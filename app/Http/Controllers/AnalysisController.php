<?php

namespace App\Http\Controllers;

use App\Models\RecommendedImporte;
use App\Models\Usuario;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Arr;

class AnalysisController extends Controller
{
    public function summary(Request $request)
    {
        $this->ensureAdmin($request);

        $ventas = DB::table('historic_ventas');
        $desg = DB::table('historic_ventadesg');

        return response()->json([
            'ventas' => [
                'rows' => (int) $ventas->count(),
                'from' => $this->formatDate($ventas->min('fecha')),
                'to' => $this->formatDate($ventas->max('fecha')),
            ],
            'ventadesg' => [
                'rows' => (int) $desg->count(),
                'from' => $this->formatDate($desg->min('fecha')),
                'to' => $this->formatDate($desg->max('fecha')),
            ],
        ]);
    }

    public function topSellers(Request $request)
    {
        $this->ensureAdmin($request);

        $monthSelect = $this->monthExpression('fecha', true);

        $months = DB::table('historic_ventadesg')
            ->selectRaw("{$monthSelect} as month_alias")
            ->whereNotNull('fecha')
            ->groupByRaw("month_alias")
            ->orderBy('month_alias')
            ->get()
            ->map(function ($row) {
                $carbon = Carbon::parse($row->month_alias);
                return [
                    'key' => $carbon->format('Y-m'),
                    'label' => $carbon->translatedFormat('M Y'),
                    'iso' => $carbon->toDateString(),
                ];
            });

        if ($months->isEmpty()) {
            return response()->json([
                'months' => [],
                'rows' => [],
            ]);
        }

        $sales = $this->buildProviderMonthSalesQuery()->get();

        $monthKeys = $months->pluck('key')->all();

        $grouped = $sales->groupBy(function ($row) {
            return (string) ($row->proveedor_ident ?? 'sin-ident');
        });

        $rows = $grouped->map(function ($items) use ($monthKeys) {
            $first = $items->first();
            $totals = array_fill_keys($monthKeys, 0.0);
            foreach ($items as $item) {
                $key = Carbon::parse($item->month)->format('Y-m');
                $totals[$key] = round((float) $item->total, 2);
            }

            return [
                'provider_ident' => (string) ($first->proveedor_ident ?? ''),
                'provider_name' => $first->proveedor_nombre,
                'totals' => $totals,
                'grand_total' => round(array_sum($totals), 2),
            ];
        })->values()->sortByDesc('grand_total')->values();

        return response()->json([
            'months' => $months,
            'rows' => $rows,
        ]);
    }

    public function recommendedImportes(Request $request)
    {
        $this->ensureAdmin($request);

        $records = RecommendedImporte::orderByDesc('recommended_importe')->get();
        if ($records->isEmpty()) {
            return response()->json($this->rebuildRecommendedImportes());
        }

        return response()->json($this->formatStoredRecommendedResponse($records));
    }

    public function recalculateRecommendedImportes(Request $request)
    {
        $this->ensureAdmin($request);

        return response()->json($this->rebuildRecommendedImportes());
    }

    protected function rebuildRecommendedImportes(): array
    {
        $percentage = (float) SystemSettings::get('analysis_recommended_pct', '5');
        $monthsLimit = max(1, (int) SystemSettings::get('analysis_recommended_months', '12'));
        $periodEnd = Carbon::today()->endOfMonth();
        $periodStart = (clone $periodEnd)->subMonths($monthsLimit - 1)->startOfMonth();

        $providers = DB::table('proveedores')
            ->select(['id', 'ident', 'nombre', 'importe', 'email'])
            ->where('tipo', '=', 'normal')
            ->whereRaw('COALESCE(importe, 0) > 0')
            ->get()
            ->keyBy(fn ($row) => (string) $row->ident);

        if ($providers->isEmpty()) {
            RecommendedImporte::query()->delete();

            return [
                'items' => [],
                'settings' => [
                    'percentage' => $percentage,
                    'months' => $monthsLimit,
                    'from' => $periodStart->toDateString(),
                    'to' => $periodEnd->toDateString(),
                ],
            ];
        }

        $stats = $this->buildProviderAggregateQuery($periodStart, $periodEnd)
            ->get()
            ->keyBy(fn ($row) => (string) $row->proveedor_ident);

        $items = $providers->map(function ($provider, $ident) use ($stats, $percentage) {
            $stat = $stats->get((string) $ident);
            $months = $stat ? max(1, (int) $stat->months) : 1;
            $total = $stat ? (float) $stat->total : 0.0;
            $avgMonthly = $total / $months;

            $recommended = round($avgMonthly * ($percentage / 100), 2);
            $currentImporte = (float) ($provider->importe ?? 0);
            $isRecommended = $recommended >= $currentImporte || $currentImporte === 0.0;

            return [
                'provider_id' => $provider->id ?? null,
                'provider_ident' => (string) $ident,
                'provider_name' => $provider->nombre ?? 'Proveedor sin nombre',
                'provider_email' => $provider->email,
                'current_importe' => $currentImporte,
                'avg_monthly_sales' => round($avgMonthly, 2),
                'recommended_importe' => $recommended,
                'months' => $stat ? (int) $stat->months : 0,
                'total_sales' => round($total, 2),
                'is_recommended' => $isRecommended,
            ];
        })->values()->sortByDesc('recommended_importe')->values();

        $this->persistRecommendedImportes($items, $percentage, $monthsLimit, $periodStart, $periodEnd);

        return [
            'items' => $this->mapRecommendedItemsForResponse($items),
            'settings' => [
                'percentage' => $percentage,
                'months' => $monthsLimit,
                'from' => $periodStart->toDateString(),
                'to' => $periodEnd->toDateString(),
            ],
        ];
    }

