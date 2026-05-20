<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\VentaDesg;
use App\Models\Producto;
use App\Models\Inventario;
use App\Models\Entrada;
use App\Models\Egreso;
use App\Models\Mailer;
use App\Models\Proveedor;
use App\Models\Mensualidad;
use App\Models\DailyCashSummary;
use App\Models\VentaCancelacion;
use App\Models\ProviderRestockForecast;
use App\Models\Usuario;
use App\Mail\RestockForecastMail;
use App\Support\ProductSalesAggregator;
use App\Support\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Carbon\CarbonPeriod;

class ReportController extends Controller
{
    private const RESTOCK_HORIZONS = ['2w', '4w', '6w'];
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

    public function restockForecast(Request $request)
    {
        $provider = $request->input('provider');
        $forecastDateInput = $request->input('forecast_date');
        $horizon = $this->resolveRestockHorizon($request);

        $query = ProviderRestockForecast::query();

        if ($forecastDateInput) {
            try {
                $forecastDate = $this->parseDateInput($forecastDateInput)->toDateString();
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Formato de fecha inválido.'], 422);
            }
        } else {
            $forecastDate = ProviderRestockForecast::where('horizon', $horizon)->max('forecast_date');
        }

        if (!$forecastDate) {
            return response()->json([
                'message' => 'No hay pronósticos registrados. Ejecuta el comando restock:forecast primero.',
            ], 404);
        }

        $query->whereDate('forecast_date', '=', $forecastDate)
            ->where('horizon', '=', $horizon);

        if ($provider) {
            $query->where('provider_ident', '=', $provider);
        }

        $rows = $query
            ->orderByDesc('suggested_order_qty')
            ->orderBy('provider_ident')
            ->get();

        $productIdents = $rows->pluck('producto_ident')
            ->filter()
            ->unique()
            ->values();

        $inventoryMap = $productIdents->isEmpty()
            ? collect()
            : DB::table('inventario as inv')
                ->select(['inv.ident', 'inv.existencia'])
                ->whereIn('inv.ident', $productIdents)
                ->get()
                ->keyBy(fn ($row) => (string) $row->ident);

        $providerIdents = $rows->pluck('provider_ident')
            ->filter(fn ($ident) => $ident !== null && $ident !== '')
            ->unique()
            ->values();

        $providerMap = $providerIdents->isEmpty()
            ? collect()
            : Proveedor::whereIn('ident', $providerIdents)->get()->keyBy('ident');

        $freshAverages = $this->computeFreshAverageSales($rows);
        $minimumDays = (int) SystemSettings::get('restock_min_days', 14);
        $leadTimeDays = $rows->first()?->lead_time_days ?? 0;

        $forecastCarbon = Carbon::parse($forecastDate);

        $items = $rows->map(function (ProviderRestockForecast $row) use ($providerMap, $inventoryMap, $freshAverages, $minimumDays, $forecastCarbon) {
            $provider = $row->provider_ident ? $providerMap->get($row->provider_ident) : null;
            $currentInventory = $inventoryMap->get($row->producto_ident);
            $inventoryOnHand = $currentInventory ? (int) $currentInventory->existencia : (int) $row->inventory_on_hand;
            $avgKey = $this->avgKey($row->provider_ident, $row->producto_ident, (int) $row->lookback_days);
            $avgDaily = $freshAverages[$avgKey] ?? (float) $row->avg_daily_sales;
            $daysOfCover = $avgDaily > 0 ? round($inventoryOnHand / max($avgDaily, 0.0001), 2) : null;
            $requiredDays = max(1, (int) $row->lead_time_days) + $minimumDays;
            $requiredUnits = $avgDaily * $requiredDays;
            $recommendedInventory = (int) max(0, ceil($requiredUnits));
            $suggested = (int) max(0, $recommendedInventory - $inventoryOnHand);
            $dueDate = $forecastCarbon->copy()->addDays(max(1, (int) $row->lead_time_days))->toDateString();
            $restockAsap = $inventoryOnHand < 5;

            return [
                'provider_ident' => $row->provider_ident,
                'provider_name' => $row->provider_name,
                'provider_email' => $provider?->email,
                'producto_ident' => $row->producto_ident,
                'producto_nombre' => $row->producto_nombre,
                'avg_daily_sales' => $avgDaily,
                'inventory_on_hand' => $inventoryOnHand,
                'projected_demand' => (float) $row->projected_demand,
                'recommended_inventory' => $recommendedInventory,
                'suggested_order_qty' => $suggested,
                'days_of_cover' => $daysOfCover,
                'lead_time_days' => (int) $row->lead_time_days,
                'lookback_days' => (int) $row->lookback_days,
                'restock_by_date' => $dueDate,
                'restock_asap' => $restockAsap,
            ];
        })->values();

        $summary = [
            'total_items' => $items->count(),
            'total_suggested' => $items->sum('suggested_order_qty'),
            'avg_daily_sales' => round($items->avg('avg_daily_sales'), 2),
        ];

        $first = $items->first();
        $lookback = $first['lookback_days'] ?? 30;
        $leadTime = $first['lead_time_days'] ?? 7;
        $minimumDays = (int) SystemSettings::get('restock_min_days', 14);

        return response()->json([
            'forecast_date' => $forecastDate,
            'horizon' => $horizon,
            'lookback_days' => $lookback,
            'lead_time_days' => $leadTime,
            'minimum_inventory_days' => $minimumDays,
            'summary' => $summary,
            'items' => $items,
        ]);
    }

