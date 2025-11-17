<?php

namespace App\Http\Controllers;

use App\Models\EstadoCaja;
use App\Models\Venta;
use App\Models\VentaDesg;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WidgetsController extends Controller
{
    public function cashierSummary(Request $request)
    {
        $fecha = $request->input('fecha');
        if ($fecha) {
            try {
                $fechaCarbon = Carbon::parse($fecha);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Formato de fecha inválido.'], 422);
            }
        } else {
            $fechaCarbon = Carbon::today();
        }

        $fechaQuery = $fechaCarbon->toDateString();
        $fechaDisplay = $fechaCarbon->format('d/m/y');

        $ventasDelDia = Venta::whereDate('fecha', $fechaQuery);

        $entradas = (clone $ventasDelDia)->sum('totalventa');
        $salidas = (float) \App\Models\Egreso::whereDate('fecha', $fechaQuery)->sum('monto');
        $salidasCount = (int) \App\Models\Egreso::whereDate('fecha', $fechaQuery)->count();

        $productosVendidos = VentaDesg::where('fecha', $fechaQuery)->sum('quantity');

        $metodos = (clone $ventasDelDia)
            ->selectRaw('metodo, SUM(totalventa) as total, COUNT(*) as transacciones')
            ->groupBy('metodo')
            ->get()
            ->map(function ($row) {
                return [
                    'metodo' => $row->metodo,
                    'total' => (float) $row->total,
                    'transacciones' => (int) $row->transacciones,
                ];
            });

        return response()->json([
            'fecha' => $fechaDisplay,
            'entradas_total' => (float) $entradas,
            'salidas_total' => (float) $salidas,
            'transacciones' => [
                'entradas' => (clone $ventasDelDia)->count(),
                'salidas' => $salidasCount,
            ],
            'productos_vendidos' => (int) $productosVendidos,
            'metodos' => $metodos,
        ]);
    }

    public function topProducts(Request $request)
    {
        $today = Carbon::today();
        $fromDate = $today->copy()->subDays(9); // include today + 9 previous = 10 days

        $from = $fromDate->toDateString();
        $to = $today->toDateString();

        $top = VentaDesg::selectRaw('producto_id, nombre, proveedor_id, SUM(quantity) as total_cantidad')
            ->whereBetween('fecha', [$from, $to])
            ->groupBy('producto_id', 'nombre', 'proveedor_id')
            ->orderByDesc('total_cantidad')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                $proveedorNombre = null;
                if ($item->proveedor_id) {
                    $proveedorNombre = optional(
                        Proveedor::where('ident', (int) $item->proveedor_id)->first()
                    )->nombre;
                }

                return [
                    'producto_id' => (int) $item->producto_id,
                    'producto_nombre' => $item->nombre,
                    'proveedor_id' => (int) $item->proveedor_id,
                    'proveedor_nombre' => $proveedorNombre,
                    'cantidad_vendida' => (int) $item->total_cantidad,
                ];
            });

        return response()->json([
            'desde' => $fromDate->format('d/m/y'),
            'hasta' => $today->format('d/m/y'),
            'productos' => $top,
        ]);
    }
}