    protected function mapRecommendedItemsForResponse($items): array
    {
        return $items->map(function ($row) {
            return Arr::except($row, ['provider_id']);
        })->values()->all();
    }

    protected function persistRecommendedImportes($items, float $percentage, int $monthsLimit, Carbon $periodStart, Carbon $periodEnd): void
    {
        DB::transaction(function () use ($items, $percentage, $monthsLimit, $periodStart, $periodEnd) {
            RecommendedImporte::query()->delete();

            if ($items->isEmpty()) {
                return;
            }

            $now = now();
            $payloads = $items->map(function ($row) use ($percentage, $monthsLimit, $periodStart, $periodEnd, $now) {
                return [
                    'proveedor_id' => $row['provider_id'] ?? null,
                    'provider_ident' => $row['provider_ident'],
                    'provider_name' => $row['provider_name'],
                    'provider_email' => $row['provider_email'] ?? null,
                    'current_importe' => $row['current_importe'],
                    'avg_monthly_sales' => $row['avg_monthly_sales'],
                    'recommended_importe' => $row['recommended_importe'],
                    'total_sales' => $row['total_sales'],
                    'months' => $row['months'],
                    'is_recommended' => $row['is_recommended'],
                    'percentage_used' => $percentage,
                    'months_window' => $monthsLimit,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();

            RecommendedImporte::insert($payloads);
        });
    }

    protected function formatStoredRecommendedResponse($records): array
    {
        if ($records->isEmpty()) {
            return [
                'items' => [],
                'settings' => [
                    'percentage' => (float) SystemSettings::get('analysis_recommended_pct', '5'),
                    'months' => max(1, (int) SystemSettings::get('analysis_recommended_months', '12')),
                    'from' => null,
                    'to' => null,
                ],
            ];
        }

        $items = $records->map(function (RecommendedImporte $record) {
            return [
                'provider_ident' => $record->provider_ident,
                'provider_name' => $record->provider_name,
                'provider_email' => $record->provider_email,
                'current_importe' => (float) $record->current_importe,
                'avg_monthly_sales' => (float) $record->avg_monthly_sales,
                'recommended_importe' => (float) $record->recommended_importe,
                'months' => (int) $record->months,
                'total_sales' => (float) $record->total_sales,
                'is_recommended' => (bool) $record->is_recommended,
            ];
        })->values()->all();

        $first = $records->first();
        $settings = [
            'percentage' => $first ? (float) $first->percentage_used : (float) SystemSettings::get('analysis_recommended_pct', '5'),
            'months' => $first ? (int) $first->months_window : max(1, (int) SystemSettings::get('analysis_recommended_months', '12')),
            'from' => $first && $first->period_start ? $first->period_start->toDateString() : null,
            'to' => $first && $first->period_end ? $first->period_end->toDateString() : null,
        ];

        return [
            'items' => $items,
            'settings' => $settings,
        ];
    }

    public function transitionReport(Request $request)
    {
        $this->ensureAdmin($request);

        $monthInput = $request->input('month', '2025-11');
        try {
            $start = Carbon::createFromFormat('Y-m', $monthInput)->startOfMonth();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Formato de mes inválido (usa YYYY-MM).'], 422);
        }

        $end = (clone $start)->endOfMonth();

        $ventas = $this->collectUnifiedVentas($start, $end);
        $lineItems = $this->collectUnifiedVentadesg($start, $end);

        $salesTotals = [
            'total_sales' => round((float) $ventas->sum('totalventa'), 2),
            'total_recibido' => round((float) $ventas->sum('total_recibido'), 2),
            'tickets' => $ventas->count(),
            'by_day' => $ventas
                ->groupBy('fecha')
                ->sortKeys()
                ->map(function ($group, $date) {
                    return [
                        'date' => $date,
                        'total' => round((float) $group->sum('totalventa'), 2),
                        'tickets' => $group->count(),
                    ];
                })
                ->values()
                ->all(),
        ];

        $caja = $ventas
            ->groupBy(function ($row) {
                return $row['metodo'] ?: 'desconocido';
            })
            ->map(function ($group, $metodo) {
                return [
                    'metodo' => $metodo,
                    'total_ventas' => round((float) $group->sum('totalventa'), 2),
                    'total_recibido' => round((float) $group->sum('total_recibido'), 2),
                    'cambio' => round((float) $group->sum('cambio'), 2),
                    'tickets' => $group->count(),
                ];
            })
            ->values()
            ->all();

        $providers = DB::table('proveedores')->select(['id', 'ident', 'nombre'])->get();
        $providersById = $providers->keyBy('id');
        $providersByIdent = $providers->keyBy(fn ($row) => (string) $row->ident);

        $condensed = [];
        foreach ($lineItems as $item) {
            $providerIdent = (string) ($item['provider_ident'] ?? '');
            $providerName = null;

            if ($item['proveedor_id'] && isset($providersById[$item['proveedor_id']])) {
                $providerIdent = (string) ($providersById[$item['proveedor_id']]->ident ?? $providerIdent);
                $providerName = $providersById[$item['proveedor_id']]->nombre ?? null;
            }

            if (!$providerName && $providerIdent !== '' && isset($providersByIdent[$providerIdent])) {
                $providerName = $providersByIdent[$providerIdent]->nombre;
            }

            $key = $providerIdent ?: 'sin-proveedor';
            if (!isset($condensed[$key])) {
                $condensed[$key] = [
                    'provider_ident' => $providerIdent ?: null,
                    'provider_name' => $providerName ?? 'Sin proveedor',
                    'legacy' => (bool) $item['legacy'],
                    'total_publico' => 0.0,
                    'total_neto' => 0.0,
                    'discounts' => 0.0,
                ];
            }

            $condensed[$key]['total_publico'] += (float) $item['public_total'];
            $condensed[$key]['total_neto'] += (float) $item['venta_total'];
            $discount = (float) $item['public_total'] - (float) $item['venta_total'];
            $condensed[$key]['discounts'] += max($discount, 0);
        }

        $cajaCondensado = collect($condensed)
            ->map(function ($row) {
                return [
                    'provider_ident' => $row['provider_ident'],
                    'provider_name' => $row['provider_name'],
                    'legacy' => $row['legacy'],
                    'total_publico' => round($row['total_publico'], 2),
                    'descuentos' => round($row['discounts'], 2),
                    'total_neto' => round($row['total_publico'] - $row['discounts'], 2),
                ];
            })
            ->values()
            ->all();

        $totals = [
            'total_publico' => round(array_sum(array_column($cajaCondensado, 'total_publico')), 2),
            'descuentos' => round(array_sum(array_column($cajaCondensado, 'descuentos')), 2),
        ];
        $totals['total_neto'] = round($totals['total_publico'] - $totals['descuentos'], 2);

        return response()->json([
            'range' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'sales' => $salesTotals,
            'caja' => $caja,
            'caja_condensado' => $cajaCondensado,
            'totals' => $totals,
        ]);
    }

    public function transitionProviderDetails(Request $request)
    {
        $this->ensureAdmin($request);
        Log::info($request->all());
        $monthInput = $request->input('month', '2025-11');
        $providerIdent = (string) $request->input('provider_ident', '');

        if ($providerIdent === '') {
            return response()->json(['message' => 'provider_ident es requerido.'], 422);
        }

        try {
            $start = Carbon::createFromFormat('Y-m', $monthInput)->startOfMonth();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Formato de mes inválido (usa YYYY-MM).'], 422);
        }

        $end = (clone $start)->endOfMonth();

        $lineItems = $this->collectUnifiedVentadesg($start, $end, $providerIdent);
        if ($lineItems->isEmpty()) {
            return response()->json([
                'items' => [],
            ]);
        }

        $ventaIds = $lineItems
            ->pluck('venta_id')
            ->filter(fn($id) => $id !== null && $id !== '')
            ->unique()
            ->values()
            ->all();

        $ventas = empty($ventaIds)
            ? collect()
            : $this->collectUnifiedVentas($start, $end, $ventaIds);

        $ventasLookup = $ventas->keyBy('venta_id');

        $lineItemsList = $lineItems->map(function ($row) use ($ventasLookup) {
            $venta = $ventasLookup->get($row['venta_id']);
            $discount = max(((float) ($row['public_total'] ?? 0)) - ((float) ($row['venta_total'] ?? 0)), 0);
            return [
                'venta_id' => $row['venta_id'],
                'fecha' => $row['fecha'],
                'producto_nombre' => $row['producto_nombre'],
                'producto_ident' => $row['producto_id'],
                'cantidad' => $row['quantity'] ?? 0,
                'metodo' => $venta['metodo'] ?? $row['metodo_pago'],
                'vendedor' => $venta['vendedor'] ?? $row['vendedor'],
                'monto' => (float) $row['venta_total'],
                'descuento' => $discount,
                'legacy' => $row['legacy'],
            ];
        })->values()->all();

        return response()->json([
            'items' => $lineItemsList,
        ]);
    }

    protected function collectUnifiedVentas(Carbon $start, Carbon $end, ?array $ventaIds = null)
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $idsFilter = null;
        if (is_array($ventaIds)) {
            $idsFilter = array_values(array_filter($ventaIds, fn($value) => $value !== null && $value !== ''));
            if (empty($idsFilter)) {
                return collect();
            }
        }

        $current = DB::table('ventas')
            ->select([
                'idventa as venta_id',
                'fecha',
                'hora',
                'totalventa',
                'total_recibido',
                'metodo',
                'cambio',
                'vendedor',
            ])
            ->whereBetween('fecha', [$startDate, $endDate]);

        if ($idsFilter !== null) {
            $current->whereIn('idventa', $idsFilter);
        }

        $current = $current
            ->get()
            ->map(function ($row) {
                return [
                    'venta_id' => $row->venta_id,
                    'fecha' => $row->fecha,
                    'hora' => $row->hora,
                    'totalventa' => (float) $row->totalventa,
                    'total_recibido' => (float) $row->total_recibido,
                    'metodo' => $row->metodo,
                    'cambio' => (float) $row->cambio,
                    'vendedor' => $row->vendedor,
                    'legacy' => false,
                ];
            });

        $legacyQuery = DB::table('historic_ventas')
            ->select([
                'legacy_idventa as venta_id',
                'fecha',
                DB::raw('NULL as hora'),
                'totalventa',
                'recibo',
                'metodo',
                'cambio',
                'vendedor',
            ])
            ->whereBetween('fecha', [$startDate, $endDate]);

        if ($idsFilter !== null) {
            $legacyQuery->whereIn('legacy_idventa', $idsFilter);
        }

        $legacyRaw = $legacyQuery->get();

        $legacy = $legacyRaw
            ->map(function ($row) {
                return [
                    'venta_id' => $row->venta_id,
                    'fecha' => $row->fecha,
                    'hora' => $row->hora,
                    'totalventa' => (float) $row->totalventa,
                    'total_recibido' => (float) $row->recibo,
                    'metodo' => $row->metodo,
                    'cambio' => (float) $row->cambio,
                    'vendedor' => $row->vendedor,
                    'legacy' => true,
                ];
            });

        if ($idsFilter !== null) {
            return $current->concat($legacy);
        }

        return $current->concat($legacy);
    }

    protected function collectUnifiedVentadesg(Carbon $start, Carbon $end, ?string $providerIdentFilter = null)
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $providerIdentFilter = $providerIdentFilter !== null ? trim($providerIdentFilter) : null;
        if ($providerIdentFilter === '') {
            $providerIdentFilter = null;
        }

        $providers = DB::table('proveedores')
            ->select(['id', 'ident'])
            ->get();
        $providersById = $providers->keyBy('id');
        $providersByIdent = $providers
            ->filter(fn($row) => $row->ident !== null && $row->ident !== '')
            ->keyBy(fn($row) => (string) $row->ident);

        $targetProviderId = null;
        if ($providerIdentFilter !== null) {
            $match = $providersByIdent->get($providerIdentFilter);
            if ($match) {
                $targetProviderId = $match->id;
            }
        }

        $currentQuery = DB::table('ventadesg as vd')
            ->leftJoin('ventas as v', 'v.idventa', '=', 'vd.idventa')
            ->leftJoin('proveedores as pr', 'pr.id', '=', 'vd.proveedor_id')
            ->select([
                'vd.idventa as venta_id',
                'vd.fecha',
                'vd.hora',
                'vd.proveedor_id',
                'pr.ident as provider_ident',
                'vd.producto_id',
                'vd.nombre as producto_nombre',
                'v.metodo as metodo_pago',
                'v.vendedor',
                'vd.public_total',
                'vd.venta_total',
                'vd.provider_payment',
                'vd.provider_cost',
                'vd.admin_earnings',
                'vd.credit_card_discount',
                'vd.provider_percentage_discount',
                'vd.consigna_discount',
                'vd.quantity',
            ])
            ->whereBetween('vd.fecha', [$startDate, $endDate]);

        if ($providerIdentFilter !== null) {
            $currentQuery->where(function ($query) use ($providerIdentFilter, $targetProviderId) {
                if ($targetProviderId !== null) {
                    $query->where('vd.proveedor_id', '=', $targetProviderId)
                        ->orWhere('pr.ident', '=', $providerIdentFilter);
                } else {
                    $query->where('pr.ident', '=', $providerIdentFilter);
                }
            });
        }

        $current = $currentQuery
            ->get()
            ->map(function ($row) use ($providersById) {
                $providerIdent = $row->provider_ident !== null ? (string) $row->provider_ident : null;

                if ($providerIdent === null && $row->proveedor_id !== null) {
                    $provider = $providersById->get($row->proveedor_id);
                    if ($provider && $provider->ident !== null) {
                        $providerIdent = (string) $provider->ident;
                    }
                }

                return [
                    'venta_id' => $row->venta_id,
                    'fecha' => $row->fecha,
                    'hora' => $row->hora,
                    'proveedor_id' => $row->proveedor_id,
                    'provider_ident' => $providerIdent,
                    'producto_id' => $row->producto_id,
                    'producto_nombre' => $row->producto_nombre,
                    'metodo_pago' => $row->metodo_pago,
                    'vendedor' => $row->vendedor,
                    'public_total' => (float) $row->public_total,
                    'venta_total' => (float) $row->venta_total,
                    'provider_payment' => (float) $row->provider_payment,
                    'provider_cost' => (float) $row->provider_cost,
                    'admin_earnings' => (float) $row->admin_earnings,
                    'credit_card_discount' => (float) $row->credit_card_discount,
                    'provider_percentage_discount' => (float) $row->provider_percentage_discount,
                    'consigna_discount' => (float) $row->consigna_discount,
                    'quantity' => (float) $row->quantity,
                    'legacy' => false,
                ];
            });

        $legacyQuery = DB::table('historic_ventadesg')
            ->select([
                'venta_legacy_id as venta_id',
                'fecha',
                'proveedor_ident',
                'producto_ident',
                'producto_nombre',
                'total',
                'total_descuento',
                'cantidad',
            ])
            ->whereBetween('fecha', [$startDate, $endDate]);

        if ($providerIdentFilter !== null) {
            $legacyQuery->where('proveedor_ident', '=', $providerIdentFilter);
        }
        
        $legacy = $legacyQuery
            ->get()
            ->map(function ($row) {
                $total = (float) ($row->total ?? 0);
                $discount = (float) ($row->total_descuento ?? 0);
                $ventaTotal = $total - $discount;

                return [
                    'venta_id' => $row->venta_id,
                    'fecha' => $row->fecha,
                    'hora' => null,
                    'proveedor_id' => null,
                    'provider_ident' => $row->proveedor_ident,
                    'producto_id' => $row->producto_ident,
                    'producto_nombre' => $row->producto_nombre,
                    'metodo_pago' => null,
                    'vendedor' => null,
                    'public_total' => $total,
                    'venta_total' => $ventaTotal,
                    'provider_payment' => 0.0,
                    'provider_cost' => 0.0,
                    'admin_earnings' => $ventaTotal,
                    'credit_card_discount' => 0.0,
                    'provider_percentage_discount' => 0.0,
                    'consigna_discount' => 0.0,
                    'quantity' => (float) ($row->cantidad ?? 0),
                    'legacy' => true,
                ];
            });


        $result = $current->concat($legacy);

    

        return $result;
    }

    public function topProducts(Request $request)
    {
        $this->ensureAdmin($request);

        $months = (int) $request->input('months', 3);
        $allowed = [3, 6, 9];
        if (!in_array($months, $allowed, true)) {
            return response()->json(['message' => 'El parámetro months debe ser 3, 6 o 9.'], 422);
        }

        $end = Carbon::today()->endOfDay();
        $start = (clone $end)->subMonths($months)->startOfDay();

        $itemsQuery = DB::table('historic_ventadesg as hv');
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $itemsQuery->leftJoin('proveedores as pr', 'pr.ident', '=', DB::raw('CAST(hv.proveedor_ident AS INTEGER)'));
        } else {
            $itemsQuery->leftJoin('proveedores as pr', 'pr.ident', '=', DB::raw('CAST(hv.proveedor_ident AS UNSIGNED)'));
        }

        $items = $itemsQuery
            ->selectRaw('hv.producto_ident, hv.producto_nombre, COALESCE(pr.nombre, \'Proveedor sin nombre\') as proveedor_nombre, SUM(COALESCE(hv.cantidad, 0)) as total_quantity, SUM(COALESCE(hv.total, 0)) as total_amount')
            ->whereNotNull('hv.fecha')
            ->whereBetween('hv.fecha', [$start->toDateString(), $end->toDateString()])
            ->groupBy('hv.producto_ident', 'hv.producto_nombre', 'proveedor_nombre')
            ->orderByDesc(DB::raw('SUM(COALESCE(hv.cantidad, 0))'))
            ->limit(20)
            ->get()
            ->map(function ($row) {
                return [
                    'producto_ident' => $row->producto_ident,
                    'producto_nombre' => $row->producto_nombre,
                    'proveedor_nombre' => $row->proveedor_nombre,
                    'total_quantity' => (float) $row->total_quantity,
                    'total_amount' => (float) $row->total_amount,
                ];
            });

        return response()->json([
            'range' => [
                'months' => $months,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'items' => $items,
        ]);
    }

    public function applyRecommendedImport(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'provider_ident' => ['required', 'string'],
            'importe' => ['required', 'numeric', 'min:0'],
            'accepted' => ['required', 'boolean'],
            'send_email' => ['sometimes', 'boolean'],
            'email' => ['nullable', 'email'],
        ]);

