<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashierFindRequest;
use App\Http\Requests\CheckoutLegacyRequest;
use App\Http\Requests\CajaLegacyRequest;
use App\Http\Requests\ExpenseLegacyRequest;
use App\Models\EstadoCaja;
use App\Models\VentaOld;
use App\Models\Venta;
use App\Models\VentaDesg;
use App\Models\Producto;
use App\Models\Inventario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CashierLegacyController extends Controller
{
    /** Return today as legacy d/m/y (two-digit year) */
    private function todayStr(): string
    {
        // ex: 22/10/25
        return date('d/m/y');
    }

    /* ===================== CAJA ===================== */

    // GET /api/caja/status
    public function status(Request $request)
    {
        $fecha = $this->todayStr();
        $row = EstadoCaja::where('fecha', $fecha)->orderByDesc('id')->first();

        return response()->json([
            'open' => $row && (int) $row->estado === 1,
            'caja' => $row,
        ]);
    }

    // POST /api/caja/open  { saldo: number, fecha?: "d/m/y" }
    public function open(CajaLegacyRequest $request)
    {
        $fecha = $request->input('fecha') ?: $this->todayStr();

        $existsOpen = EstadoCaja::where('fecha', $fecha)->where('estado', 1)->exists();
        if ($existsOpen) {
            return response()->json(['message' => 'Ya existe caja abierta para esa fecha'], 409);
        }

        $row = EstadoCaja::create([
            'fecha'        => $fecha,              // varchar(10) legacy
            'estado'       => 1,                   // 1 = abierta
            'saldoinicial'        => (float) $request->input('saldo', 0),   // saldo inicial (cash)
            'saldofinal'        => 0,   // saldo inicial (cash) --- IGNORE ---
            'saldosistema' => 0,                   // se calculará al cerrar
            'usuario'      => $request->user()->nombre ?? $request->user()->email ?? 'admin',
        ]);

        return response()->json($row, 201);
    }

    // POST /api/caja/close  { saldosistema?: number, fecha?: "d/m/y" }
    public function close(CajaLegacyRequest $request)
    {
        $fecha = $request->input('fecha') ?: $this->todayStr();

        // caja abierta de ese día
        $row = EstadoCaja::where('fecha', $fecha)->where('estado', 1)->orderByDesc('id')->first();
        if (!$row) {
            return response()->json(['message' => 'No hay caja abierta para la fecha indicada'], 409);
        }

        // total en efectivo del día (según ventas.totalventa y ventas.metodo='cash')
        $cashTotal = (float) DB::table('ventas')
            ->where('fecha', $fecha)
            ->where('metodo', 'cash')
            ->sum('totalventa');

        $row->saldosistema = $row->saldo + $cashTotal; // saldo inicial + ventas en cash
        // si el cliente manda un valor manual en el cierre, lo respetamos
        if ($request->filled('saldosistema')) {
            $row->saldosistema = (float) $request->input('saldosistema');
        }
        $row->estado = 0; // cerrada
        $row->save();

        return response()->json([
            'caja'       => $row,
            'cash_today' => $cashTotal,
        ]);
    }

    /* ================== PRODUCT FIND ================= */

    // GET /api/cashier/find-product?barcode=123456 or ?search=papel
    public function findProduct(CashierFindRequest $request)
    {
        $q = Producto::with(['proveedor', 'inventario']);

        if ($barcode = $request->input('barcode')) {
            $q->where('ident', (int)$barcode);
        }
        if ($s = $request->input('search')) {
            $like = '%' . $s . '%';
            $q->where(function ($qq) use ($like) {
                $qq->where('nombre', 'ILIKE', $like)
                   ->orWhere('descripcion', 'ILIKE', $like);
            });
        }
        if ($pid = $request->input('proveedor_id')) {
            $q->where('proveedorid', (int)$pid);
        }

        $per = (int)$request->input('per_page', 25);
        $data = $q->orderBy('nombre')->limit($per)->get();

        return response()->json(['data' => $data]);
    }

    /* ===================== CHECKOUT ================== */

    // POST /api/cashier/checkout
    public function checkout(CheckoutLegacyRequest $request)
    {
        // caja debe estar abierta para hoy (d/m/y)
        $fechaHoy = $this->todayStr();
        $caja = EstadoCaja::where('fecha', $fechaHoy)->where('estado', 1)->first();
        if (!$caja) {
            return response()->json(['message' => 'Debes abrir caja antes de vender'], 409);
        }

        $items = $request->input('items', []);
        $discountPercent = (float)($request->input('discount_percent') ?? 0);
        $method  = $request->input('payment.method');   // 'cash' | 'debit' | 'credit'
        $received= $request->input('payment.received'); // efectivo entregado (solo cash)

        try {
            $payload = DB::transaction(function () use ($items, $discountPercent, $method, $received, $fechaHoy, $request) {

                $lines = [];
                $subtotal = 0.0;

                // 1) validar stock + preparar renglones
                foreach ($items as $it) {
                    $ident = (int)$it['ident'];
                    $qty   = (int)$it['qty'];

                    $producto = Producto::where('ident', $ident)->first();
                    if (!$producto) {
                        throw new \RuntimeException("Producto {$ident} no existe");
                    }

                    $inv = Inventario::where('ident', $ident)->lockForUpdate()->first();
                    $exist = (int)($inv?->existencia ?? 0);
                    if ($qty > $exist) {
                        throw new \RuntimeException("Inventario insuficiente para {$ident}");
                    }

                    $unit = (float)$producto->precio;
                    $importe = $unit * $qty;
                    $subtotal += $importe;

                    $lines[] = compact('producto','inv','qty','unit','importe');
                }

                // 2) descuento y recargo
                $discountAmount = $discountPercent > 0 ? round($subtotal * ($discountPercent / 100), 2) : 0.0;
                $afterDiscount  = max(0, $subtotal - $discountAmount);

                $surchargePercent = $method === 'credit' ? 4.5 : 0.0;
                $surchargeAmount  = $surchargePercent > 0 ? round($afterDiscount * ($surchargePercent / 100), 2) : 0.0;

                $total = round($afterDiscount + $surchargeAmount, 2);

                // 3) ticket consecutivo (idventa)
                $nextIdVenta = (int) DB::table('ventas')->max('idventa') + 1;

                // 4) efectivo / cambio
                $vendedor = $request->user()->nombre ?? $request->user()->email ?? 'admin';
                $recibo   = null;
                $cambio   = null;

                if ($method === 'cash') {
                    $recib = (float)$received;
                    if ($recib < $total) {
                        throw new \RuntimeException('Efectivo recibido insuficiente');
                    }
                    $recibo = $recib;
                    $cambio = round($recib - $total, 2);
                } else {
                    // tarjeta o débito: cobro exacto
                    $recibo = $total;
                    $cambio = 0.0;
                }
                $ie = $request->input('ie', 1); // legacy

                // 5) encabezado de venta (tabla legacy "ventas")
                $venta = Venta::create([
                    'idventa'    => $nextIdVenta,
                    'totalventa' => $total,
                    'metodo'     => $method,       // 'efectivo' | 'debit' | 'credit'
                    'recibo'     => $recibo,
                    'cambio'     => $cambio,
                    'vendedor'   => $vendedor,
                    'fecha'      => $fechaHoy,     // "d/m/y" (varchar(10))
                    'ie'         => $ie,
                    'concepto'   => 'VENTA MOSTRADOR',
                ]);

                // 6) renglones (tabla legacy "ventadesg") + actualizar inventario
                $hora = date('H:i:s'); // ok para time(6)
                foreach ($lines as $ln) {
                    $lineProportion = $subtotal > 0 ? ($ln['importe'] / $subtotal) : 0;
                    $lineDiscount   = round($discountAmount * $lineProportion, 2);

                    VentaDesg::create([
                        'idventa'   => $nextIdVenta,
                        'fecha'     => $fechaHoy,
                        'idprod'    => (int)$ln['producto']->ident, // barcode
                        'nombre'    => (string)$ln['producto']->nombre,
                        'proveedor' => (int)$ln['producto']->proveedorid,
                        'puni'      => (float)$ln['unit'],
                        'cant'      => (int)$ln['qty'],
                        'total'     => (float)$ln['importe'],
                        'totdesc'   => (float)$lineDiscount,
                        'hora'      => $hora,
                    ]);

                    // disminuir inventario
                    $inv = $ln['inv'];
                    if (!$inv) {
                        $inv = new Inventario();
                        $inv->ident = (int)$ln['producto']->ident;
                        $inv->existencia = 0;
                        $inv->importe = 0;
                        $inv->provee = (int)$ln['producto']->proveedorid;
                        $inv->precio_individual = (float)$ln['unit'];
                    }
                    $inv->existencia = max(0, (int)$inv->existencia - (int)$ln['qty']);
                    $inv->importe    = max(0, (float)$inv->importe - ((int)$ln['qty'] * (float)$ln['unit']));
                    $inv->save();
                }

                $venta->load('lineas');

                return [
                    'venta'             => $venta,
                    'subtotal'          => $subtotal,
                    'discount_percent'  => $discountPercent,
                    'discount_amount'   => $discountAmount,
                    'surcharge_percent' => $surchargePercent,
                    'surcharge_amount'  => $surchargeAmount,
                    'total'             => $total,
                ];
            });

            return response()->json(['data' => $payload], 201);

        } catch (Throwable $e) {
            report($e);
            $msg = $e->getMessage() ?: 'No se pudo finalizar la venta';
            $code = str_contains(strtolower($msg), 'inventario') ? 422 : 500;
            return response()->json(['message' => $msg], $code);
        }
    }

    // POST /api/cashier/expenses
    public function registerExpense(ExpenseLegacyRequest $request)
    {
        $fecha = $request->input('fecha') ?: $this->todayStr();

        $caja = EstadoCaja::where('fecha', $fecha)->where('estado', 1)->first();
        if (!$caja) {
            return response()->json(['message' => 'Debes abrir caja antes de registrar gastos'], 409);
        }

        $total = round((float) $request->input('totalventa'), 2);
        if ($total <= 0) {
            return response()->json(['message' => 'El total del gasto debe ser mayor a cero'], 422);
        }

        try {
            $venta = DB::transaction(function () use ($request, $fecha, $total) {
                $nextIdVenta = (int) DB::table('ventas')->max('idventa') + 1;

                $method    = $request->input('method') ?: 'efectivo';
                $concepto  = $request->input('concepto');
                $vendedor  = $request->input('vendedor') ?: ($request->user()->nombre ?? $request->user()->email ?? 'admin');

                return Venta::create([
                    'idventa'    => $nextIdVenta,
                    'totalventa' => $total,
                    'metodo'     => $method,
                    'recibo'     => 0,
                    'cambio'     => 0,
                    'vendedor'   => $vendedor,
                    'fecha'      => $fecha,
                    'ie'         => 0,
                    'concepto'   => $concepto,
                ]);
            });

            return response()->json(['data' => $venta], 201);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'No se pudo registrar el gasto'], 500);
        }
    }
}
