<?php

namespace App\Http\Controllers;

use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaCancelacion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminSalesToolController extends Controller
{
    protected function ensureAdmin(Request $request, string $password): Usuario
    {
        $user = $request->user();
        if (!($user instanceof Usuario)) {
            abort(403, 'Solo administradores.');
        }
        if (!Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'admin_password' => 'Contraseña inválida.',
            ]);
        }
        return $user;
    }

    public function list(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'admin_password' => ['required', 'string', 'min:4'],
        ]);

        $this->ensureAdmin($request, $data['admin_password']);

        $date = Carbon::parse($data['date'])->toDateString();
        $ventas = Venta::with(['lineas' => fn ($q) => $q->orderBy('id')])
            ->whereDate('fecha', $date)
            ->orderBy('hora')
            ->orderBy('id')
            ->get();

        $payload = $ventas->map(function (Venta $venta) {
            return [
                'id' => $venta->id,
                'idventa' => $venta->idventa,
                'fecha' => $venta->fecha?->toDateString(),
                'hora' => $venta->hora,
                'metodo' => $venta->metodo,
                'vendedor' => $venta->vendedor,
                'total' => (float) ($venta->totalventa ?? 0),
                'total_recibido' => (float) ($venta->total_recibido ?? 0),
                'cambio' => (float) ($venta->cambio ?? 0),
                'line_items' => $venta->lineas->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'producto_ident' => $line->producto_id,
                        'producto_nombre' => $line->nombre,
                        'cantidad' => (float) ($line->quantity ?? 0),
                        'free_quantity' => (float) ($line->free_quantity ?? 0),
                        'public_total' => (float) ($line->public_total ?? 0),
                        'venta_total' => (float) ($line->venta_total ?? 0),
                        'unit_price' => (float) ($line->unit_price ?? 0),
                    ];
                }),
            ];
        });

        return response()->json([
            'date' => $date,
            'sales_count' => $payload->count(),
            'sales' => $payload,
        ]);
    }

    public function cancel(Request $request, Venta $venta)
    {
        $data = $request->validate([
            'admin_password' => ['required', 'string', 'min:4'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $admin = $this->ensureAdmin($request, $data['admin_password']);

        $alreadyLogged = VentaCancelacion::where('venta_id', $venta->id)->exists();
        if ($alreadyLogged) {
            return response()->json(['message' => 'Esta venta ya fue cancelada anteriormente.'], 409);
        }

        return DB::transaction(function () use ($venta, $data, $admin) {
            $lineas = $venta->lineas()->get();

            $productIds = $lineas->pluck('producto_id')
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values();

            $productos = $productIds->isEmpty()
                ? collect()
                : Producto::whereIn('ident', $productIds->all())->get()->keyBy(fn ($p) => (string) $p->ident);

            foreach ($lineas as $linea) {
                if (!$linea->producto_id) {
                    continue;
                }
                $inventario = Inventario::where('ident', $linea->producto_id)->lockForUpdate()->first();
                if (!$inventario) {
                    continue;
                }
                $qty = (int) ($linea->quantity ?? 0);
                $inventario->existencia = max(0, (int) $inventario->existencia) + $qty;

                $precioBase = $linea->unit_price;
                if ($precioBase === null && $productos->has((string) $linea->producto_id)) {
                    $precioBase = $productos->get((string) $linea->producto_id)->precio;
                }
                if ($precioBase !== null) {
                    $inventario->importe = round($inventario->existencia * (float) $precioBase, 2);
                }
                $inventario->save();
            }

            VentaCancelacion::create([
                'venta_id' => $venta->id,
                'idventa' => $venta->idventa,
                'admin_id' => $admin->id,
                'reason' => $data['reason'] ?? null,
                'venta_payload' => $venta->toArray(),
                'lineas_payload' => $lineas->toArray(),
            ]);

            $venta->lineas()->delete();
            $venta->delete();

            return response()->json(['message' => 'Venta cancelada y stock revertido.']);
        });
    }
}
