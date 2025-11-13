<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Producto;
use App\Models\Inventario;
use App\Models\Entrada;
use App\Models\Proveedor;
use App\Models\Mensualidad;
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

        $dateFilter = function ($query) use ($inicioIso, $finIso) {
            $query->whereBetween('fecha', [$inicioIso, $finIso]);
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

        $ventasQuery->orderBy('fecha')->orderBy('idventa');

        $ventas = $ventasQuery->get();

        if ($provider) {
            $ventas = $ventas->filter(function (Venta $venta) use ($provider) {
                $filtered = $venta->lineas->where('proveedor', $provider->ident)->values();
                $venta->setRelation('lineas', $filtered);
                return $filtered->isNotEmpty();
            })->values();
        }

        $providerIds = [];
        foreach ($ventas as $venta) {
            foreach ($venta->lineas as $linea) {
                $pid = (int) ($linea->proveedor ?? 0);
                if ($pid > 0) {
                    $providerIds[$pid] = true;
                }
            }
        }
        $providerMap = Proveedor::whereIn('ident', array_keys($providerIds))
            ->get()
            ->keyBy('ident');

        $summaryTotals = [
            'ventas_total' => 0,
            'subtotal' => 0.0,
            'descuento_lineas' => 0.0,
            'tarjeta_cargo' => 0.0,
            'totalventa' => 0.0,
            'ingreso_real' => 0.0,
            'costo_total' => 0.0,
            'ganancia_total' => 0.0,
        ];

        $channelTotals = [
            'cash' => 0.0,
            'card' => 0.0,
            'transfer' => 0.0,
            'other' => 0.0,
        ];
        $methodTotals = [];
        $providerGlobal = [];
        $productTotals = [];
        $totalUnidades = 0;
        $totalIngresos = 0.0;

        $mapped = $ventas->map(function (Venta $venta) use (
            $providerMap,
            &$summaryTotals,
            &$channelTotals,
            &$methodTotals,
            &$providerGlobal,
            &$productTotals,
            &$totalUnidades,
            &$totalIngresos
        ) {
            $lineas = $venta->lineas;
            $lineDiscountTotal = (float) $lineas->sum(function ($linea) {
                return (float) ($linea->descuento_producto ?? 0);
            });
            $lineCardCharges = (float) $lineas->sum(function ($linea) {
                return (float) ($linea->cargo_tarjeta_proveedor ?? 0);
            });

            $lineGrossSubtotal = (float) $lineas->sum(function ($linea) {
                return (float) ($linea->total ?? 0);
            });

            $subtotal = (float) ($venta->subtotal ?? $lineGrossSubtotal);
            if ($subtotal <= 0 || abs($subtotal - $lineGrossSubtotal) <= 0.05) {
                $subtotal = round($lineGrossSubtotal, 2);
            }

            $tarjetaCargo = round((float) ($venta->tarjeta_cargo ?? $lineCardCharges), 2);
            $totalventa = round((float) ($venta->totalventa ?? ($lineGrossSubtotal - $lineDiscountTotal)), 2);
            $ingresoReal = round((float) ($venta->ingreso_real ?? $totalventa), 2);

            $lineEntries = [];
            $providerSummary = [];

            foreach ($lineas as $linea) {
                $pid = (int) ($linea->proveedor ?? 0);
                $provider = $providerMap->get($pid);
                $providerName = $provider->nombre ?? "Proveedor {$pid}";
                $providerType = $provider->tipo ?? 'normal';
                $providerPct = $providerType === 'porcentaje'
                    ? ($provider->porcentaje_comision ?? null)
                    : null;

                $lineTotal = (float) ($linea->total ?? 0);
                $providerBruto = (float) ($linea->proveedor_bruto ?? 0);
                $providerDiscount = (float) ($linea->proveedor_descuento ?? 0);
                $providerNet = (float) ($linea->proveedor_neto ?? ($providerBruto - $providerDiscount));
                $providerCardCharge = (float) ($linea->cargo_tarjeta_proveedor ?? 0);
                $adminGanancia = (float) ($linea->admin_ganancia ?? 0);

                $lineEntries[] = [
                    'idprod' => (int) $linea->idprod,
                    'nombre' => $linea->nombre,
                    'proveedor' => $pid,
                    'proveedor_nombre' => $providerName,
                    'proveedor_tipo' => $providerType,
                    'proveedor_porcentaje' => $providerPct,
                    'puni' => (float) $linea->puni,
                    'cant' => (int) $linea->cant,
                    'total' => $lineTotal,
                    'descuento_producto' => (float) ($linea->descuento_producto ?? 0),
                    'cargo_tarjeta_proveedor' => $providerCardCharge,
                    'promotion' => $linea->promotion ?? 'normal',
                    'proveedor_bruto' => $providerBruto,
                    'proveedor_descuento' => $providerDiscount,
                    'proveedor_neto' => $providerNet,
                    'admin_ganancia' => $adminGanancia,
                ];

                $totalUnidades += (int) $linea->cant;
                $productKey = $linea->idprod ? (string) $linea->idprod : ($linea->nombre ?? uniqid('prod_', true));
                if (!isset($productTotals[$productKey])) {
                    $productTotals[$productKey] = [
                        'nombre' => $linea->nombre,
                        'proveedor' => $providerName,
                        'unidades' => 0,
                        'total' => 0.0,
                    ];
                }
                $productTotals[$productKey]['unidades'] += (int) $linea->cant;
                $productTotals[$productKey]['total'] += $lineTotal;

                if ($pid > 0) {
                    if (!isset($providerSummary[$pid])) {
                        $providerSummary[$pid] = [
                            'proveedor_id' => $pid,
                            'nombre' => $providerName,
                            'tipo' => $providerType,
                            'porcentaje' => $providerPct,
                            'publico_total' => 0.0,
                            'proveedor_bruto' => 0.0,
                            'proveedor_descuento' => 0.0,
                            'provider_card_charge' => 0.0,
                            'proveedor_neto' => 0.0,
                            'admin_ganancia' => 0.0,
                        ];
                    }
                    $providerSummary[$pid]['publico_total'] += $lineTotal;
                    $providerSummary[$pid]['proveedor_bruto'] += $providerBruto;
                    $providerSummary[$pid]['proveedor_descuento'] += $providerDiscount;
                    $providerSummary[$pid]['provider_card_charge'] += $providerCardCharge;
                    $providerSummary[$pid]['proveedor_neto'] += $providerNet;
                    $providerSummary[$pid]['admin_ganancia'] += $adminGanancia;
                }
            }

            $providerSummary = array_map(function (array $entry) use ($subtotal) {
                $entry['publico_total'] = round($entry['publico_total'], 2);
                $entry['proveedor_bruto'] = round($entry['proveedor_bruto'], 2);
                $entry['proveedor_descuento'] = round($entry['proveedor_descuento'], 2);
                $entry['provider_card_charge'] = round($entry['provider_card_charge'], 2);
                $entry['proveedor_neto'] = round($entry['proveedor_neto'], 2);
                $entry['admin_ganancia'] = round($entry['admin_ganancia'], 2);
                $entry['percent'] = $subtotal > 0
                    ? round(($entry['publico_total'] / $subtotal) * 100, 2)
                    : 0.0;
                return $entry;
            }, array_values($providerSummary));

            foreach ($providerSummary as $provEntry) {
                $provKey = $provEntry['proveedor_id'] . '|' . $provEntry['nombre'];
                if (!isset($providerGlobal[$provKey])) {
                    $providerGlobal[$provKey] = [
                        'proveedor_id' => $provEntry['proveedor_id'],
                        'nombre' => $provEntry['nombre'],
                        'tipo' => $provEntry['tipo'],
                        'porcentaje' => $provEntry['porcentaje'],
                        'ventas_brutas' => 0.0,
                        'card_charge' => 0.0,
                        'descuentos' => 0.0,
                        'neto' => 0.0,
                    ];
                }
                $providerGlobal[$provKey]['ventas_brutas'] += $provEntry['publico_total'];
                $providerGlobal[$provKey]['card_charge'] += $provEntry['provider_card_charge'];
                $providerGlobal[$provKey]['descuentos'] += $provEntry['proveedor_descuento'];
                $providerGlobal[$provKey]['neto'] += $provEntry['proveedor_neto'];
            }

            $costoTotal = (float) ($venta->costo_total ?? array_sum(array_column($providerSummary, 'proveedor_neto')));
            $gananciaTotal = (float) ($venta->ganancia_total ?? array_sum(array_column($providerSummary, 'admin_ganancia')));

            $channel = $this->classifyMetodoChannel($venta->metodo);
            $cobroNeto = max((float) ($venta->recibo ?? 0) - (float) ($venta->cambio ?? 0), 0);
            $amountForChannel = $channel === 'cash' ? $cobroNeto : $totalventa;
            $channelTotals[$channel] += $amountForChannel;
            $methodLabel = strtoupper($venta->metodo ?? '—');
            $methodTotals[$methodLabel] = ($methodTotals[$methodLabel] ?? 0) + $amountForChannel;
            $totalIngresos += $amountForChannel;

            $summaryTotals['ventas_total']++;
            $summaryTotals['subtotal'] += $subtotal;
            $summaryTotals['descuento_lineas'] += $lineDiscountTotal;
            $summaryTotals['tarjeta_cargo'] += $tarjetaCargo;
            $summaryTotals['totalventa'] += $totalventa;
            $summaryTotals['ingreso_real'] += $ingresoReal;
            $summaryTotals['costo_total'] += $costoTotal;
            $summaryTotals['ganancia_total'] += $gananciaTotal;

            return [
                'idventa' => $venta->idventa,
                'fecha' => $venta->fecha,
                'metodo' => $venta->metodo,
                'subtotal' => round($subtotal, 2),
                'descuento_lineas' => round($lineDiscountTotal, 2),
                'tarjeta_cargo' => $tarjetaCargo,
                'totalventa' => $totalventa,
                'ingreso_real' => $ingresoReal,
                'costo_total' => round($costoTotal, 2),
                'ganancia_total' => round($gananciaTotal, 2),
                'ie' => (int) $venta->ie,
                'concepto' => $venta->concepto,
                'recibo' => (float) $venta->recibo,
                'cambio' => (float) $venta->cambio,
                'vendedor' => $venta->vendedor,
                'providers' => $providerSummary,
                'lineas' => $lineEntries,
            ];
        })->values();

        $summaryPayload = [
            'ventas_total' => $summaryTotals['ventas_total'],
            'subtotal' => round($summaryTotals['subtotal'], 2),
            'descuento_lineas' => round($summaryTotals['descuento_lineas'], 2),
            'tarjeta_cargo' => round($summaryTotals['tarjeta_cargo'], 2),
            'total_totalventa' => round($summaryTotals['totalventa'], 2),
            'ingreso_real' => round($summaryTotals['ingreso_real'], 2),
            'costo_total' => round($summaryTotals['costo_total'], 2),
            'ganancia_total' => round($summaryTotals['ganancia_total'], 2),
        ];

        $basics = [
            'total_ventas' => $summaryTotals['ventas_total'],
            'total_unidades' => $totalUnidades,
            'total_ingresos' => round($totalIngresos, 2),
        ];

        $paymentSummary = [
            'channels' => array_map(fn ($value) => round($value, 2), $channelTotals),
            'total' => round(array_sum($channelTotals), 2),
            'methods' => collect($methodTotals)
                ->map(fn ($amount, $label) => ['label' => $label, 'amount' => round($amount, 2)])
                ->sortByDesc('amount')
                ->values()
                ->all(),
        ];

        $providerDiscounts = collect($providerGlobal)
            ->map(fn ($entry) => [
                'proveedor_id' => $entry['proveedor_id'],
                'nombre' => $entry['nombre'],
                'tipo' => $entry['tipo'],
                'porcentaje' => $entry['porcentaje'],
                'ventas_brutas' => round($entry['ventas_brutas'], 2),
                'card_charge' => round($entry['card_charge'], 2),
                'descuentos' => round($entry['descuentos'], 2),
                'neto' => round($entry['neto'], 2),
            ])
            ->sort(function ($a, $b) {
                if ($a['card_charge'] === $b['card_charge']) {
                    return $b['ventas_brutas'] <=> $a['ventas_brutas'];
                }
                return $b['card_charge'] <=> $a['card_charge'];
            })
            ->values()
            ->all();

        $topProducts = collect($productTotals)
            ->map(fn ($entry) => [
                'nombre' => $entry['nombre'],
                'proveedor' => $entry['proveedor'],
                'unidades' => $entry['unidades'],
                'total' => round($entry['total'], 2),
            ])
            ->sortByDesc('total')
            ->take(10)
            ->values()
            ->all();

        Log::info('Reporte de caja generado', [
            'from' => $inicioString,
            'to' => $finString,
            'total_ventas' => $mapped->count(),
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
                    'venta_id',
                    'fecha',
                    'metodo',
                    'subtotal',
                    'totalventa',
                    'ingreso_real',
                    'tarjeta_cargo',
                    'costo_total',
                    'ganancia_total',
                    'descuento_lineas',
                    'proveedor_id',
                    'proveedor_nombre',
                    'proveedor_tipo',
                    'proveedor_porcentaje',
                    'proveedor_publico_total',
                    'proveedor_bruto',
                    'proveedor_descuento',
                    'proveedor_cargo_tarjeta',
                    'proveedor_neto',
                    'proveedor_ganancia_admin',
                    'linea_idprod',
                    'linea_nombre',
                    'linea_proveedor',
                    'linea_puni',
                    'linea_cant',
                    'linea_total',
                    'linea_descuento_producto',
                    'linea_cargo_tarjeta_proveedor',
                    'linea_proveedor_bruto',
                    'linea_proveedor_descuento',
                    'linea_proveedor_neto',
                    'linea_admin_ganancia',
                    'linea_promotion',
                ]);

                foreach ($mapped as $venta) {
                    $lineas = $venta['lineas'] ?? [];
                    if (empty($lineas)) {
                        fputcsv($handle, [
                            $venta['idventa'],
                            $venta['fecha'],
                            $venta['metodo'],
                            $venta['subtotal'],
                            $venta['totalventa'],
                            $venta['ingreso_real'],
                            $venta['tarjeta_cargo'],
                            $venta['costo_total'],
                            $venta['ganancia_total'],
                            $venta['descuento_lineas'],
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

                    foreach ($lineas as $linea) {
                        fputcsv($handle, [
                            $venta['idventa'],
                            $venta['fecha'],
                            $venta['metodo'],
                            $venta['subtotal'],
                            $venta['totalventa'],
                            $venta['ingreso_real'],
                            $venta['tarjeta_cargo'],
                            $venta['costo_total'],
                            $venta['ganancia_total'],
                            $venta['descuento_lineas'],
                            $linea['proveedor'],
                            $linea['proveedor_nombre'] ?? null,
                            $linea['proveedor_tipo'] ?? null,
                            $linea['proveedor_porcentaje'] ?? null,
                            $linea['total'],
                            $linea['proveedor_bruto'],
                            $linea['proveedor_descuento'],
                            $linea['cargo_tarjeta_proveedor'],
                            $linea['proveedor_neto'],
                            $linea['admin_ganancia'],
                            $linea['idprod'],
                            $linea['nombre'],
                            $linea['proveedor'],
                            $linea['puni'],
                            $linea['cant'],
                            $linea['total'],
                            $linea['descuento_producto'],
                            $linea['cargo_tarjeta_proveedor'],
                            $linea['proveedor_bruto'],
                            $linea['proveedor_descuento'],
                            $linea['proveedor_neto'],
                            $linea['admin_ganancia'],
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
            'summary' => $summaryPayload,
            'ventas' => $mapped,
            'basics' => $basics,
            'payment_summary' => $paymentSummary,
            'provider_discounts' => $providerDiscounts,
            'top_products' => $topProducts,
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

        $baseQuery = Venta::query()
            ->whereBetween('fecha', [$inicioIso, $finIso]);

        $egresosQuery = (clone $baseQuery)->where('ie', 0)->orderBy('fecha')->orderBy('idventa');

        $egresos = $egresosQuery->get();
        $egresosTotal = (float) $egresos->sum(function (Venta $venta) {
            return (float) $venta->totalventa;
        });

        $ingresosTotal = (float) (clone $baseQuery)
            ->where('ie', 1)
            ->sum('totalventa');

        $saldo = round($ingresosTotal - $egresosTotal, 2);

        $mapped = $egresos->map(function (Venta $venta) {
            return [
                'idventa' => $venta->idventa,
                'fecha' => $venta->fecha,
                'metodo' => $venta->metodo,
                'concepto' => $venta->concepto,
                'totalventa' => (float) $venta->totalventa,
                'vendedor' => $venta->vendedor,
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
                fputcsv($handle, ['idventa', 'fecha', 'metodo', 'vendedor', 'concepto', 'monto']);

                foreach ($mapped as $row) {
                    fputcsv($handle, [
                        $row['idventa'],
                        $row['fecha'],
                        $row['metodo'],
                        $row['vendedor'],
                        $row['concepto'],
                        $row['totalventa'],
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
                'vd.idprod',
                'vd.nombre as producto_nombre',
                'vd.proveedor as proveedor_ident',
                'vd.puni',
                'vd.cant',
                'vd.total as linea_total',
                'vd.descuento_producto',
                'vd.cargo_tarjeta_proveedor',
                'vd.proveedor_bruto',
                'vd.proveedor_neto',
                'vd.proveedor_descuento as linea_proveedor_descuento',
                'vd.admin_ganancia',
                'vd.promotion',
                'v.id as venta_id',
                'v.fecha as venta_fecha',
                'v.subtotal as venta_subtotal',
                'v.tarjeta_cargo as venta_tarjeta_cargo',
                'v.metodo as venta_metodo',
                'v.vendedor as venta_vendedor',
                'v.totalventa as venta_total',
                'p.id as proveedor_id',
                'p.ident as proveedor_ident_real',
                'p.nombre as proveedor_nombre',
                'p.tipo as proveedor_tipo',
                'p.porcentaje_comision as proveedor_porcentaje',
            ])
            ->leftJoin('ventas as v', 'v.idventa', '=', 'vd.idventa')
            ->leftJoin('proveedores as p', 'p.ident', '=', 'vd.proveedor');

        if ($provider) {
            $rows->where('vd.proveedor', '=', $provider->ident);
        }

        $rows->whereBetween('vd.fecha', [$inicioIso, $finIso]);
        $rows->orderBy('vd.fecha')->orderBy('vd.id');

        $collection = collect($rows->get());

        $generalDiscountTotal = 0.0;

        $grouped = $collection->groupBy(function ($row) {
            return $row->proveedor_ident !== null ? (string) $row->proveedor_ident : 'sin_proveedor';
        });

        $controller = $this;

        $providers = $grouped->map(function ($group) {
            $first = $group->first();
            $proveedorId = $first->proveedor_id ? (int) $first->proveedor_id : null;
            $proveedorIdent = $first->proveedor_ident !== null ? (string) $first->proveedor_ident : null;
            $proveedorNombre = $first->proveedor_nombre ?: ($proveedorIdent !== null ? "Proveedor {$proveedorIdent}" : 'Sin proveedor');
            $proveedorTipo = $first->proveedor_tipo ?: 'normal';
            $proveedorPorcentaje = $first->proveedor_porcentaje !== null ? (int) $first->proveedor_porcentaje : null;

            $details = $group->map(function ($row) use ($proveedorTipo) {
                $lineGross = round((float) ($row->linea_total ?? 0), 2);
                $ventaSubtotal = (float) ($row->venta_subtotal ?? 0);
                $ratio = ($ventaSubtotal > 0 && $lineGross > 0) ? $lineGross / $ventaSubtotal : 0.0;

                $lineProviderCharge = (float) ($row->cargo_tarjeta_proveedor ?? 0);
                if ($lineProviderCharge === 0.0) {
                    $providerChargeTotal = (float) ($row->venta_tarjeta_cargo ?? 0);
                    if ($providerChargeTotal !== 0.0 && $ratio > 0) {
                        $lineProviderCharge = round($providerChargeTotal * $ratio, 2);
                    }
                } else {
                    $lineProviderCharge = round($lineProviderCharge, 2);
                }

                $providerBruto = round((float) ($row->proveedor_bruto ?? 0), 2);
                $adminGanancia = round((float) ($row->admin_ganancia ?? 0), 2);
                $providerPct = $row->proveedor_porcentaje !== null ? (float) $row->proveedor_porcentaje : null;

                $providerTypeDiscount = 0.0;
                if (in_array($proveedorTipo, ['consigna', 'porcentaje'], true)) {
                    if ($providerBruto > 0 && $providerBruto <= $lineGross + 0.01) {
                        $providerTypeDiscount = round(max(0, $lineGross - $providerBruto), 2);
                    } elseif ($adminGanancia > 0) {
                        $providerTypeDiscount = $adminGanancia;
                    } elseif ($proveedorTipo === 'porcentaje' && $providerPct !== null) {
                        $percent = max(0, min(100, $providerPct));
                        $providerTypeDiscount = round($lineGross * ($percent / 100), 2);
                    }
                }

                $expectedEarning = round($lineGross - $lineProviderCharge - $providerTypeDiscount, 2);

                $fecha = $row->venta_fecha ?? $row->linea_fecha;
                $fechaIso = $fecha ? Carbon::parse($fecha)->toDateString() : null;

                return [
                    'ventadesg_id' => (int) $row->id,
                    'idventa' => (int) $row->idventa,
                    'venta_id' => $row->venta_id ? (int) $row->venta_id : null,
                    'fecha' => $fechaIso,
                    'fecha_raw' => $fechaIso,
                    'fecha_iso' => $fechaIso,
                    'producto_ident' => (string) $row->idprod,
                    'producto_nombre' => $row->producto_nombre,
                    'cantidad' => (int) ($row->cant ?? 0),
                    'precio_unitario' => round((float) ($row->puni ?? 0), 2),
                    'total' => $lineGross,
                    'card_fee' => $lineProviderCharge,
                    'provider_discount' => $providerTypeDiscount,
                    'expected_earning' => $expectedEarning,
                    'proveedor_tipo' => $proveedorTipo,
                    'proveedor_porcentaje' => $providerPct,
                    'metodo' => $row->venta_metodo,
                    'vendedor' => $row->venta_vendedor,
                    'venta_total' => round((float) ($row->venta_total ?? 0), 2),
                    'promotion' => $row->promotion,
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
                'tipo_descuento_total' => round($details->sum(fn ($item) => $item['provider_discount']), 2),
                'expected_earning' => round($details->sum(fn ($item) => $item['expected_earning']), 2),
                'items' => $details,
            ];
        })->values();

        $totales = [
            'ventas_brutas' => round($providers->sum(fn($row) => $row['total_vendido']), 2),
            'descuentos' => round($providers->sum(fn($row) => $row['tipo_descuento_total']), 2),
            'cargos_tarjeta' => round($providers->sum(fn($row) => $row['card_fee_total']), 2),
            'descuento_general' => $generalDiscountTotal,
            'ganancias' => round($providers->sum(fn($row) => $row['expected_earning']), 2),
        ];

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
                fputcsv($handle, ['Cargos por tarjeta', $totales['cargos_tarjeta']]);
                fputcsv($handle, ['Ganancia estimada', $totales['ganancias']]);
                fputcsv($handle, []);

                fputcsv($handle, [
                    'Proveedor ID',
                    'Proveedor Ident',
                    'Proveedor Nombre',
                    'Tipo',
                    'Porcentaje',
                    'Ventas brutas',
                    'Descuento por tipo',
                    'Cargos tarjeta',
                    'Ganancia estimada',
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
                        $prov['card_fee_total'],
                        $prov['expected_earning'],
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
                    'Cargo tarjeta',
                    'Ganancia estimada',
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
                            $item['card_fee'],
                            $item['expected_earning'],
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