        if (!$data['accepted']) {
            return response()->json(['message' => 'Debe confirmar que el proveedor aceptó el nuevo importe.'], 422);
        }

        $provider = DB::table('proveedores')->where('ident', $data['provider_ident'])->first();
        if (!$provider) {
            return response()->json(['message' => 'Proveedor no encontrado.'], 404);
        }

        DB::table('proveedores')->where('ident', $data['provider_ident'])->update([
            'importe' => $data['importe'],
        ]);

        $sendEmail = filter_var($data['send_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($sendEmail) {
            $email = $data['email'] ?? $provider->email;
            if (!$email) {
                return response()->json(['message' => 'Debe proporcionar un correo electrónico para enviar la confirmación.'], 422);
            }

            Mail::raw("Se ha actualizado el importe mensual a " . number_format($data['importe'], 2) . " MXN.", function ($message) use ($email, $provider) {
                $message->to($email)
                    ->subject('Confirmación de nuevo importe - ' . ($provider->nombre ?? 'Proveedor'));
            });
        }

        RecommendedImporte::where('provider_ident', $data['provider_ident'])->update([
            'current_importe' => $data['importe'],
            'is_recommended' => true,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Importe actualizado correctamente.']);
    }

    public function monthDetails(Request $request)
    {
        $this->ensureAdmin($request);

        $request->validate([
            'provider_ident' => ['required', 'string'],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $providerIdent = $request->input('provider_ident');
        $monthKey = $request->input('month');
        $start = Carbon::createFromFormat('Y-m', $monthKey)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $provider = DB::table('proveedores')
            ->where('ident', $providerIdent)
            ->first();

        $items = DB::table('historic_ventadesg as hv')
            ->select([
                'hv.producto_ident',
                'hv.producto_nombre',
                DB::raw('SUM(hv.cantidad) as cantidad'),
                DB::raw('SUM(hv.total) as total'),
            ])
            ->whereBetween('hv.fecha', [$start->toDateString(), $end->toDateString()])
            ->where('hv.proveedor_ident', '=', $providerIdent)
            ->groupBy('hv.producto_ident', 'hv.producto_nombre')
            ->orderByDesc(DB::raw('SUM(hv.total)'))
            ->get()
            ->map(function ($row) {
                return [
                    'producto_ident' => $row->producto_ident,
                    'producto_nombre' => $row->producto_nombre,
                    'cantidad' => (float) $row->cantidad,
                    'total' => (float) $row->total,
                ];
            });

        $totals = [
            'cantidad' => $items->sum('cantidad'),
            'monto' => $items->sum('total'),
        ];

        return response()->json([
            'month' => $monthKey,
            'provider_ident' => $providerIdent,
            'provider_name' => $provider->nombre ?? 'Proveedor sin nombre',
            'items' => $items,
            'totals' => $totals,
        ]);
    }

    public function import(Request $request)
    {
        $this->ensureAdmin($request);
        Log::error('Inicio de ejecucion');
        try {
            $data = $request->validate([
                'type' => ['required', Rule::in(['ventas', 'ventadesg'])],
                'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            ], [
                'file.mimes' => 'Sube un archivo CSV válido.',
            ]);
        } catch (ValidationException $e) {
            Log::error('Historic import validation failed', [
                'errors' => $e->errors(),
                'type' => $request->input('type'),
                'file_present' => $request->hasFile('file'),
            ]);
            throw $e;
        }
        Log::error('Data validation passed');
        $path = $request->file('file')->store('analysis_uploads');
        $fullPath = Storage::path($path);

        try {
            $imported = $data['type'] === 'ventas'
                ? $this->importVentas($fullPath)
                : $this->importVentasDesg($fullPath);
        } catch (\Throwable $e) {
            Log::error('Historic data import failed', [
                'type' => $data['type'],
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw ValidationException::withMessages([
                'file' => 'No se pudo importar el archivo: ' . $e->getMessage(),
            ]);
        } finally {
            Storage::delete($path);
        }

        return response()->json([
            'message' => "Se importaron {$imported} filas.",
        ]);
    }

    private function importVentas(string $path): int
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            Log::error('historic_ventas import: unable to open file', ['path' => $path]);
            throw new \RuntimeException('No se pudo leer el archivo.');
        }

        $count = 0;
        $rows = [];
        $now = now();
        $headerSkipped = false;

        while (($columns = fgetcsv($handle)) !== false) {
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }

            if (count($columns) < 10) {
                Log::warning('historic_ventas import: skipping row due to insufficient columns', [
                    'columns' => $columns,
                ]);
                continue;
            }

            $rows[] = [
                'legacy_id' => (int) $columns[0],
                'legacy_idventa' => $this->toInt($columns[1]),
                'totalventa' => $this->toDecimal($columns[2]),
                'metodo' => $this->cleanString($columns[3]),
                'recibo' => $this->toDecimal($columns[4]),
                'cambio' => $this->toDecimal($columns[5]),
                'vendedor' => $this->cleanString($columns[6]),
                'fecha' => $this->parseDate($columns[7]),
                'ie' => $this->cleanString($columns[8]),
                'concepto' => $this->cleanString($columns[9]),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= 500) {
                $this->persistChunk('historic_ventas', $rows);
                $count += count($rows);
                $rows = [];
            }
        }

        if (!empty($rows)) {
            $this->persistChunk('historic_ventas', $rows);
            $count += count($rows);
        }

        fclose($handle);

        return $count;
    }

    private function importVentasDesg(string $path): int
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            Log::error('historic_ventadesg import: unable to open file', ['path' => $path]);
            throw new \RuntimeException('No se pudo leer el archivo.');
        }

        $count = 0;
        $rows = [];
        $now = now();
        $headerSkipped = false;

        while (($columns = fgetcsv($handle)) !== false) {
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }

            if (count($columns) < 11) {
                Log::warning('historic_ventadesg import: skipping row due to insufficient columns', [
                    'columns' => $columns,
                ]);
                continue;
            }

            $rows[] = [
                'legacy_id' => (int) $columns[0],
                'venta_legacy_id' => $this->toInt($columns[1]),
                'fecha' => $this->parseDate($columns[2]),
                'producto_ident' => $this->cleanString($columns[3]),
                'producto_nombre' => $this->cleanString($columns[4]),
                'proveedor_ident' => $this->cleanString($columns[5]),
                'precio_unitario' => $this->toDecimal($columns[6]),
                'cantidad' => $this->toDecimal($columns[7]),
                'total' => $this->toDecimal($columns[8]),
                'total_descuento' => $this->toDecimal($columns[9]),
                'hora' => $this->cleanString($columns[10]),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= 500) {
                $this->persistChunk('historic_ventadesg', $rows);
                $count += count($rows);
                $rows = [];
            }
        }

        if (!empty($rows)) {
            $this->persistChunk('historic_ventadesg', $rows);
            $count += count($rows);
        }

        fclose($handle);

        return $count;
    }

