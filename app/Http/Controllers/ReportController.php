<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Producto;
use App\Models\Inventario;
use App\Models\Entrada;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    protected function currentProvider(Request $request): ?Proveedor
    {
        $user = $request->user();
        return $user instanceof Proveedor ? $user : null;
    }

    public function caja(Request $request)
    {
        $fechaInicio = $request->input('from_date');
        $fechaFin = $request->input('to_date');
        $provider = $this->currentProvider($request);
        $provider = $this->currentProvider($request);

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
            'lineas' => function ($query) use ($dateFilter, $provider) {
                $dateFilter($query);
                if ($provider) {
                    $query->where('proveedor', $provider->ident);
                }
            }
        ]);

        $dateFilter($ventasQuery);

        if ($provider) {
            $ventasQuery->whereHas('lineas', function ($query) use ($dateFilter, $provider) {
                $dateFilter($query);
                $query->where('proveedor', $provider->ident);
            });
        }

        if ($driver === 'pgsql') {
            $ventasQuery->orderByRaw("to_date(fecha, 'DD/MM/YY')");
        } elseif ($driver === 'mysql') {
            $ventasQuery->orderByRaw("STR_TO_DATE(fecha, '%d/%m/%y')");
        } else {
            $ventasQuery->orderBy('fecha');
        }

        $ventasQuery->orderBy('idventa');

        $ventas = $ventasQuery->get();

        if ($provider) {
            $ventas = $ventas->filter(function (Venta $venta) use ($provider) {
                $filtered = $venta->lineas->where('proveedor', $provider->ident)->values();
                $venta->setRelation('lineas', $filtered);
                return $filtered->isNotEmpty();
            })->values();
        }

        $mapped = $ventas->map(function (Venta $venta) use ($provider) {
            $lineas = $venta->lineas;
            $lineDiscountTotal = (float) $lineas->sum(function ($linea) {
                return (float) ($linea->descuento_producto ?? 0);
            });
            $lineCardCharges = (float) $lineas->sum(function ($linea) {
                return (float) ($linea->cargo_tarjeta_proveedor ?? 0);
            });

            if ($provider) {
                $gross = (float) $lineas->sum(function ($linea) {
                    $unit = (float) ($linea->puni ?? 0);
                    $qty = (int) ($linea->cant ?? 0);
                    return round($unit * $qty, 2);
                });
                $net = (float) $lineas->sum(function ($linea) {
                    return (float) ($linea->total ?? 0);
                });
                $overallDiscount = round($lineDiscountTotal + $lineCardCharges, 2);
                $tarjetaCargo = round($lineCardCharges, 2);
                $amount = 0.0;
                $percent = $gross > 0 ? round(($overallDiscount / $gross) * 100, 2) : 0.0;
                $subtotal = round($gross, 2);
                $totalventa = round($net, 2);
            } else {
                $subtotal = (float) $venta->subtotal;
                $amount = (float) ($venta->descuento_general ?? 0);
                $percent = (float) ($venta->descuento_general_porcentaje ?? 0);
                if ($percent <= 0 && $amount > 0 && $subtotal > 0) {
                    $percent = round(($amount / $subtotal) * 100, 2);
                }
                $tarjetaCargo = (float) $venta->tarjeta_cargo;
                $overallDiscount = $amount + $lineDiscountTotal + $tarjetaCargo;
                $totalventa = (float) $venta->totalventa;
            }

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
                'totalventa' => $totalventa,
                'ie' => (int) $venta->ie,
                'concepto' => $venta->concepto,
                'recibo' => (float) $venta->recibo,
                'cambio' => (float) $venta->cambio,
                'vendedor' => $venta->vendedor,
                'lineas' => $lineas->map(function ($linea) {
                    return [
                        'idprod' => (int) $linea->idprod,
                        'nombre' => $linea->nombre,
                        'proveedor' => (int) $linea->proveedor,
                        'puni' => (float) $linea->puni,
                        'cant' => (int) $linea->cant,
                        'total' => (float) $linea->total,
                        'descuento_producto' => (float) ($linea->descuento_producto ?? 0),
                        'cargo_tarjeta_proveedor' => (float) ($linea->cargo_tarjeta_proveedor ?? 0),
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
        $provider = $this->currentProvider($request);

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

        if ($provider) {
            $query->where('proveedorid', $provider->ident);
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
        $provider = $this->currentProvider($request);

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

        if ($provider) {
            $query->where('pr.ident', '=', $provider->ident);
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

    public function cajaPorProveedor(Request $request)
    {
        $fechaInicio = $request->input('from_date');
        $fechaFin = $request->input('to_date');
        $provider = $this->currentProvider($request);

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

        $applyDateFilter = function ($builder) use ($driver, $inicioString, $finString) {
            $builder->whereBetween('vd.fecha', [$inicioString, $finString]);
        };

        $rows = DB::table('ventadesg as vd')
            ->select([
                'vd.id',
                'vd.idventa',
                'vd.fecha as linea_fecha',
                'vd.idprod',
                'vd.nombre as producto_nombre',
                'vd.proveedor as proveedor_ident',
                'vd.puni',
                'vd.cant',
                'vd.total as linea_total',
                'vd.descuento_producto',
                'vd.cargo_tarjeta_proveedor',
                'vd.promotion',
                'v.id as venta_id',
                'v.fecha as venta_fecha',
                'v.subtotal as venta_subtotal',
                'v.descuento_general as venta_descuento_general',
                'v.tarjeta_cargo as venta_tarjeta_cargo',
                'v.metodo as venta_metodo',
                'v.vendedor as venta_vendedor',
                'v.totalventa as venta_total',
                'p.id as proveedor_id',
                'p.ident as proveedor_ident_real',
                'p.nombre as proveedor_nombre',
            ])
            ->leftJoin('ventas as v', 'v.idventa', '=', 'vd.idventa')
            ->leftJoin('proveedores as p', 'p.ident', '=', 'vd.proveedor');

        if ($provider) {
            $rows->where('vd.proveedor', '=', $provider->ident);
        }

        $applyDateFilter($rows);

        $rows->orderBy('vd.fecha')->orderBy('vd.id');

        $collection = collect($rows->get());

        $generalDiscountTotal = $collection
            ->groupBy(function ($row) {
                return $row->idventa !== null ? (string) $row->idventa : 'venta_null_' . spl_object_id($row);
            })
            ->sum(function ($rows) {
                $first = $rows->first();
                return round((float) ($first->venta_descuento_general ?? 0), 2);
            });

        $grouped = $collection->groupBy(function ($row) {
            return $row->proveedor_ident !== null ? (string) $row->proveedor_ident : 'sin_proveedor';
        });

        $controller = $this;

        $providers = $grouped->map(function ($group) use ($controller) {
            $first = $group->first();
            $proveedorId = $first->proveedor_id ? (int) $first->proveedor_id : null;
            $proveedorIdent = $first->proveedor_ident !== null ? (string) $first->proveedor_ident : null;
            $proveedorNombre = $first->proveedor_nombre ?: ($proveedorIdent !== null ? "Proveedor {$proveedorIdent}" : 'Sin proveedor');

            $details = $group->map(function ($row) use ($controller) {
                $lineGross = round((float) ($row->linea_total ?? 0), 2);
                $ventaSubtotal = (float) ($row->venta_subtotal ?? 0);
                $ratio = ($ventaSubtotal > 0 && $lineGross > 0) ? $lineGross / $ventaSubtotal : 0.0;

                $itemDiscount = round((float) ($row->descuento_producto ?? 0), 2);

                $lineProviderCharge = (float) ($row->cargo_tarjeta_proveedor ?? 0);
                if ($lineProviderCharge === 0.0) {
                    $providerChargeTotal = (float) ($row->venta_tarjeta_cargo ?? 0);
                    if ($providerChargeTotal !== 0.0 && $ratio > 0) {
                        $lineProviderCharge = round($providerChargeTotal * $ratio, 2);
                    }
                } else {
                    $lineProviderCharge = round($lineProviderCharge, 2);
                }

                $lineDiscountTotal = round($itemDiscount + $lineProviderCharge, 2);
                $lineNet = round($lineGross - $lineDiscountTotal, 2);

                $fechaRaw = $row->venta_fecha ?? $row->linea_fecha;
                $fechaDisplay = $fechaRaw;
                $fechaIso = null;

                try {
                    if ($fechaRaw !== null && $fechaRaw !== '') {
                        $parsed = $controller->parseDateInput($fechaRaw);
                        $fechaDisplay = $fechaRaw;
                        $fechaIso = $parsed->toDateString();
                    }
                } catch (\Throwable $e) {
                    // keep raw values
                }

                return [
                    'ventadesg_id' => (int) $row->id,
                    'idventa' => (int) $row->idventa,
                    'venta_id' => $row->venta_id ? (int) $row->venta_id : null,
                    'fecha' => $fechaDisplay,
                    'fecha_raw' => $fechaRaw,
                    'fecha_iso' => $fechaIso,
                    'producto_ident' => (string) $row->idprod,
                    'producto_nombre' => $row->producto_nombre,
                    'cantidad' => (int) ($row->cant ?? 0),
                    'precio_unitario' => round((float) ($row->puni ?? 0), 2),
                    'total' => $lineGross,
                    'descuento_producto' => $itemDiscount,
                    'cargo_tarjeta' => $lineProviderCharge,
                    'descuento_total' => $lineDiscountTotal,
                    'ganancia' => $lineNet,
                    'metodo' => $row->venta_metodo,
                    'vendedor' => $row->venta_vendedor,
                    'venta_total' => round((float) ($row->venta_total ?? 0), 2),
                    'promotion' => $row->promotion,
                ];
            })->values();

            $ventasBrutas = round($details->sum(function ($item) {
                return $item['total'];
            }), 2);
            $descuentos = round($details->sum(function ($item) {
                return $item['descuento_total'];
            }), 2);
            $cargoTarjeta = round($details->sum(function ($item) {
                return $item['cargo_tarjeta'];
            }), 2);
            $ganancia = round($details->sum(function ($item) {
                return $item['ganancia'];
            }), 2);

            return [
                'proveedor_id' => $proveedorId,
                'proveedor_ident' => $proveedorIdent,
                'proveedor_nombre' => $proveedorNombre,
                'ventas_brutas' => $ventasBrutas,
                'descuentos' => $descuentos,
                'cargos_tarjeta' => $cargoTarjeta,
                'ganancia_total' => $ganancia,
                'items' => $details,
            ];
        })->values();

        $totalLineaDescuentos = round($providers->sum(fn ($row) => $row['descuentos']), 2);

        $totales = [
            'ventas_brutas' => round($providers->sum(fn ($row) => $row['ventas_brutas']), 2),
            'descuentos' => $totalLineaDescuentos,
            'cargos_tarjeta' => round($providers->sum(fn ($row) => $row['cargos_tarjeta']), 2),
            'descuento_general' => $generalDiscountTotal,
            'ganancias' => round($providers->sum(fn ($row) => $row['ganancia_total']), 2),
        ];

        Log::info('Reporte de caja por proveedor generado', [
            'from' => $inicioString,
            'to' => $finString,
            'total_proveedores' => $providers->count(),
        ]);

        foreach (DB::getQueryLog() as $entry) {
            Log::debug('Consulta de reporte caja por proveedor', [
                'sql' => $entry['query'],
                'bindings' => $entry['bindings'],
                'time_ms' => $entry['time'] ?? null,
            ]);
        }

        if ($request->boolean('download')) {
            $filename = sprintf(
                'reporte_caja_proveedores_%s_%s.csv',
                Str::of($inicioString)->replace('/', '-'),
                Str::of($finString)->replace('/', '-')
            );

            return response()->streamDownload(function () use ($providers, $totales, $inicioString, $finString, $generalDiscountTotal) {
                $handle = fopen('php://output', 'w');

                fputcsv($handle, ['Reporte caja por proveedor']);
                fputcsv($handle, ['Desde', $inicioString, 'Hasta', $finString]);
                fputcsv($handle, []);
                fputcsv($handle, ['Resumen']);
                fputcsv($handle, ['Ventas brutas', $totales['ventas_brutas']]);
                fputcsv($handle, ['Descuentos', $totales['descuentos']]);
                fputcsv($handle, ['Cargos tarjeta', $totales['cargos_tarjeta']]);
                fputcsv($handle, ['Descuento general (sin asignar)', $generalDiscountTotal]);
                fputcsv($handle, ['Ganancias', $totales['ganancias']]);
                fputcsv($handle, []);

                fputcsv($handle, [
                    'Proveedor ID',
                    'Proveedor Ident',
                    'Proveedor Nombre',
                    'Ventas brutas',
                    'Descuentos (producto + cargos)',
                    'Cargos tarjeta',
                    'Ganancia total',
                    'Items count',
                ]);

                foreach ($providers as $prov) {
                    fputcsv($handle, [
                        $prov['proveedor_id'],
                        $prov['proveedor_ident'],
                        $prov['proveedor_nombre'],
                        $prov['ventas_brutas'],
                        $prov['descuentos'],
                        $prov['cargos_tarjeta'],
                        $prov['ganancia_total'],
                        count($prov['items']),
                    ]);
                }

                fputcsv($handle, []);
                fputcsv($handle, [
                    'Proveedor ID',
                    'Proveedor Ident',
                    'Proveedor Nombre',
                    'VentaDesg ID',
                    'Venta ID',
                    'Venta Tabla ID',
                    'Fecha',
                    'Fecha ISO',
                    'Producto Ident',
                    'Producto Nombre',
                    'Cantidad',
                    'Precio unitario',
                    'Total',
                    'Descuento producto',
                    'Cargo tarjeta',
                    'Descuento total',
                    'Ganancia',
                    'Metodo',
                    'Vendedor',
                    'Venta total',
                    'Promotion',
                ]);

                foreach ($providers as $prov) {
                    foreach ($prov['items'] as $item) {
                        fputcsv($handle, [
                            $prov['proveedor_id'],
                            $prov['proveedor_ident'],
                            $prov['proveedor_nombre'],
                            $item['ventadesg_id'],
                            $item['idventa'],
                            $item['venta_id'],
                            $item['fecha'],
                            $item['fecha_iso'],
                            $item['producto_ident'],
                            $item['producto_nombre'],
                            $item['cantidad'],
                            $item['precio_unitario'],
                            $item['total'],
                            $item['descuento_producto'],
                            $item['cargo_tarjeta'],
                            $item['descuento_total'],
                            $item['ganancia'],
                            $item['metodo'],
                            $item['vendedor'],
                            $item['venta_total'],
                            $item['promotion'],
                        ]);
                    }
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        }

        return response()->json([
            'from_date' => $inicioString,
            'to_date' => $finString,
            'resumen' => $totales,
            'proveedores' => $providers,
            'descuento_general_total' => $generalDiscountTotal,
            'cargos_tarjeta_total' => $totales['cargos_tarjeta'],
            'provider' => $provider ? [
                'id' => $provider->id,
                'ident' => $provider->ident,
                'nombre' => $provider->nombre,
            ] : null,
        ]);
    }

    public function entradas(Request $request)
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

        $query = Entrada::query()
            ->select('entradas.*', 'proveedores.nombre as proveedor_nombre')
            ->leftJoin('proveedores', function ($join) use ($driver) {
                if ($driver === 'pgsql') {
                    $join->on(DB::raw('CAST(proveedores.ident AS TEXT)'), '=', 'entradas.provid');
                } elseif ($driver === 'mysql') {
                    $join->on(DB::raw('CAST(proveedores.ident AS CHAR)'), '=', 'entradas.provid');
                } else {
                    $join->on('proveedores.ident', '=', 'entradas.provid');
                }
            });

        $dateFilter = function ($builder) use ($driver, $inicioIso, $finIso) {
            if ($driver === 'pgsql') {
                $builder->whereRaw("COALESCE(to_date(entradas.fecha, 'YYYY-MM-DD'), to_date(entradas.fecha, 'DD/MM/YYYY'), to_date(entradas.fecha, 'DD/MM/YY')) BETWEEN ? AND ?", [$inicioIso, $finIso]);
            } elseif ($driver === 'mysql') {
                $builder->whereRaw("COALESCE(STR_TO_DATE(entradas.fecha, '%Y-%m-%d'), STR_TO_DATE(entradas.fecha, '%d/%m/%Y'), STR_TO_DATE(entradas.fecha, '%d/%m/%y')) BETWEEN ? AND ?", [$inicioIso, $finIso]);
            } else {
                $builder->whereBetween('entradas.fecha', [$inicioIso, $finIso]);
            }
        };

        if ($provider) {
            $query->where('entradas.provid', '=', (string) $provider->ident);
        }

        $dateFilter($query);

        if ($driver === 'pgsql') {
            $query->orderByRaw("COALESCE(to_date(entradas.fecha, 'YYYY-MM-DD'), to_date(entradas.fecha, 'DD/MM/YYYY'), to_date(entradas.fecha, 'DD/MM/YY'))")
                ->orderBy('entradas.id');
        } elseif ($driver === 'mysql') {
            $query->orderByRaw("COALESCE(STR_TO_DATE(entradas.fecha, '%Y-%m-%d'), STR_TO_DATE(entradas.fecha, '%d/%m/%Y'), STR_TO_DATE(entradas.fecha, '%d/%m/%y'))")
                ->orderBy('entradas.id');
        } else {
            $query->orderBy('entradas.fecha')->orderBy('entradas.id');
        }

        $entradas = $query->get();

        $mapped = $entradas->map(function ($entrada) {
            $fechaIso = null;
            $fechaDisplay = $entrada->fecha;

            try {
                $parsed = $this->parseDateInput($entrada->fecha);
                $fechaIso = $parsed->toDateString();
                $fechaDisplay = $entrada->fecha;
            } catch (\Throwable $e) {
                // keep defaults
            }

            $accionCode = (int) ($entrada->accion ?? 0);
            $accionLabel = match ($accionCode) {
                1 => 'add',
                2 => 'set',
                default => 'unknown',
            };

            return [
                'id' => (int) $entrada->id,
                'fecha' => $fechaDisplay,
                'fecha_raw' => $entrada->fecha,
                'fecha_iso' => $fechaIso,
                'prodid' => $entrada->prodid,
                'prodnombre' => $entrada->prodnombre,
                'proveedor_ident' => $entrada->provid,
                'proveedor_nombre' => $entrada->proveedor_nombre,
                'ingreal' => (int) $entrada->ingreal,
                'accion_code' => $accionCode,
                'accion' => $accionLabel,
                'usuario' => $entrada->usuario,
            ];
        })->values();

        Log::info('Reporte de entradas generado', [
            'from' => $inicioString,
            'to' => $finString,
            'total_entradas' => $entradas->count(),
        ]);

        foreach (DB::getQueryLog() as $entry) {
            Log::debug('Consulta de reporte de entradas', [
                'sql' => $entry['query'],
                'bindings' => $entry['bindings'],
                'time_ms' => $entry['time'] ?? null,
            ]);
        }

        if ($request->boolean('download')) {
            $filename = sprintf('reporte_entradas_%s_%s.csv', Str::of($inicioString)->replace('/', '-'), Str::of($finString)->replace('/', '-'));

            return response()->streamDownload(function () use ($mapped) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'id',
                    'fecha',
                    'fecha_iso',
                    'fecha_raw',
                    'prodid',
                    'prodnombre',
                    'proveedor_ident',
                    'proveedor_nombre',
                    'ingreal',
                    'accion_code',
                    'accion',
                    'usuario',
                ]);

                foreach ($mapped as $entrada) {
                    fputcsv($handle, [
                        $entrada['id'],
                        $entrada['fecha'],
                        $entrada['fecha_iso'],
                        $entrada['fecha_raw'],
                        $entrada['prodid'],
                        $entrada['prodnombre'],
                        $entrada['proveedor_ident'],
                        $entrada['proveedor_nombre'],
                        $entrada['ingreal'],
                        $entrada['accion_code'],
                        $entrada['accion'],
                        $entrada['usuario'],
                    ]);
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        }

        return response()->json([
            'from_date' => $inicioString,
            'to_date' => $finString,
            'entradas' => $mapped,
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
