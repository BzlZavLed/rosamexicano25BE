<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Producto;
use App\Models\Inventario;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function caja(Request $request)
    {
        $fechaInicio = $request->input('from_date');
        $fechaFin = $request->input('to_date');

        if (!$fechaInicio) {
            return response()->json(['message' => 'Debe proporcionar al menos from_date.'], 422);
        }

        try {
            $inicioCarbon = $this->parseDateInput($fechaInicio);
            $finCarbon = $fechaFin ? $this->parseDateInput($fechaFin) : $inicioCarbon;
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Formato de fecha inválido.'], 422);
        }

        if ($inicioCarbon->gt($finCarbon)) {
            return response()->json(['message' => 'from_date no puede ser mayor a to_date.'], 422);
        }

        $inicioString = $inicioCarbon->format('d/m/y');
        $finString = $finCarbon->format('d/m/y');
        $inicioIso = $inicioCarbon->toDateString();
        $finIso = $finCarbon->toDateString();

        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $connection->enableQueryLog();

        $dateFilter = function ($query) use ($driver, $inicioIso, $finIso, $inicioString, $finString) {
            if ($driver === 'pgsql') {
                $query->whereRaw("to_date(fecha, 'DD/MM/YY') BETWEEN ? AND ?", [$inicioIso, $finIso]);
            } elseif ($driver === 'mysql') {
                $query->whereRaw("STR_TO_DATE(fecha, '%d/%m/%y') BETWEEN ? AND ?", [$inicioIso, $finIso]);
            } else {
                $query->whereBetween('fecha', [$inicioString, $finString]);
            }
        };

        $ventasQuery = Venta::with([
            'lineas' => function ($query) use ($dateFilter) {
                $dateFilter($query);
            }
        ]);

        $dateFilter($ventasQuery);

        if ($driver === 'pgsql') {
            $ventasQuery->orderByRaw("to_date(fecha, 'DD/MM/YY')");
        } elseif ($driver === 'mysql') {
            $ventasQuery->orderByRaw("STR_TO_DATE(fecha, '%d/%m/%y')");
        } else {
            $ventasQuery->orderBy('fecha');
        }

        $ventasQuery->orderBy('idventa');

        $ventas = $ventasQuery->get();

        $mapped = $ventas->map(function (Venta $venta) {
            $subtotal = (float) $venta->subtotal;
            $amount = (float) ($venta->descuento_general ?? 0);
            $percent = (float) ($venta->descuento_general_porcentaje ?? 0);
            if ($percent <= 0 && $amount > 0 && $subtotal > 0) {
                $percent = round(($amount / $subtotal) * 100, 2);
            }
            $tarjetaCargo = (float) $venta->tarjeta_cargo;
            $lineDiscountTotal = (float) $venta->lineas->sum(function ($linea) {
                return (float) ($linea->descuento_producto ?? 0);
            });
            $overallDiscount = $amount + $lineDiscountTotal + $tarjetaCargo;

            return [
                'idventa' => $venta->idventa,
                'fecha' => $venta->fecha,
                'metodo' => $venta->metodo,
                'subtotal' => $subtotal,
                'descuento_general_percent' => $percent,
                'descuento_general_amount' => $amount,
                'tarjeta_cargo' => $tarjetaCargo,
                'descuento_lineas' => $lineDiscountTotal,
                'descuento_total' => $overallDiscount,
                'totalventa' => (float) $venta->totalventa,
                'ie' => (int) $venta->ie,
                'concepto' => $venta->concepto,
                'recibo' => (float) $venta->recibo,
                'cambio' => (float) $venta->cambio,
                'vendedor' => $venta->vendedor,
                'lineas' => $venta->lineas->map(function ($linea) {
                    return [
                        'idprod' => (int) $linea->idprod,
                        'nombre' => $linea->nombre,
                        'proveedor' => (int) $linea->proveedor,
                        'puni' => (float) $linea->puni,
                        'cant' => (int) $linea->cant,
                        'total' => (float) $linea->total,
                        'descuento_producto' => (float) ($linea->descuento_producto ?? 0),
                        'promotion' => $linea->promotion ?? 'normal',
                    ];
                }),
            ];
        });

        Log::info('Reporte de caja generado', [
            'from' => $inicioString,
            'to' => $finString,
            'total_ventas' => $ventas->count(),
        ]);

        foreach (DB::getQueryLog() as $entry) {
            Log::debug('Consulta de reporte de caja', [
                'sql' => $entry['query'],
                'bindings' => $entry['bindings'],
                'time_ms' => $entry['time'] ?? null,
            ]);
        }

        if ($request->boolean('download')) {
            $filename = sprintf('reporte_caja_%s_%s.csv', str_replace('/', '-', $inicioString), str_replace('/', '-', $finString));
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"$filename\"",
            ];

            $callback = function () use ($mapped) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'idventa',
                    'fecha',
                    'metodo',
                    'subtotal',
                    'descuento_general_percent',
                    'descuento_general_amount',
                    'descuento_lineas',
                    'descuento_total',
                    'tarjeta_cargo',
                    'totalventa',
                    'ie',
                    'concepto',
                    'recibo',
                    'cambio',
                    'vendedor',
                    'linea_idprod',
                    'linea_nombre',
                    'linea_proveedor',
                    'linea_puni',
                    'linea_cant',
                    'linea_total',
                    'linea_descuento_producto',
                    'linea_promotion',
                ]);

                foreach ($mapped as $venta) {
                    if ($venta['lineas']->isEmpty()) {
                        fputcsv($handle, [
                            $venta['idventa'],
                            $venta['fecha'],
                            $venta['metodo'],
                            $venta['subtotal'],
                            $venta['descuento_general_percent'],
                            $venta['descuento_general_amount'],
                            $venta['descuento_lineas'],
                            $venta['descuento_total'],
                            $venta['tarjeta_cargo'],
                            $venta['totalventa'],
                            $venta['ie'],
                            $venta['concepto'],
                            $venta['recibo'],
                            $venta['cambio'],
                            $venta['vendedor'],
                            null,
                            null,
                            null,
                            null,
                            null,
                            null,
                            null,
                            null,
                        ]);
                        continue;
                    }

                    foreach ($venta['lineas'] as $linea) {
                        fputcsv($handle, [
                            $venta['idventa'],
                            $venta['fecha'],
                            $venta['metodo'],
                            $venta['subtotal'],
                            $venta['descuento_general_percent'],
                            $venta['descuento_general_amount'],
                            $venta['descuento_lineas'],
                            $venta['descuento_total'],
                            $venta['tarjeta_cargo'],
                            $venta['totalventa'],
                            $venta['ie'],
                            $venta['concepto'],
                            $venta['recibo'],
                            $venta['cambio'],
                            $venta['vendedor'],
                            $linea['idprod'],
                            $linea['nombre'],
                            $linea['proveedor'],
                            $linea['puni'],
                            $linea['cant'],
                            $linea['total'],
                            $linea['descuento_producto'],
                            $linea['promotion'],
                        ]);
                    }
                }

                fclose($handle);
            };

            return response()->stream($callback, 200, $headers);
        }

        return response()->json([
            'from_date' => $inicioString,
            'to_date' => $finString,
            'ventas' => $mapped,
        ]);
    }


    public function productos(Request $request)
    {
        // Optional query params; endpoint works fine with none:
        // ?q=searchTerm&per_page=50&page=2
        $search = trim((string) $request->input('q', ''));
        $perPage = (int) $request->input('per_page', 25);
        if ($perPage <= 0 || $perPage > 200) {
            $perPage = 25; // sane default / upper bound
        }

        $query = Producto::query()
            ->with(['proveedor:ident,id,nombre']);

        if ($search !== '') {
            $normalized = Str::lower($search);
            $query->where(function ($q) use ($search, $normalized) {
                $like = "%{$normalized}%";
                $q->where('ident', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(nombre) LIKE ?', [$like])
                    ->orWhereHas('proveedor', function ($qp) use ($search, $like) {
                        $qp->whereRaw('LOWER(nombre) LIKE ?', [$like])
                            ->orWhere('ident', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Sort by product name as a reasonable default
        $query->orderBy('nombre');

        $paginator = $query
            ->paginate($perPage)
            ->appends([
                'q' => $search,
                'per_page' => $perPage,
            ]);

        // Shape the payload explicitly (stable, front-end friendly)
        $items = $paginator->getCollection()->map(function (Producto $p) {
            return [
                'id' => (int) $p->id,
                'ident' => (string) $p->ident,
                'nombre' => (string) $p->nombre,
                'precio' => isset($p->precio) ? (float) $p->precio : null,
                'proveedor' => $p->proveedor ? [
                    'ident' => (string) $p->proveedor->ident,
                    'nombre' => (string) $p->proveedor->nombre,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $items,
            'pagination' => [
                'total' => $paginator->total(),
                'count' => $paginator->count(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
        ]);
    }

    public function inventario(Request $request)
    {
        $search = trim((string) $request->input('q', ''));
        $perPage = (int) $request->input('per_page', 25);
        if ($perPage <= 0 || $perPage > 200) {
            $perPage = 25;
        }

        $sort = strtolower((string) $request->input('sort', 'producto'));
        $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = Inventario::query()
            ->select('inventario.*')
            ->leftJoin('producto as p', 'p.ident', '=', 'inventario.ident')
            ->leftJoin('proveedores as pr', 'pr.ident', '=', 'p.proveedorid')
            ->with([
                'producto' => fn ($q) => $q->select('id', 'ident', 'nombre', 'precio', 'proveedorid'),
                'producto.proveedor' => fn ($q) => $q->select('id', 'ident', 'nombre'),
            ]);

        if ($search !== '') {
            $normalized = Str::lower($search);
            $query->where(function ($q) use ($search, $normalized) {
                $like = "%{$normalized}%";
                $q->where('inventario.ident', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(p.nombre) LIKE ?', [$like])
                    ->orWhere('pr.ident', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(pr.nombre) LIKE ?', [$like]);
            });
        }

        switch ($sort) {
            case 'existencia':
                $query->orderBy('inventario.existencia', $direction);
                break;
            case 'proveedor':
                $query->orderByRaw('LOWER(pr.nombre) ' . strtoupper($direction));
                break;
            default:
                $query->orderByRaw('LOWER(p.nombre) ' . strtoupper($direction));
                break;
        }

        $paginator = $query->paginate($perPage)->appends([
            'q' => $search,
            'per_page' => $perPage,
            'sort' => $sort,
            'direction' => $direction,
        ]);

        $items = $paginator->getCollection()->map(function (Inventario $inv) {
            $producto = $inv->producto;
            $proveedor = $producto?->proveedor;
            $precio = $producto?->precio;
            $existencia = (int) ($inv->existencia ?? 0);
            $costoInventario = $precio !== null ? round((float) $precio * $existencia, 2) : null;

            return [
                'inventario_id' => (int) $inv->id,
                'producto_ident' => (string) ($producto->ident ?? $inv->ident),
                'producto_nombre' => (string) ($producto->nombre ?? ''),
                'precio' => $precio !== null ? (float) $precio : null,
                'existencia' => $existencia,
                'costo_inventario' => $costoInventario,
                'proveedor' => $proveedor ? [
                    'ident' => (string) $proveedor->ident,
                    'nombre' => (string) $proveedor->nombre,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $items,
            'pagination' => [
                'total' => $paginator->total(),
                'count' => $paginator->count(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
        ]);
    }
    private function parseDateInput(string $value): Carbon
    {
        $value = trim($value);

        try {
            return Carbon::createFromFormat('d/m/y', $value, config('app.timezone'));
        } catch (\Throwable $e) {
            // fall-through
        }

        return Carbon::parse($value);
    }
}