    private function persistChunk(string $table, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $first = $rows[0];
        $updateColumns = array_values(array_filter(array_keys($first), function ($column) {
            return $column !== 'legacy_id';
        }));

        try {
            DB::table($table)->upsert($rows, ['legacy_id'], $updateColumns);
        } catch (\Throwable $e) {
            Log::error('Historic data chunk insert failed', [
                'table' => $table,
                'rows' => count($rows),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function parseDate(?string $value): ?string
    {
        $value = $this->cleanString($value);
        if (!$value) {
            return null;
        }

        $formats = ['n/j/y', 'n/j/Y', 'd/m/Y', 'Y-m-d'];
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->toDateString();
            } catch (\Throwable $e) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function toDecimal($value): float
    {
        $value = $this->cleanString($value);
        if ($value === '' || $value === null) {
            return 0.0;
        }
        $value = str_replace(['$', ','], '', $value);
        return (float) $value;
    }

    private function toInt($value): ?int
    {
        $value = $this->cleanString($value);
        if ($value === '' || $value === null) {
            return null;
        }
        return (int) $value;
    }

    private function cleanString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim(preg_replace("/\s+/", ' ', (string) $value) ?? '');
        return $trimmed === '' ? null : $trimmed;
    }

    private function formatDate($value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildProviderMonthSalesQuery()
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $monthExpr = "DATE_TRUNC('month', hv.fecha::timestamp)::date";
            return DB::table('historic_ventadesg as hv')
                ->selectRaw("hv.proveedor_ident, COALESCE(pr.nombre, 'Proveedor sin nombre') as proveedor_nombre, {$monthExpr} as month, SUM(hv.total) as total")
                ->leftJoin('proveedores as pr', 'pr.ident', '=', DB::raw('hv.proveedor_ident::int'))
                ->whereNotNull('hv.fecha')
                ->groupByRaw("hv.proveedor_ident, pr.nombre, {$monthExpr}")
                ->orderBy('month');
        }

        $monthExpr = "STR_TO_DATE(DATE_FORMAT(STR_TO_DATE(hv.fecha, '%Y-%m-%d'), '%Y-%m-01'), '%Y-%m-%d')";
        return DB::table('historic_ventadesg as hv')
            ->selectRaw("hv.proveedor_ident, COALESCE(pr.nombre, 'Proveedor sin nombre') as proveedor_nombre, {$monthExpr} as month, SUM(hv.total) as total")
            ->leftJoin('proveedores as pr', function ($join) {
                $join->on('pr.ident', '=', DB::raw('CAST(hv.proveedor_ident AS UNSIGNED)'));
            })
            ->whereNotNull('hv.fecha')
            ->groupByRaw("hv.proveedor_ident, COALESCE(pr.nombre, 'Proveedor sin nombre'), {$monthExpr}")
            ->orderBy('month');
    }

    private function buildProviderAggregateQuery(Carbon $start, Carbon $end)
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $monthExpr = "DATE_TRUNC('month', fecha::timestamp)";
            return DB::table('historic_ventadesg')
                ->selectRaw("proveedor_ident, SUM(total) as total, COUNT(DISTINCT {$monthExpr}) as months")
                ->whereNotNull('proveedor_ident')
                ->whereBetween('fecha', [$start->toDateString(), $end->toDateString()])
                ->groupBy('proveedor_ident');
        }

        $monthExpr = "DATE_FORMAT(STR_TO_DATE(fecha, '%Y-%m-%d'), '%Y-%m-01')";
        return DB::table('historic_ventadesg')
            ->selectRaw("proveedor_ident, SUM(total) as total, COUNT(DISTINCT {$monthExpr}) as months")
            ->whereNotNull('proveedor_ident')
            ->whereBetween('fecha', [$start->toDateString(), $end->toDateString()])
            ->groupBy('proveedor_ident');
    }

    private function monthExpression(string $column, bool $castToDate = false): string
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $expr = "DATE_TRUNC('month', {$column}::timestamp)";
            return $castToDate ? "({$expr})::date" : $expr;
        }

        $parsed = "STR_TO_DATE({$column}, '%Y-%m-%d')";
        $expr = "DATE_FORMAT({$parsed}, '%Y-%m-01')";
        if ($castToDate) {
            return "STR_TO_DATE({$expr}, '%Y-%m-%d')";
        }

        return $expr;
    }

    private function ensureAdmin(Request $request): void
    {
        if (!($request->user() instanceof Usuario)) {
            abort(403, 'Solo administradores pueden acceder a esta sección.');
        }
    }
}
