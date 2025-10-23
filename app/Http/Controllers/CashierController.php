<?php

namespace App\Http\Controllers;

use App\Http\Requests\CajaOpenRequest;
use App\Http\Requests\CajaCloseRequest;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\ProductoResource;
use App\Models\EstadoCaja;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaDesg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CashierController extends Controller
{
    /** Quick guard: allow only admins (not providers) */
    private function ensureAdmin(Request $request)
    {
        if ($request->user() instanceof \App\Models\Proveedor) {
            abort(403, 'Solo administrador');
        }
    }

    private function todayStr(): string
    {
        return date('d/m/y');
    } // 22/10/25

    public function status()
    {
        $fecha = $this->todayStr();
        $row = EstadoCaja::where('fecha', $fecha)->orderByDesc('id')->first();

        return response()->json([
            'open' => $row && (int) $row->estado === 1,
            'caja' => $row,
        ]);
    }

    public function open(CajaOpenRequest $request)
    {
        Log::info('OPEN CAJA payload', $request->all());

        // d/m/y from request or today
        $fecha = $request->input('fecha') ?: $this->todayStr();

        // Only one open per day
        $already = EstadoCaja::where('fecha', $fecha)->where('estado', 1)->exists();
        if ($already) {
            return response()->json(['message' => 'La caja ya está abierta'], 422);
        }
        $opening = $request->has('saldoinicial')
            ? (float) $request->input('saldoinicial')
            : (float) $request->input('saldo');  // fallback
        $row = EstadoCaja::create([
            'fecha' => $fecha,                            // varchar(10)
            'estado' => 1,                                 // 1 = abierta
            'saldoinicial' =>$opening,   // <-- IMPORTANT: map saldo -> saldoinicial
            'saldofinal' => 0.0,                               // not known yet
            'saldosistema' => 0.0,                               // computed at close
            'usuario' => $request->user()->nombre
                ?? ($request->user()->email ?? 'admin'),
        ]);

        return response()->json($row, 201);
    }

    public function close(CajaCloseRequest $request)
    {
        $fecha = $request->input('fecha') ?: $this->todayStr();

        $row = EstadoCaja::where('fecha', $fecha)->where('estado', 1)->orderByDesc('id')->first();
        if (!$row) {
            return response()->json(['message' => 'No hay caja abierta para la fecha indicada'], 409);
        }

        // Sum only CASH sales for that date from legacy `ventas` table
        $cashTotal = (float) DB::table('ventas')
            ->where('fecha', $fecha)
            ->where('metodo', 'cash')
            ->sum('totalventa');

        // System expected cash at close
        $sistema = (float) $row->saldoinicial + $cashTotal;

        // Allow optional overrides from request
        if ($request->filled('saldosistema')) {
            $sistema = (float) $request->input('saldosistema');
        }
        if ($request->filled('saldofinal')) {
            $row->saldofinal = (float) $request->input('saldofinal'); // counted cash
        }

        $row->saldosistema = $sistema;
        $row->estado = 0; // cerrada
        $row->save();

        // You can also return variance if saldofinal was provided:
        $variance = $row->saldofinal !== null ? round($row->saldofinal - $row->saldosistema, 2) : null;

        return response()->json([
            'caja' => $row,
            'cash_today' => $cashTotal,
            'variance' => $variance,
        ]);
    }

    /** GET /api/cashier/find-product?q=...  (barcode or name) */
    public function findProduct(Request $request)
    {
        $this->ensureAdmin($request);

        $q = trim((string) $request->query('q', ''));
        if ($q === '')
            return response()->json([]);

        $query = Producto::query()->with('proveedor');

        if (is_numeric($q)) {
            $query->where('ident', (int) $q); // barcode
        } else {
            $like = '%' . Str::lower($q) . '%';
            $query->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(nombre) LIKE ?', [$like])
                   ->orWhereRaw('LOWER(descripcion) LIKE ?', [$like]);
            });
        }

        return ProductoResource::collection($query->limit(20)->get());
    }

    /** POST /api/cashier/checkout  (creates venta + ventadesg + updates inventory) */
    public function checkout(CheckoutRequest $request)
    {
        $this->ensureAdmin($request);

        $now = Carbon::now();
        $fecha = $now->format('Y-m-d');
        $hora = $now->format('H:i:s');

        $payload = $request->validated();

        return DB::transaction(function () use ($payload, $fecha, $hora) {
            // Calculate total strictly on server
            $total = 0;
            foreach ($payload['lineas'] as $l) {
                $line = ($l['pUni'] * $l['cant']) - ($l['totdesc'] ?? 0);
                $total += $line;
            }

            // Create venta header
            $venta = Venta::create([
                'idventa' => $payload['idventa'],
                'totalventa' => $total,
                'metodo' => $payload['metodo'],
                'recibo' => $payload['recibo'],
                'cambio' => $payload['cambio'],
                'vendedor' => $payload['vendedor'],
                'fecha' => $fecha,
                'ie' => 0,
                'concepto' => $payload['concepto'] ?? '',
            ]);

            // Create lines & update inventory
            foreach ($payload['lineas'] as $l) {
                $prod = \App\Models\Producto::findOrFail($l['idProd']);

                VentaDesg::create([
                    'idventa' => $payload['idventa'],
                    'fecha' => $fecha,
                    'idProd' => $prod->id,
                    'nombre' => $l['nombre'],
                    'proveedor' => $l['proveedor'],
                    'pUni' => $l['pUni'],
                    'cant' => $l['cant'],
                    'total' => $l['pUni'] * $l['cant'],
                    'totdesc' => $l['totdesc'] ?? 0,
                    'hora' => $hora,
                ]);

                // Inventory (by barcode ident)
                $inv = \App\Models\Inventario::where('ident', $prod->ident)->lockForUpdate()->first();
                if (!$inv) {
                    $inv = new \App\Models\Inventario([
                        'ident' => $prod->ident,
                        'existencia' => 0,
                        'importe' => 0,
                        'provee' => (int) $prod->proveedorid,
                    ]);
                }

                if ($inv->existencia < $l['cant']) {
                    throw new \RuntimeException("Stock insuficiente para producto {$prod->nombre}");
                }

                $inv->existencia -= (int) $l['cant'];
                $inv->importe = $inv->existencia * (float) $prod->precio; // money equivalent
                $inv->provee = (int) $prod->proveedorid;
                $inv->save();
            }

            return $venta->load('lineas');
        });
    }
}