    public function updateRestockPreference(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof Usuario) {
            return response()->json(['message' => 'Solo usuarios administradores pueden actualizar esta preferencia.'], 403);
        }

        $horizon = $this->normalizeRestockHorizon($request->input('horizon'));
        if (!$horizon) {
            return response()->json(['message' => 'Horizonte inválido. Usa 2w, 4w o 6w.'], 422);
        }

        $user->restock_horizon = $horizon;
        $user->save();

        return response()->json([
            'horizon' => $horizon,
        ]);
    }

    public function restockForecastNotify(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof Usuario) {
            return response()->json(['message' => 'Solo administradores pueden notificar a los proveedores.'], 403);
        }

        $data = $request->validate([
            'horizon' => ['required', 'string'],
            'providers' => ['sometimes', 'array', 'min:1'],
            'providers.*' => ['string'],
        ]);

        $horizon = $this->normalizeRestockHorizon($data['horizon']);
        if (!$horizon) {
            return response()->json(['message' => 'Horizonte inválido. Usa 2w, 4w o 6w.'], 422);
        }
        $forecastDate = ProviderRestockForecast::where('horizon', $horizon)->max('forecast_date');

        if (!$forecastDate) {
            return response()->json([
                'message' => 'No hay pronósticos registrados para este horizonte. Ejecuta primero restock:forecast.',
            ], 404);
        }

        $includeZero = filter_var(SystemSettings::get('restock_include_zero', '0'), FILTER_VALIDATE_BOOL);
        $operator = $includeZero ? '>=' : '>';

        $query = ProviderRestockForecast::query()
            ->where('horizon', $horizon)
            ->whereDate('forecast_date', $forecastDate)
            ->where('suggested_order_qty', $operator, 0);

        if (!empty($data['providers'])) {
            $query->whereIn('provider_ident', $data['providers']);
        }

        $rows = $query->orderBy('provider_ident')->orderByDesc('suggested_order_qty')->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'message' => 'No hay productos con sugerencias para notificar.',
            ], 422);
        }

        $providerIdents = $rows->pluck('provider_ident')
            ->filter(fn ($ident) => $ident !== null && $ident !== '')
            ->unique()
            ->values();

        $providers = $providerIdents->isEmpty()
            ? collect()
            : Proveedor::whereIn('ident', $providerIdents)->get()->keyBy('ident');

        $freshAverages = $this->computeFreshAverageSales($rows);
        $minimumDays = (int) SystemSettings::get('restock_min_days', 14);
        $sent = [];
        $skipped = [];
        $horizonLabel = $this->humanRestockHorizon($horizon);

        foreach ($rows->groupBy('provider_ident') as $ident => $group) {
            $providerModel = $ident ? $providers->get($ident) : null;
            $providerName = $providerModel->nombre ?? ($group->first()->provider_name ?? 'Proveedor sin nombre');

            if (!$providerModel || empty($providerModel->email)) {
                $skipped[] = [
                    'provider_ident' => $ident,
                    'provider_name' => $providerName,
                    'reason' => 'missing_email',
                ];
                continue;
            }

            $items = $group->map(function (ProviderRestockForecast $row) use ($freshAverages, $minimumDays) {
                $avgKey = $this->avgKey($row->provider_ident, $row->producto_ident, (int) $row->lookback_days);
                $avgDaily = $freshAverages[$avgKey] ?? (float) $row->avg_daily_sales;
                $requiredDays = max(1, (int) $row->lead_time_days) + $minimumDays;
                $recommendedInventory = (int) max(0, ceil($avgDaily * $requiredDays));
                $suggested = (int) max(0, $recommendedInventory - (int) $row->inventory_on_hand);

                return [
                    'producto_ident' => $row->producto_ident,
                    'producto_nombre' => $row->producto_nombre,
                    'suggested_order_qty' => $suggested,
                    'avg_daily_sales' => $avgDaily,
                    'inventory_on_hand' => (int) $row->inventory_on_hand,
                    'projected_demand' => (float) $row->projected_demand,
                    'recommended_inventory' => $recommendedInventory,
                    'days_of_cover' => $row->days_of_cover !== null ? (float) $row->days_of_cover : null,
                    'lead_time_days' => (int) $row->lead_time_days,
                ];
            })->values()->all();

            $mailSubject = sprintf(
                'Pronóstico de resurtido (%s) - %s',
                $horizonLabel,
                $providerModel->nombre ?? 'Proveedor'
            );

            $mailViewData = [
                'provider' => $providerModel,
                'horizonLabel' => $horizonLabel,
                'forecastDate' => $forecastDate,
                'items' => $items,
            ];

            Mail::to($providerModel->email)->send(
                new RestockForecastMail($providerModel, $horizonLabel, $forecastDate, $items)
            );

            $body = $this->sanitizeEmailBody(view('emails.restock_forecast', $mailViewData)->render());

            Mailer::create([
                'mail' => 'restock_forecast_' . $horizon,
                'email' => $providerModel->email,
                'asunto' => $mailSubject,
                'mensaje' => $body,
                'status' => 1,
                'fecha' => now()->toDateString(),
            ]);

            $sent[] = [
                'provider_ident' => (string) $providerModel->ident,
                'provider_name' => $providerModel->nombre,
                'email' => $providerModel->email,
            ];
        }

        return response()->json([
            'forecast_date' => $forecastDate,
            'horizon' => $horizon,
            'sent' => count($sent),
            'skipped' => count($skipped),
            'providers_notified' => $sent,
            'providers_skipped' => $skipped,
            'message' => 'Notificaciones enviadas.',
        ]);
    }

    private function resolveRestockHorizon(Request $request, string $default = '2w'): string
    {
        $input = $this->normalizeRestockHorizon($request->input('horizon'));
        if ($input) {
            return $input;
        }

        $user = $request->user();
        if ($user instanceof Usuario) {
            $pref = $this->normalizeRestockHorizon($user->restock_horizon ?? null);
            if ($pref) {
                return $pref;
            }
        }

        return $default;
    }

    private function humanRestockHorizon(string $horizon): string
    {
        return match ($horizon) {
            '2w' => 'próximas 2 semanas',
            '4w' => 'próximas 4 semanas',
            '6w' => 'próximas 6 semanas',
            default => 'próximas 2 semanas',
        };
    }

    private function normalizeRestockHorizon($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim((string) $value));
        $map = [
            'day' => '2w',
            'week' => '4w',
            'month' => '6w',
            '2w' => '2w',
            '4w' => '4w',
            '6w' => '6w',
            '2weeks' => '2w',
            '4weeks' => '4w',
            '6weeks' => '6w',
        ];

        return $map[$value] ?? (in_array($value, self::RESTOCK_HORIZONS, true) ? $value : null);
    }

    public function mensualidad(Request $request)
    {
        $baseQuery = Mensualidad::query();

        $mapMensualidad = function (Mensualidad $mensualidad) {
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
        };

        $orderedQuery = (clone $baseQuery)
            ->with(['proveedor:id,nombre,email'])
            ->orderByDesc('mes_cobro')
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        if ($request->boolean('download')) {
            $rows = $orderedQuery->get()->map($mapMensualidad);

            $filename = 'reporte_mensualidad_completo.csv';

            return response()->streamDownload(function () use ($rows) {
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

                foreach ($rows as $row) {
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

        $summaryRow = (clone $baseQuery)
            ->selectRaw('COUNT(*) as total_cobros')
            ->selectRaw('COALESCE(SUM(importe), 0) as importe_total')
            ->selectRaw('COALESCE(SUM(cantidad_pago), 0) as pagado_total')
            ->selectRaw('COALESCE(SUM(restante), 0) as restante_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN pago_completo THEN 1 ELSE 0 END), 0) as pagos_completos')
            ->first();

        $mensualidades = $orderedQuery->get();
        $mapped = $mensualidades->map($mapMensualidad)->values();

        return response()->json([
            'summary' => [
                'total_cobros' => (int) ($summaryRow->total_cobros ?? 0),
                'importe_total' => round((float) ($summaryRow->importe_total ?? 0), 2),
                'pagado_total' => round((float) ($summaryRow->pagado_total ?? 0), 2),
                'restante_total' => round((float) ($summaryRow->restante_total ?? 0), 2),
                'pagos_completos' => (int) ($summaryRow->pagos_completos ?? 0),
            ],
            'items' => $mapped,
        ]);
    }

    public function proveedoresEliminados(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $query = Proveedor::onlyTrashed()
            ->with(['productos' => function ($q) {
                $q->onlyTrashed()
                    ->with(['inventario:id,ident,existencia'])
                    ->orderBy('nombre')
                    ->orderBy('ident');
            }])
            ->orderByDesc('deleted_at')
            ->orderBy('nombre');

        if ($search !== '') {
            $like = '%' . strtolower($search) . '%';
            $query->where(function ($q) use ($like, $search) {
                $q->whereRaw('LOWER(nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(tel, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(delete_reason, \'\')) LIKE ?', [$like])
                    ->orWhereHas('productos', function ($productQuery) use ($like, $search) {
                        $productQuery->onlyTrashed()
                            ->where(function ($inner) use ($like, $search) {
                                $inner->whereRaw('LOWER(nombre) LIKE ?', [$like])
                                    ->orWhereRaw('LOWER(COALESCE(descripcion, \'\')) LIKE ?', [$like]);

                                if (is_numeric($search)) {
                                    $inner->orWhere('ident', (int) $search);
                                }
                            });
                    });

                if (is_numeric($search)) {
                    $q->orWhere('ident', (int) $search);
                }
            });
        }

        $providers = $query->get();

        $items = $providers->map(function (Proveedor $proveedor) {
            $products = $proveedor->productos->map(function (Producto $product) {
                $quantity = (int) optional($product->inventario)->existencia;
                $publicPrice = (float) ($product->precio ?? 0);
                $providerPrice = (float) ($product->precio_proveedor ?? 0);

                return [
                    'id' => (int) $product->id,
                    'ident' => (int) $product->ident,
                    'nombre' => $product->nombre,
                    'descripcion' => $product->descripcion,
                    'cantidad' => $quantity,
                    'existencia' => $quantity,
                    'precio' => $publicPrice,
                    'precio_proveedor' => $providerPrice,
                    'valor_publico' => round($quantity * $publicPrice, 2),
                    'valor_proveedor' => round($quantity * $providerPrice, 2),
                    'deleted_at' => optional($product->deleted_at)->toDateTimeString(),
                ];
            })->values();

            return [
                'id' => (int) $proveedor->id,
                'ident' => (int) $proveedor->ident,
                'nombre' => $proveedor->nombre,
                'tel' => $proveedor->tel,
                'email' => $proveedor->email,
                'ciudad' => $proveedor->ciudad,
                'sucursal' => $proveedor->sucursal,
                'deleted_at' => optional($proveedor->deleted_at)->toDateTimeString(),
                'delete_reason' => $proveedor->delete_reason,
                'products_count' => $products->count(),
                'products_quantity' => $products->sum('cantidad'),
                'public_value' => round($products->sum('valor_publico'), 2),
                'provider_value' => round($products->sum('valor_proveedor'), 2),
                'products' => $products->toArray(),
            ];
        })->values();

        $summary = [
            'providers_count' => $items->count(),
            'products_count' => $items->sum('products_count'),
            'products_quantity' => $items->sum('products_quantity'),
            'public_value' => round($items->sum('public_value'), 2),
            'provider_value' => round($items->sum('provider_value'), 2),
        ];

        if ($request->boolean('download')) {
            $filename = 'proveedores_eliminados_' . now()->format('Y-m-d_His') . '.csv';

            return response()->streamDownload(function () use ($items) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'proveedor_id',
                    'proveedor_ident',
                    'proveedor',
                    'email',
                    'telefono',
                    'ciudad',
                    'sucursal',
                    'fecha_baja',
                    'motivo_baja',
                    'productos_eliminados',
                    'piezas_eliminadas',
                    'valor_publico',
                    'valor_proveedor',
                    'producto_id',
                    'producto_ident',
                    'producto',
                    'descripcion',
                    'cantidad',
                    'precio_venta',
                    'precio_proveedor',
                    'producto_fecha_baja',
                ]);

                foreach ($items as $provider) {
                    if (empty($provider['products'])) {
                        fputcsv($handle, [
                            $provider['id'],
                            $provider['ident'],
                            $provider['nombre'],
                            $provider['email'],
                            $provider['tel'],
                            $provider['ciudad'],
                            $provider['sucursal'],
                            $provider['deleted_at'],
                            $provider['delete_reason'],
                            $provider['products_count'],
                            $provider['products_quantity'],
                            $provider['public_value'],
                            $provider['provider_value'],
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

                    foreach ($provider['products'] as $product) {
                        fputcsv($handle, [
                            $provider['id'],
                            $provider['ident'],
                            $provider['nombre'],
                            $provider['email'],
                            $provider['tel'],
                            $provider['ciudad'],
                            $provider['sucursal'],
                            $provider['deleted_at'],
                            $provider['delete_reason'],
                            $provider['products_count'],
                            $provider['products_quantity'],
                            $provider['public_value'],
                            $provider['provider_value'],
                            $product['id'],
                            $product['ident'],
                            $product['nombre'],
                            $product['descripcion'],
                            $product['cantidad'],
                            $product['precio'],
                            $product['precio_proveedor'],
                            $product['deleted_at'],
                        ]);
                    }
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        }

        return response()->json([
            'generated_at' => now()->toDateTimeString(),
            'summary' => $summary,
            'items' => $items->toArray(),
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
            ->with(['proveedor:ident,id,nombre'])
            ->whereHas('proveedor');

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
            'totals' => [
                'total_productos' => $paginator->total(),
                'total_existencia' => 0,
                'valor_publico' => 0,
                'valor_proveedor' => 0,
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
        $providerTipo = strtolower((string) $request->input('provider_tipo', ''));
        $providerIdent = $request->input('proveedor_id', null);

        $baseQuery = Inventario::query()
            ->join('producto as p', 'p.ident', '=', 'inventario.ident')
            ->join('proveedores as pr', 'pr.ident', '=', 'p.proveedorid')
            ->whereNull('p.deleted_at')
            ->whereNull('pr.deleted_at');

        $baseQuery->when($search !== '', function ($q) use ($search) {
            $normalized = Str::lower($search);
            $like = "%{$normalized}%";
            $q->where(function ($inner) use ($search, $like) {
                $inner->where('inventario.ident', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(p.nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(p.descripcion) LIKE ?', [$like])
                    ->orWhere('pr.ident', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(pr.nombre) LIKE ?', [$like]);
            });
        });

        if ($provider) {
            $baseQuery->where('pr.ident', '=', $provider->ident);
        }
        if ($providerIdent !== null && $providerIdent !== '') {
            $providerIdent = (int) $providerIdent;
            $baseQuery->where(function ($q) use ($providerIdent) {
                $q->where('pr.ident', '=', $providerIdent)
                    ->orWhere('pr.id', '=', $providerIdent);
            });
        }
        if ($providerTipo && in_array($providerTipo, ['normal', 'consigna', 'porcentaje'], true)) {
            $baseQuery->where('pr.tipo', '=', $providerTipo);
        }

        $query = (clone $baseQuery)
            ->select('inventario.*')
            ->with([
                'producto' => fn($q) => $q->select('id', 'ident', 'nombre', 'descripcion', 'precio', 'precio_proveedor', 'proveedorid'),
                'producto.proveedor' => fn($q) => $q->select('id', 'ident', 'nombre', 'tipo', 'porcentaje_comision'),
            ]);

        switch ($sort) {
            case 'precio':
                $query->orderByRaw('COALESCE(p.precio, 0) ' . strtoupper($direction));
                break;
            case 'existencia':
                $query->orderBy('inventario.existencia', $direction);
                break;
            case 'valor':
                $query->orderByRaw('COALESCE(p.precio, 0) * COALESCE(inventario.existencia, 0) ' . strtoupper($direction));
                break;
            case 'proveedor':
                $query->orderByRaw('LOWER(pr.nombre) ' . strtoupper($direction));
                break;
            default:
                $query->orderByRaw('LOWER(p.nombre) ' . strtoupper($direction));
                break;
        }

        if ($request->boolean('download')) {
            $downloadItems = (clone $query)
                ->get()
                ->map(fn(Inventario $inv) => $this->formatInventarioItem($inv));

            return $this->downloadInventarioCsv($downloadItems);
        }

        $paginator = $query->paginate($perPage)->appends([
            'q' => $search,
            'per_page' => $perPage,
            'sort' => $sort,
            'direction' => $direction,
            'proveedor_id' => $providerIdent,
            'provider_tipo' => $providerTipo,
        ]);

        $totalsRow = (clone $baseQuery)
            ->selectRaw('COUNT(*) as total_productos')
            ->selectRaw('SUM(COALESCE(inventario.existencia, 0)) as total_existencia')
            ->selectRaw('SUM(COALESCE(p.precio, 0) * COALESCE(inventario.existencia, 0)) as valor_publico')
            ->selectRaw('SUM(COALESCE(p.precio_proveedor, 0) * COALESCE(inventario.existencia, 0)) as valor_proveedor')
            ->first();

        $items = $paginator->getCollection()
            ->map(fn(Inventario $inv) => $this->formatInventarioItem($inv));

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
            'totals' => $totalsRow ? [
                'total_productos' => $totalsRow->total_productos ?? null,
                'total_existencia' => $totalsRow->total_existencia ?? null,
                'valor_publico' => $totalsRow->valor_publico ?? null,
                'valor_proveedor' => $totalsRow->valor_proveedor ?? null,
            ] : null,
        ]);
    }

    protected function formatInventarioItem(Inventario $inv): array
    {
        $producto = $inv->producto;
        $proveedor = $producto?->proveedor;
        $precio = $producto?->precio;
        $precioProveedor = $producto?->precio_proveedor;
        $existencia = (int) ($inv->existencia ?? 0);
        $costoInventario = $precio !== null ? round((float) $precio * $existencia, 2) : null;

        return [
            'inventario_id' => (int) $inv->id,
            'producto_ident' => (string) ($producto->ident ?? $inv->ident),
            'producto_nombre' => (string) ($producto->nombre ?? ''),
            'producto_descripcion' => (string) ($producto->descripcion ?? ''),
            'precio' => $precio !== null ? (float) $precio : null,
            'precio_proveedor' => $precioProveedor !== null ? (float) $precioProveedor : null,
            'existencia' => $existencia,
            'costo_inventario' => $costoInventario,
            'proveedor' => $proveedor ? [
                'ident' => (string) $proveedor->ident,
                'nombre' => (string) $proveedor->nombre,
                'tipo' => (string) ($proveedor->tipo ?? 'normal'),
                'porcentaje_comision' => $proveedor->porcentaje_comision,
            ] : null,
        ];
    }

    protected function downloadInventarioCsv(Collection $items)
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, [
            'Producto ident',
            'Producto',
            'Proveedor ident',
            'Proveedor',
            'Tipo proveedor',
            'Existencias',
            'Precio venta',
            'Precio proveedor',
            'Valor inventario',
        ]);

        foreach ($items as $row) {
            $provider = $row['proveedor'] ?? null;
            $precioProveedor = $row['precio_proveedor'] ?? null;
            $existencia = $row['existencia'] ?? 0;
            $valorInventario = $row['costo_inventario'];

            fputcsv($handle, [
                $row['producto_ident'] ?? '',
                $row['producto_nombre'] ?? '',
                $provider['ident'] ?? '',
                $provider['nombre'] ?? '',
                $provider['tipo'] ?? 'normal',
                $existencia,
                $row['precio'] ?? '',
                $precioProveedor ?? '',
                $valorInventario ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'reporte-inventario-' . Carbon::now()->format('Ymd-His') . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function cajaPorProveedor(Request $request)
    {
        $fechaInicio = $request->input('from_date');
        $fechaFin = $request->input('to_date');
        $provider = $this->currentProvider($request);
        $searchTerm = trim((string) $request->input('q', ''));
        $searchLower = $searchTerm !== '' ? mb_strtolower($searchTerm) : '';

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
        $page = max(1, (int) $request->input('page', 1));
        $perPageInput = (int) $request->input('per_page', 10);
        $perPageOptions = [10, 20, 40];
        $perPage = in_array($perPageInput, $perPageOptions, true) ? $perPageInput : 10;
        $applyPagination = !$request->boolean('download') && ($request->filled('page') || $request->filled('per_page'));

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

        $rows->where('vd.fecha', '>=', $inicioIso)
            ->where('vd.fecha', '<=', $finIso);
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

        if ($searchLower !== '') {
            $grouped = $grouped->filter(function ($group) use ($searchLower) {
                $first = $group->first();
                $candidates = [
                    $first->proveedor_nombre,
                    $first->proveedor_ident,
                    $first->proveedor_id,
                ];

                foreach ($candidates as $candidate) {
                    if ($candidate === null) {
                        continue;
                    }
                    if (str_contains(mb_strtolower((string) $candidate), $searchLower)) {
                        return true;
                    }
                }

                return false;
            });
        }

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

            $totalVendido = round($details->sum(fn ($item) => $item['total']), 2);
            $cardFeeTotal = round($details->sum(fn ($item) => $item['card_fee']), 2);
            $manualDiscountTotal = round($details->sum(fn ($item) => $item['manual_discount']), 2);
            $tipoDescuentoTotal = round($details->sum(fn ($item) => $item['provider_discount']), 2);
            $realEarningTotal = round($details->sum(fn ($item) => $item['real_earning']), 2);
            $cantidadTotal = (int) $details->sum(fn ($item) => $item['cantidad']);
            $precioPromedio = $cantidadTotal > 0
                ? round($details->sum(fn ($item) => $item['precio_unitario'] * $item['cantidad']) / $cantidadTotal, 2)
                : 0.0;

            return [
                'proveedor_id' => $proveedorId,
                'proveedor_ident' => $proveedorIdent,
                'proveedor_nombre' => $proveedorNombre,
                'proveedor_tipo' => $proveedorTipo,
                'proveedor_porcentaje' => $proveedorPorcentaje,
                'total_vendido' => $totalVendido,
                'card_fee_total' => $cardFeeTotal,
                'manual_discount_total' => $manualDiscountTotal,
                'tipo_descuento_total' => $tipoDescuentoTotal,
                'real_earning' => $realEarningTotal,
                'expected_earning' => $realEarningTotal,
                'totals' => [
                    'cantidad' => $cantidadTotal,
                    'precio_promedio' => $precioPromedio,
                    'total' => $totalVendido,
                    'provider_discount' => $tipoDescuentoTotal,
                    'manual_discount' => $manualDiscountTotal,
                    'card_fee' => $cardFeeTotal,
                    'ganancia' => $realEarningTotal,
                ],
                'items' => $details,
            ];
        })->values();
        $itemsMeta = null;
        $itemsTotals = null;
        if ($applyPagination && $provider) {
            $providerPayload = $providers->first();
            $items = collect($providerPayload['items'] ?? []);
            $totalItems = $items->count();
            $lastPage = max(1, (int) ceil($totalItems / $perPage));
            $page = min($page, $lastPage);
            $pagedItems = $items->forPage($page, $perPage)->values();
            $itemsTotals = [
                'cantidad' => (int) $pagedItems->sum(fn ($item) => $item['cantidad']),
                'precio_promedio' => $pagedItems->sum(fn ($item) => $item['cantidad']) > 0
                    ? round($pagedItems->sum(fn ($item) => $item['precio_unitario'] * $item['cantidad']) / $pagedItems->sum(fn ($item) => $item['cantidad']), 2)
                    : 0.0,
                'total' => round($pagedItems->sum(fn ($item) => $item['total']), 2),
                'provider_discount' => round($pagedItems->sum(fn ($item) => $item['provider_discount']), 2),
                'manual_discount' => round($pagedItems->sum(fn ($item) => $item['manual_discount']), 2),
                'card_fee' => round($pagedItems->sum(fn ($item) => $item['card_fee']), 2),
                'ganancia' => round($pagedItems->sum(fn ($item) => $item['real_earning']), 2),
            ];
            if ($providerPayload) {
                $providerPayload['items'] = $pagedItems;
                $providers = collect([$providerPayload])->values();
            }
            $itemsMeta = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalItems,
                'last_page' => $lastPage,
            ];
        }

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
            'items_meta' => $itemsMeta,
            'items_totals' => $itemsTotals,
            'provider' => $provider ? [
                'id' => $provider->id,
                'ident' => $provider->ident,
                'nombre' => $provider->nombre,
            ] : null,
        ]);
    }

    public function cancelaciones(Request $request)
    {
        $fromDate = $request->input('from_date');
        if (!$fromDate) {
            return response()->json(['message' => 'Debe proporcionar from_date.'], 422);
        }

        try {
            $inicio = $this->parseDateInput($fromDate)->startOfDay();
            $fin = $request->filled('to_date')
                ? $this->parseDateInput($request->input('to_date'))->endOfDay()
                : (clone $inicio)->endOfDay();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Formato de fecha inválido.'], 422);
        }

        if ($inicio->gt($fin)) {
            return response()->json(['message' => 'from_date no puede ser mayor a to_date.'], 422);
        }

        $search = trim((string) $request->input('q', ''));

        $query = VentaCancelacion::with(['admin:id,nombre,email'])
            ->whereBetween('created_at', [$inicio, $fin])
            ->orderByDesc('created_at');

        if ($search !== '') {
            $like = '%' . strtolower($search) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(idventa::text) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(reason) LIKE ?', [$like])
                    ->orWhereHas('admin', function ($inner) use ($like) {
                        $inner->whereRaw('LOWER(nombre) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                    });
            });
        }

        $records = $query->get();

        $items = $records->map(function (VentaCancelacion $cancel) {
            $ventaPayload = $cancel->venta_payload ?? [];
            $lineasPayload = $cancel->lineas_payload ?? [];
            return [
                'id' => $cancel->id,
                'venta_id' => $cancel->venta_id,
                'idventa' => $cancel->idventa,
                'reason' => $cancel->reason,
                'cancelled_at' => optional($cancel->created_at)->toDateTimeString(),
                'admin' => $cancel->admin ? [
                    'id' => $cancel->admin->id,
                    'nombre' => $cancel->admin->nombre,
                    'email' => $cancel->admin->email,
                ] : null,
                'sale_date' => $ventaPayload['fecha'] ?? null,
                'sale_time' => $ventaPayload['hora'] ?? null,
                'metodo' => $ventaPayload['metodo'] ?? null,
                'vendedor' => $ventaPayload['vendedor'] ?? null,
                'total' => isset($ventaPayload['totalventa']) ? (float) $ventaPayload['totalventa'] : null,
                'line_items' => array_map(function ($line) {
                    $qty = isset($line['quantity']) ? (float) $line['quantity'] : (isset($line['cantidad']) ? (float) $line['cantidad'] : 0);
                    $unitPrice = null;
                    if (isset($line['unit_price'])) {
                        $unitPrice = (float) $line['unit_price'];
                    } elseif ($qty > 0 && isset($line['public_total'])) {
                        $unitPrice = round(((float) $line['public_total']) / $qty, 2);
                    }
                    $lineTotal = isset($line['venta_total']) ? (float) $line['venta_total'] : null;
                    if ($lineTotal === null || $lineTotal == 0) {
                        if (isset($line['public_total'])) {
                            $lineTotal = (float) $line['public_total'];
                        } elseif ($unitPrice !== null) {
                            $lineTotal = $unitPrice * $qty;
                        }
                    }
                    return [
                        'producto_nombre' => $line['nombre'] ?? null,
                        'producto_ident' => $line['producto_id'] ?? null,
                        'cantidad' => $qty,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                    ];
                }, $lineasPayload),
            ];
        });

        return response()->json([
            'range' => [
                'from' => $inicio->toDateString(),
                'to' => $fin->toDateString(),
            ],
            'count' => $items->count(),
            'items' => $items,
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

        $columnResolver = static function (array $candidates) {
            foreach ($candidates as $candidate) {
                if (Schema::hasColumn('ventadesg', $candidate)) {
                    return 'vd.' . $candidate;
                }
            }
            return null;
        };

        $productoColumnExpr = $columnResolver(['idprod', 'producto_id', 'producto_ident']);
        $cantidadColumnExpr = $columnResolver(['cant', 'quantity', 'cantidad']);
        $totalColumnExpr = $columnResolver(['total', 'venta_total', 'public_total']);
        $descuentoColumnExpr = $columnResolver([
            'descuento_producto',
            'provider_percentage_discount',
            'provider_discount_amount',
            'manual_discount_amount',
        ]);
        $cargoTarjetaColumnExpr = $columnResolver([
            'cargo_tarjeta_proveedor',
            'credit_card_discount',
            'provider_card_fee',
        ]);

        $providerColumn = null;
        $providerValue = $provider->ident;
        $providerColumnCandidates = [
            ['name' => 'proveedor_id', 'value' => $provider->id],
            ['name' => 'provider_id', 'value' => $provider->id],
            ['name' => 'proveedor', 'value' => $provider->ident],
            ['name' => 'provider', 'value' => $provider->ident],
            ['name' => 'proveedor_ident', 'value' => $provider->ident],
            ['name' => 'provider_ident', 'value' => $provider->ident],
        ];
        foreach ($providerColumnCandidates as $candidate) {
            if (Schema::hasColumn('ventadesg', $candidate['name'])) {
                $providerColumn = 'vd.' . $candidate['name'];
                $providerValue = $candidate['value'];
                break;
            }
        }
        if (!$providerColumn) {
            $providerColumn = DB::raw('1');
            $providerValue = 1;
        }

        $query = DB::table('ventadesg as vd')
            ->select([
                DB::raw(($productoColumnExpr ?? 'NULL') . ' as producto_ident'),
                'vd.nombre as producto_nombre',
                DB::raw(($cantidadColumnExpr ?? '0') . ' as cantidad'),
                DB::raw(($totalColumnExpr ?? '0') . ' as total'),
                DB::raw(($descuentoColumnExpr ?? '0') . ' as descuento_producto'),
                DB::raw(($cargoTarjetaColumnExpr ?? '0') . ' as cargo_tarjeta_proveedor'),
                'vd.fecha',
            ])
            ->where($providerColumn, '=', $providerValue);

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

            $cantidad = (int) ($row->cantidad ?? 0);
            $bruto = (float) ($row->total ?? 0);
            $descuento = (float) ($row->descuento_producto ?? 0);
            $cargoTarjeta = (float) ($row->cargo_tarjeta_proveedor ?? 0);
            $neto = $bruto - $descuento - $cargoTarjeta;
            $dateBuckets[$dateKey] += $neto;

            $productoKey = (string) ($row->producto_ident ?? 'sin_ident');
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

    private function computeFreshAverageSales($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $today = Carbon::today();
        $todayString = $today->toDateString();
        $result = [];

        $grouped = $rows->groupBy(fn (ProviderRestockForecast $row) => (int) $row->lookback_days);
        foreach ($grouped as $lookback => $group) {
            $days = max(1, (int) $lookback);
            $startDate = $today->copy()->subDays($days - 1)->toDateString();

            $productIds = $group->pluck('producto_ident')->filter()->unique()->values();
            if ($productIds->isEmpty()) {
                continue;
            }

            $providerIds = $group->pluck('provider_ident')->filter()->unique()->values();
            $sales = ProductSalesAggregator::aggregate(
                $startDate,
                $todayString,
                $productIds->all(),
                $providerIds->all()
            );

            foreach ($sales as $sale) {
                $key = $this->avgKey((string) $sale->provider_ident, (string) $sale->producto_ident, $days);
                $result[$key] = round((float) $sale->unidades / $days, 4);
            }
        }

        return $result;
    }

    private function avgKey(?string $providerIdent, ?string $productIdent, int $days): string
    {
        return (string) $providerIdent . ':' . (string) $productIdent . ':' . max(1, $days);
    }

    private function sanitizeEmailBody(string $body): string
    {
        $appName = config('app.name', 'Laravel');
        $appUrl = config('app.url');
        $brandLine = trim($appName . ': ' . ($appUrl ?? ''));
        if ($appUrl && str_contains($body, $brandLine)) {
            $body = str_replace($brandLine, '', $body);
        }

        return trim($body);
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
