<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\VentaDesg;
use App\Models\Producto;
use App\Models\Inventario;
use App\Models\Entrada;
use App\Models\Egreso;
use App\Models\Proveedor;
use App\Models\Mensualidad;
use App\Models\DailyCashSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\CarbonPeriod;

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

        $ventas = Venta::with(['lineas' => function ($query) use ($inicioIso, $finIso) {
                $query->whereBetween('fecha', [$inicioIso, $finIso])->orderBy('id');
            }])
            ->whereBetween('fecha', [$inicioIso, $finIso])
            ->orderBy('fecha')
            ->orderBy('hora')
            ->orderBy('idventa')
            ->get();

        $providerUser = $this->currentProvider($request);
        if ($providerUser) {
            $ventas = $ventas->map(function (Venta $venta) use ($providerUser) {
                $filtradas = $venta->lineas
                    ->where('proveedor_id', $providerUser->ident)
                    ->values();
                $venta->setRelation('lineas', $filtradas);
                return $venta;
            })->filter(function (Venta $venta) {
                return $venta->lineas->isNotEmpty();
            })->values();
        }

        $providerIds = [];
        foreach ($ventas as $venta) {
            foreach ($venta->lineas as $linea) {
                $pid = (int) ($linea->proveedor_id ?? 0);
                if ($pid > 0) {
                    $providerIds[$pid] = true;
                }
            }
        }

        $providerMap = empty($providerIds)
            ? collect()
            : Proveedor::whereIn('ident', array_keys($providerIds))->get()->keyBy('ident');

        $ventasPayload = $ventas->map(function (Venta $venta) use ($providerMap) {
            $lineas = $venta->lineas->map(function (VentaDesg $linea) use ($providerMap) {
                $pid = (int) ($linea->proveedor_id ?? 0);
                $provider = $pid > 0 ? $providerMap->get($pid) : null;
                $providerType = $provider->tipo ?? 'normal';
                $providerDiscountType = $providerType === 'porcentaje'
                    ? 'porcentaje'
                    : ($providerType === 'consigna' ? 'consigna' : 'normal');

                $providerDiscountAmount = match ($providerDiscountType) {
                    'porcentaje' => (float) ($linea->provider_percentage_discount ?? 0),
                    'consigna' => (float) ($linea->consigna_discount ?? 0),
                    default => 0.0,
                };

                $quantity = max(1, (int) $linea->quantity ?: 1);
                $providerPrice = $quantity > 0 ? round(((float) $linea->provider_cost) / $quantity, 2) : 0.0;
                $providerDiscountAmount = round($providerDiscountAmount, 2);

                return [
                    'producto_id' => (int) $linea->producto_id,
                    'nombre' => $linea->nombre,
                    'provider' => $provider ? [
                        'id' => (int) $provider->ident,
                        'nombre' => $provider->nombre,
                        'tipo' => $providerType,
                        'porcentaje' => $provider->porcentaje_comision,
                    ] : null,
                    'quantity' => (int) $linea->quantity,
                    'free_quantity' => (int) $linea->free_quantity,
                    'unit_price' => (float) $linea->unit_price,
                    'public_total' => (float) $linea->public_total,
                    'promotion_discount_amount' => (float) ($linea->promotion_discount_amount ?? 0),
                    'manual_discount_amount' => (float) ($linea->manual_discount_amount ?? 0),
                    'credit_card_discount' => (float) ($linea->credit_card_discount ?? 0),
                    'provider_price' => $providerPrice,
                    'provider_discount_type' => $providerDiscountType,
                    'provider_discount_amount' => $providerDiscountAmount,
                    'provider_payment' => (float) ($linea->provider_payment ?? 0),
                    'admin_earnings' => (float) ($linea->admin_earnings ?? 0),
                    'free_product' => (bool) ($linea->free_product ?? false),
                ];
            })->values();

            return [
                'idventa' => (int) $venta->idventa,
                'fecha' => (string) $venta->fecha,
                'hora' => (string) $venta->hora,
                'metodo' => $venta->metodo,
                'vendedor' => $venta->vendedor,
                'totalventa' => (float) $venta->totalventa,
                'total_recibido' => (float) ($venta->total_recibido ?? 0),
                'cambio' => (float) ($venta->cambio ?? 0),
                'lineas' => $lineas,
            ];
        });

        $methodSummary = $ventasPayload
            ->groupBy('metodo')
            ->map(function ($group, $metodo) {
                return [
                    'metodo' => $metodo,
                    'total' => round($group->sum('totalventa'), 2),
                    'count' => $group->count(),
                ];
            })
            ->values()
            ->sortByDesc('total')
            ->values();

        $summary = [
            'ventas_total' => $ventasPayload->count(),
            'total_totalventa' => round($ventasPayload->sum('totalventa'), 2),
            'total_recibido' => round($ventasPayload->sum('total_recibido'), 2),
            'total_cambio' => round($ventasPayload->sum('cambio'), 2),
            'metodos' => $methodSummary,
        ];

        if ($request->boolean('download')) {
            $filename = sprintf(
                'reporte_caja_%s_%s.csv',
                str_replace('/', '-', $inicioString),
                str_replace('/', '-', $finString)
            );

            return response()->streamDownload(function () use ($ventasPayload) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'idventa',
                    'fecha',
                    'hora',
                    'metodo',
                    'totalventa',
                    'total_recibido',
                    'cambio',
                    'producto_id',
                    'producto',
                    'proveedor',
                    'proveedor_tipo',
                    'free_quantity',
                    'cantidad',
                    'precio_publico',
                    'precio_proveedor',
                    'promo_descuento',
                    'manual_descuento',
                    'tarjeta_descuento',
                    'desc_proveedor',
                    'tipo_desc_proveedor',
                    'pago_proveedor',
                    'ganancia_admin',
                ]);

                foreach ($ventasPayload as $venta) {
                    foreach ($venta['lineas'] as $linea) {
                        fputcsv($handle, [
                            $venta['idventa'],
                            $venta['fecha'],
                            $venta['hora'],
                            $venta['metodo'],
                            $venta['totalventa'],
                            $venta['total_recibido'],
                            $venta['cambio'],
                            $linea['producto_id'],
                            $linea['nombre'],
                            $linea['provider']['nombre'] ?? null,
                            $linea['provider']['tipo'] ?? 'normal',
                            $linea['free_quantity'],
                            $linea['quantity'],
                            $linea['unit_price'],
                            $linea['provider_price'],
                            $linea['promotion_discount_amount'],
                            $linea['manual_discount_amount'],
                            $linea['credit_card_discount'],
                            $linea['provider_discount_amount'],
                            $linea['provider_discount_type'],
                            $linea['provider_payment'],
                            $linea['admin_earnings'],
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
            'summary' => [
                'ventas_total' => $summary['ventas_total'],
                'total_totalventa' => $summary['total_totalventa'],
                'total_recibido' => $summary['total_recibido'],
                'total_cambio' => $summary['total_cambio'],
                'metodos' => $summary['metodos']->toArray(),
            ],
            'ventas' => $ventasPayload->values()->toArray(),
        ]);
    }


    protected function classifyMetodoChannel(?string $metodo): string
    {
        if (!$metodo) {
            return 'other';
        }

        $normalized = strtolower($metodo);
        $map = [
            'efectivo' => 'cash',
            'cash' => 'cash',
            'contado' => 'cash',
            'tarjeta' => 'card',
            'credito' => 'card',
            'debito' => 'card',
            'visa' => 'card',
            'mastercard' => 'card',
            'amex' => 'card',
            'transferencia' => 'transfer',
            'transfer' => 'transfer',
            'spei' => 'transfer',
            'banco' => 'transfer',
        ];

        return $map[$normalized] ?? 'other';
    }

    public function egresosCaja(Request $request)
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

        $egresos = Egreso::query()
            ->whereBetween('fecha', [$inicioIso, $finIso])
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $egresosTotal = (float) $egresos->sum(function (Egreso $egreso) {
            return (float) $egreso->monto;
        });

        $ingresosTotal = (float) Venta::query()
            ->whereBetween('fecha', [$inicioIso, $finIso])
            ->sum('totalventa');

        $saldo = round($ingresosTotal - $egresosTotal, 2);

        $mapped = $egresos->map(function (Egreso $egreso) {
            return [
                'id' => (int) $egreso->id,
                'fecha' => optional($egreso->fecha)->toDateString(),
                'descripcion' => $egreso->descripcion,
                'monto' => (float) $egreso->monto,
                'creado_por' => $egreso->creado_por,
            ];
        })->values();

        if ($request->boolean('download')) {
            $filename = sprintf(
                'reporte_egresos_caja_%s_%s.csv',
                Str::of($inicioString)->replace('/', '-'),
                Str::of($finString)->replace('/', '-')
            );

            return response()->streamDownload(function () use ($mapped) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['id', 'fecha', 'descripcion', 'creado_por', 'monto']);

                foreach ($mapped as $row) {
                    fputcsv($handle, [
                        $row['id'],
                        $row['fecha'],
                        $row['descripcion'],
                        $row['creado_por'],
                        $row['monto'],
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
            'egresos' => $mapped,
            'summary' => [
                'ingresos_total' => round($ingresosTotal, 2),
                'egresos_total' => round($egresosTotal, 2),
                'saldo' => $saldo,
            ],
        ]);
    }

    public function flujoCaja(Request $request)
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

        $inicioIso = $inicioCarbon->toDateString();
        $finIso = $finCarbon->toDateString();

        $rows = DailyCashSummary::query()
            ->whereBetween('fecha', [$inicioIso, $finIso])
            ->orderBy('fecha')
            ->get();

        $items = $rows->map(function (DailyCashSummary $summary) {
            $fecha = $summary->fecha instanceof Carbon
                ? $summary->fecha->toDateString()
                : Carbon::parse($summary->fecha)->toDateString();

            $saldoInicial = (float) ($summary->saldo_inicial ?? 0);
            $efectivo = (float) ($summary->efectivo ?? 0);
            $transferencia = (float) ($summary->transferencia ?? 0);
            $tarjeta = (float) ($summary->tarjeta ?? 0);
            $egresos = (float) ($summary->egresos ?? 0);
            $saldoCierre = (float) ($summary->saldo_cierre ?? 0);
            $ingresosTotal = round($efectivo + $transferencia + $tarjeta, 2);

            return [
                'fecha' => $fecha,
                'saldo_inicial' => round($saldoInicial, 2),
                'efectivo' => round($efectivo, 2),
                'transferencia' => round($transferencia, 2),
                'tarjeta' => round($tarjeta, 2),
                'ingresos_total' => $ingresosTotal,
                'egresos' => round($egresos, 2),
                'saldo_cierre' => round($saldoCierre, 2),
            ];
        });

        $resumen = [
            'dias' => $items->count(),
            'saldo_inicial' => round($items->sum('saldo_inicial'), 2),
            'efectivo' => round($items->sum('efectivo'), 2),
            'transferencia' => round($items->sum('transferencia'), 2),
            'tarjeta' => round($items->sum('tarjeta'), 2),
            'ingresos_total' => round($items->sum('ingresos_total'), 2),
            'egresos' => round($items->sum('egresos'), 2),
            'saldo_cierre' => round($items->sum('saldo_cierre'), 2),
        ];

        if ($request->boolean('download')) {
            $filename = sprintf(
                'reporte_flujo_caja_%s_%s.csv',
                Str::of($inicioIso)->replace('-', ''),
                Str::of($finIso)->replace('-', '')
            );

            return response()->streamDownload(function () use ($items, $resumen, $inicioIso, $finIso) {
                $handle = fopen('php://output', 'w');

                fputcsv($handle, ['Reporte flujo de caja']);
                fputcsv($handle, ['Desde', $inicioIso, 'Hasta', $finIso]);
                fputcsv($handle, []);
                fputcsv($handle, ['Resumen']);
                fputcsv($handle, ['Días', $resumen['dias']]);
                fputcsv($handle, ['Saldo inicial', $resumen['saldo_inicial']]);
                fputcsv($handle, ['Efectivo', $resumen['efectivo']]);
                fputcsv($handle, ['Transferencia', $resumen['transferencia']]);
                fputcsv($handle, ['Tarjeta', $resumen['tarjeta']]);
                fputcsv($handle, ['Ingresos totales', $resumen['ingresos_total']]);
                fputcsv($handle, ['Egresos', $resumen['egresos']]);
                fputcsv($handle, ['Saldo cierre', $resumen['saldo_cierre']]);
                fputcsv($handle, []);

                fputcsv($handle, [
                    'Fecha',
                    'Saldo inicial',
                    'Efectivo',
                    'Transferencia',
                    'Tarjeta',
                    'Ingresos totales',
                    'Egresos',
                    'Saldo cierre',
                ]);

                foreach ($items as $item) {
                    fputcsv($handle, [
                        $item['fecha'],
                        $item['saldo_inicial'],
                        $item['efectivo'],
                        $item['transferencia'],
                        $item['tarjeta'],
                        $item['ingresos_total'],
                        $item['egresos'],
                        $item['saldo_cierre'],
                    ]);
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        }

    return response()->json([
            'from_date' => $inicioIso,
            'to_date' => $finIso,
            'resumen' => $resumen,
            'items' => $items,
        ]);
    }

    public function mensualidad(Request $request)
    {
        $mesCobro = $request->input('mes_cobro');
        $status = $request->input('status');
        $proveedorId = $request->input('proveedor_id');

        if (!$mesCobro) {
            return response()->json(['message' => 'Debe proporcionar mes_cobro (formato YYYY-MM).'], 422);
        }

        try {
            $month = Carbon::createFromFormat('Y-m', $mesCobro);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Formato de mes inválido, use YYYY-MM.'], 422);
        }

        $mesCobroNormalized = $month->format('Y-m');

        $query = Mensualidad::query()
            ->with(['proveedor:id,nombre,email'])
            ->where('mes_cobro', $mesCobroNormalized);

        if ($proveedorId) {
            $query->where('proveedor_id', $proveedorId);
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $mensualidades = $query->orderBy('fecha')->orderBy('id')->get();

        $mapped = $mensualidades->map(function (Mensualidad $mensualidad) {
            $fechaCobro = optional($mensualidad->fecha)->toDateString();
            $paymentDate = optional($mensualidad->payment_date)->toDateString();

            return [
                'id' => (int) $mensualidad->id,
                'proveedor' => [
                    'id' => $mensualidad->proveedor->id ?? $mensualidad->proveedor_id,
                    'nombre' => $mensualidad->proveedor->nombre ?? $mensualidad->nombre,
                    'email' => $mensualidad->proveedor->email ?? null,
                ],
                'concepto' => $mensualidad->concepto,
                'nota' => $mensualidad->nota,
                'mes_cobro' => $mensualidad->mes_cobro,
                'fecha_cobro' => $fechaCobro,
                'importe' => (float) $mensualidad->importe,
                'cantidad_pago' => (float) $mensualidad->cantidad_pago,
                'restante' => (float) $mensualidad->restante,
                'pago_completo' => (bool) $mensualidad->pago_completo,
                'status' => $mensualidad->status,
                'payment_date' => $paymentDate,
                'receipt_path' => $mensualidad->receipt_path,
                'cobro_path' => $mensualidad->cobro_path,
            ];
        })->values();

        $summary = [
            'total_cobros' => $mensualidades->count(),
            'importe_total' => round((float) $mensualidades->sum('importe'), 2),
            'pagado_total' => round((float) $mensualidades->sum('cantidad_pago'), 2),
            'restante_total' => round((float) $mensualidades->sum('restante'), 2),
            'pagos_completos' => $mensualidades->where('pago_completo', true)->count(),
        ];

        if ($request->boolean('download')) {
            $filename = sprintf('reporte_mensualidad_%s.csv', Str::of($mesCobroNormalized)->replace('-', '_'));
            return response()->streamDownload(function () use ($mapped) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'id',
                    'proveedor_nombre',
                    'proveedor_email',
                    'mes_cobro',
                    'fecha_cobro',
                    'concepto',
                    'importe',
                    'cantidad_pago',
                    'restante',
                    'pago_completo',
                    'status',
                    'payment_date',
                    'cobro_path',
                    'receipt_path',
                ]);

                foreach ($mapped as $row) {
                    fputcsv($handle, [
                        $row['id'],
                        $row['proveedor']['nombre'] ?? null,
                        $row['proveedor']['email'] ?? null,
                        $row['mes_cobro'],
                        $row['fecha_cobro'],
                        $row['concepto'],
                        $row['importe'],
                        $row['cantidad_pago'],
                        $row['restante'],
                        $row['pago_completo'] ? 1 : 0,
                        $row['status'],
                        $row['payment_date'],
                        $row['cobro_path'],
                        $row['receipt_path'],
                    ]);
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        }

        return response()->json([
            'mes_cobro' => $mesCobroNormalized,
            'filters' => [
                'status' => $status && $status !== 'all' ? $status : null,
                'proveedor_id' => $proveedorId ? (int) $proveedorId : null,
            ],
            'summary' => $summary,
            'items' => $mapped,
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

        $sort = $request->get('sort', 'nombre');
        $direction = strtolower($request->get('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sort === 'proveedor') {
            $query->leftJoin('proveedores as orden_proveedor', 'orden_proveedor.ident', '=', 'producto.proveedorid')
                ->orderByRaw('LOWER(orden_proveedor.nombre) ' . $direction)
                ->select('producto.*');
        } else {
            $column = match ($sort) {
                'precio' => 'precio',
                'ident' => 'ident',
                default => 'nombre',
            };
            if ($column === 'nombre') {
                $query->orderByRaw('LOWER(nombre) ' . $direction);
            } else {
                $query->orderBy($column, $direction);
            }
        }

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
                'producto' => fn($q) => $q->select('id', 'ident', 'nombre', 'precio', 'proveedorid'),
                'producto.proveedor' => fn($q) => $q->select('id', 'ident', 'nombre'),
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

        $inicioIso = $inicioCarbon->toDateString();
        $finIso = $finCarbon->toDateString();

        $rows = DB::table('ventadesg as vd')
            ->select([
                'vd.id',
                'vd.idventa',
                'vd.fecha as linea_fecha',
                'vd.producto_id',
                'vd.nombre as producto_nombre',
                'vd.proveedor_id',
                'vd.unit_price',
                'vd.quantity',
                'vd.public_total',
                'vd.manual_discount_amount',
                'vd.credit_card_discount',
                'vd.provider_percentage_discount',
                'vd.consigna_discount',
                'vd.provider_cost',
                'vd.provider_payment',
                'vd.venta_total as linea_venta_total',
                'vd.promotion_discount_amount',
                'vd.free_product',
                'v.id as venta_id',
                'v.fecha as venta_fecha',
                'v.metodo as venta_metodo',
                'v.vendedor as venta_vendedor',
                'v.totalventa as venta_total',
                'p.id as proveedor_pk',
                'p.ident as proveedor_ident',
                'p.nombre as proveedor_nombre',
                'p.tipo as proveedor_tipo',
                'p.porcentaje_comision as proveedor_porcentaje',
            ])
            ->leftJoin('ventas as v', 'v.idventa', '=', 'vd.idventa')
            ->leftJoin('proveedores as p', 'p.ident', '=', 'vd.proveedor_id');

        if ($provider) {
            $rows->where('vd.proveedor_id', '=', $provider->ident);
        }

        $rows->whereBetween('vd.fecha', [$inicioIso, $finIso]);
        $rows->orderBy('vd.fecha')->orderBy('vd.id');

        $collection = collect($rows->get());

        $grouped = $collection->groupBy(function ($row) {
            if ($row->proveedor_ident !== null) {
                return (string) $row->proveedor_ident;
            }
            if ($row->proveedor_id !== null) {
                return (string) $row->proveedor_id;
            }
            return 'sin_proveedor';
        });

        $providers = $grouped->map(function ($group) {
            $first = $group->first();
            $proveedorId = $first->proveedor_pk ? (int) $first->proveedor_pk : null;
            $rawIdent = $first->proveedor_ident ?? $first->proveedor_id;
            $proveedorIdent = $rawIdent !== null ? (string) $rawIdent : null;
            $proveedorNombre = $first->proveedor_nombre ?: ($proveedorIdent !== null ? "Proveedor {$proveedorIdent}" : 'Sin proveedor');
            $proveedorTipo = $first->proveedor_tipo ?: 'normal';
            $proveedorPorcentaje = $first->proveedor_porcentaje !== null ? (int) $first->proveedor_porcentaje : null;

            $details = $group->map(function ($row) use ($proveedorTipo) {
                $lineGross = round((float) ($row->public_total ?? 0), 2);
                $lineManualDiscount = round((float) ($row->manual_discount_amount ?? 0), 2);
                $lineProviderCharge = round((float) ($row->credit_card_discount ?? 0), 2);
                $lineProviderPct = round((float) ($row->provider_percentage_discount ?? 0), 2);
                $lineConsigna = round((float) ($row->consigna_discount ?? 0), 2);
                $lineProviderType = $row->proveedor_tipo ?: $proveedorTipo;
                $lineProviderCost = round((float) ($row->provider_cost ?? 0), 2);
                $lineQuantity = (int) ($row->quantity ?? 0);
                $providerUnitCost = $lineQuantity > 0 ? round($lineProviderCost / $lineQuantity, 2) : null;

                $providerTypeDiscount = 0.0;
                if ($lineProviderType === 'consigna') {
                    $providerTypeDiscount = $lineConsigna;
                } elseif ($lineProviderType === 'porcentaje') {
                    $providerTypeDiscount = $lineProviderPct;
                }

                $realEarning = round($lineGross - $lineProviderCharge - $providerTypeDiscount - $lineManualDiscount, 2);
                if ($realEarning < 0) {
                    $realEarning = 0.0;
                }

                $fecha = $row->venta_fecha ?? $row->linea_fecha;
                $fechaIso = $fecha ? Carbon::parse($fecha)->toDateString() : null;

                return [
                    'ventadesg_id' => (int) $row->id,
                    'idventa' => (int) $row->idventa,
                    'venta_id' => $row->venta_id ? (int) $row->venta_id : null,
                    'fecha' => $fechaIso,
                    'fecha_raw' => $fechaIso,
                    'fecha_iso' => $fechaIso,
                    'producto_ident' => (string) ($row->producto_id ?? ''),
                    'producto_nombre' => $row->producto_nombre,
                    'cantidad' => (int) ($row->quantity ?? 0),
                    'precio_unitario' => round((float) ($row->unit_price ?? 0), 2),
                    'total' => $lineGross,
                    'card_fee' => $lineProviderCharge,
                    'manual_discount' => $lineManualDiscount,
                    'provider_discount' => $providerTypeDiscount,
                    'real_earning' => $realEarning,
                    'expected_earning' => $realEarning,
                    'provider_price' => $providerUnitCost,
                    'provider_cost_total' => $lineProviderCost,
                    'proveedor_tipo' => $lineProviderType,
                    'proveedor_porcentaje' => $row->proveedor_porcentaje,
                    'metodo' => $row->venta_metodo,
                    'vendedor' => $row->venta_vendedor,
                    'venta_total' => round((float) ($row->venta_total ?? $row->linea_venta_total ?? 0), 2),
                    'promotion' => ($row->promotion_discount_amount ?? 0) > 0 ? 'promotion' : ($row->free_product ? 'gratis' : null),
                ];
            })->values();

            return [
                'proveedor_id' => $proveedorId,
                'proveedor_ident' => $proveedorIdent,
                'proveedor_nombre' => $proveedorNombre,
                'proveedor_tipo' => $proveedorTipo,
                'proveedor_porcentaje' => $proveedorPorcentaje,
                'total_vendido' => round($details->sum(fn ($item) => $item['total']), 2),
                'card_fee_total' => round($details->sum(fn ($item) => $item['card_fee']), 2),
                'manual_discount_total' => round($details->sum(fn ($item) => $item['manual_discount']), 2),
                'tipo_descuento_total' => round($details->sum(fn ($item) => $item['provider_discount']), 2),
                'real_earning' => round($details->sum(fn ($item) => $item['real_earning']), 2),
                'expected_earning' => round($details->sum(fn ($item) => $item['real_earning']), 2),
                'items' => $details,
            ];
        })->values();

        $totales = [
            'ventas_brutas' => round($providers->sum(fn($row) => $row['total_vendido']), 2),
            'descuentos' => round($providers->sum(fn($row) => $row['tipo_descuento_total']), 2),
            'manual_descuentos' => round($providers->sum(fn($row) => $row['manual_discount_total']), 2),
            'cargos_tarjeta' => round($providers->sum(fn($row) => $row['card_fee_total']), 2),
            'descuento_general' => round($providers->sum(fn($row) => $row['manual_discount_total']), 2),
            'ganancias' => round($providers->sum(fn($row) => $row['real_earning']), 2),
        ];
        $generalDiscountTotal = $totales['manual_descuentos'];

        if ($request->boolean('download')) {
            $filename = sprintf(
                'reporte_caja_proveedores_%s_%s.csv',
                Str::of($inicioIso)->replace('-', ''),
                Str::of($finIso)->replace('-', '')
            );

            return response()->streamDownload(function () use ($providers, $totales, $inicioIso, $finIso, $generalDiscountTotal) {
                $handle = fopen('php://output', 'w');

                fputcsv($handle, ['Reporte caja por proveedor']);
                fputcsv($handle, ['Desde', $inicioIso, 'Hasta', $finIso]);
                fputcsv($handle, []);
                fputcsv($handle, ['Resumen']);
                fputcsv($handle, ['Ventas brutas', $totales['ventas_brutas']]);
                fputcsv($handle, ['Descuento por tipo (consigna/porcentaje)', $totales['descuentos']]);
                fputcsv($handle, ['Descuento manual', $totales['manual_descuentos']]);
                fputcsv($handle, ['Cargos por tarjeta', $totales['cargos_tarjeta']]);
                fputcsv($handle, ['Ganancia real', $totales['ganancias']]);
                fputcsv($handle, []);

                fputcsv($handle, [
                    'Proveedor ID',
                    'Proveedor Ident',
                    'Proveedor Nombre',
                    'Tipo',
                    'Porcentaje',
                    'Ventas brutas',
                    'Descuento por tipo',
                    'Descuento manual',
                    'Cargos tarjeta',
                    'Ganancia real',
                    'Items count',
                ]);

                foreach ($providers as $prov) {
                    fputcsv($handle, [
                        $prov['proveedor_id'],
                        $prov['proveedor_ident'],
                        $prov['proveedor_nombre'],
                        $prov['proveedor_tipo'],
                        $prov['proveedor_porcentaje'],
                        $prov['total_vendido'],
                        $prov['tipo_descuento_total'],
                        $prov['manual_discount_total'],
                        $prov['card_fee_total'],
                        $prov['real_earning'],
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
                    'Producto Ident',
                    'Producto Nombre',
                    'Cantidad',
                    'Precio unitario',
                    'Total vendido',
                    'Descuento por tipo',
                    'Descuento manual',
                    'Cargo tarjeta',
                    'Ganancia real',
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
                            $item['producto_ident'],
                            $item['producto_nombre'],
                            $item['cantidad'],
                            $item['precio_unitario'],
                            $item['total'],
                            $item['provider_discount'],
                            $item['manual_discount'],
                            $item['card_fee'],
                            $item['real_earning'],
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
            'from_date' => $inicioIso,
            'to_date' => $finIso,
            'resumen' => $totales,
            'proveedores' => $providers,
            'descuento_general_total' => $generalDiscountTotal,
            'cargos_tarjeta_total' => $totales['cargos_tarjeta'],
            'manual_descuentos_total' => $generalDiscountTotal,
            'provider' => $provider ? [
                'id' => $provider->id,
                'ident' => $provider->ident,
                'nombre' => $provider->nombre,
            ] : null,
        ]);
    }

    public function providerTrends(Request $request)
    {
        $provider = $this->currentProvider($request);
        if (!$provider) {
            return response()->json(['message' => 'Solo disponible para proveedores'], 403);
        }

        $maxDays = 10;
        $defaultEnd = Carbon::today();
        $defaultStart = $defaultEnd->copy()->subDays($maxDays - 1);

        $fromInput = $request->input('from_date');
        $toInput = $request->input('to_date');

        Log::info('providerTrends: inicio', [
            'provider_id' => $provider->id,
            'provider_ident' => $provider->ident,
            'from_input' => $fromInput,
            'to_input' => $toInput,
        ]);

        try {
            $fromDate = $fromInput ? $this->parseDateInput($fromInput) : $defaultStart;
            $toDate = $toInput ? $this->parseDateInput($toInput) : $defaultEnd;
        } catch (\Throwable $e) {
            Log::warning('providerTrends: formato de fecha inválido', [
                'from' => $fromInput,
                'to' => $toInput,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Formato de fecha inválido.'], 422);
        }

        if ($fromDate->gt($toDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $fromIso = $fromDate->format('Y-m-d');
        $toIso = $toDate->format('Y-m-d');

        Log::info('providerTrends: rango normalizado', [
            'from' => $fromIso,
            'to' => $toIso,
        ]);

        $query = DB::table('ventadesg as vd')
            ->select([
                'vd.idprod',
                'vd.nombre as producto_nombre',
                'vd.cant',
                'vd.total',
                'vd.descuento_producto',
                'vd.cargo_tarjeta_proveedor',
                'vd.fecha',
            ])
            ->where('vd.proveedor', '=', $provider->ident);

        $rows = $query
            ->whereBetween('vd.fecha', [$fromIso, $toIso])
            ->orderBy('vd.fecha')
            ->get();

        Log::info('providerTrends: registros obtenidos', [
            'count' => $rows->count(),
            'rows' => $rows,
        ]);

        $dateBuckets = [];
        $period = CarbonPeriod::create($fromDate, $toDate);
        foreach ($period as $cursor) {
            $dateBuckets[$cursor->toDateString()] = 0.0;
        }

        $topProducts = [];

        foreach ($rows as $row) {
            $rowDate = Carbon::parse($row->fecha);
            if ($rowDate->lt($fromDate) || $rowDate->gt($toDate)) {
                continue;
            }

            $dateKey = $rowDate->toDateString();
            if (!array_key_exists($dateKey, $dateBuckets)) {
                $dateBuckets[$dateKey] = 0.0;
            }

            $cantidad = (int) ($row->cant ?? 0);
            $bruto = (float) ($row->total ?? 0);
            $descuento = (float) ($row->descuento_producto ?? 0);
            $cargoTarjeta = (float) ($row->cargo_tarjeta_proveedor ?? 0);
            $neto = $bruto - $descuento - $cargoTarjeta;
            $dateBuckets[$dateKey] += $neto;

            $productoKey = (string) ($row->idprod ?? 'sin_ident');
            if (!isset($topProducts[$productoKey])) {
                $topProducts[$productoKey] = [
                    'ident' => $productoKey,
                    'nombre' => $row->producto_nombre ?: "Producto {$productoKey}",
                    'cantidad' => 0,
                    'total' => 0.0,
                ];
            }

            $topProducts[$productoKey]['cantidad'] += $cantidad;
            $topProducts[$productoKey]['total'] += $bruto;
        }

        Log::info('providerTrends: acumulados', [
            'date_buckets' => $dateBuckets,
            'top_products_raw' => $topProducts,
        ]);

        $topProducts = collect($topProducts)
            ->sortByDesc(fn ($item) => $item['cantidad'])
            ->take(5)
            ->map(function ($item) {
                $item['total'] = round((float) $item['total'], 2);
                return $item;
            })
            ->values()
            ->all();

        $earnings = [];
        foreach ($dateBuckets as $date => $amount) {
            $earnings[] = [
                'date' => $date,
                'amount' => round($amount, 2),
            ];
        }

        Log::info('providerTrends: salida final', [
            'range' => [
                'start' => $fromIso,
                'end' => $toIso,
            ],
            'top_products' => $topProducts,
            'earnings' => $earnings,
        ]);

        return response()->json([
            'range' => [
                'start' => $fromIso,
                'end' => $toIso,
            ],
            'top_products' => $topProducts,
            'earnings' => $earnings,
        ]);
    }

    public function entradas(Request $request)
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

        $dateFilter = function ($builder) use ($inicioIso, $finIso) {
            $builder->whereBetween('entradas.fecha', [$inicioIso, $finIso]);
        };

        if ($provider) {
            $query->where('entradas.provid', '=', (string) $provider->ident);
        }

        $dateFilter($query);

        $query->orderBy('entradas.fecha')->orderBy('entradas.id');

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

        $formats = [
            'd/m/y',
            'd/m/Y',
            'Y-m-d',
            'Y/m/d',
            'm/d/Y',
            'm-d-Y',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value, config('app.timezone'));
            } catch (\Throwable $e) {
                continue;
            }
        }

        return Carbon::parse($value);
    }
}
