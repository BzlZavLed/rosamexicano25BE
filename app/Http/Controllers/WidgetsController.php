<?php

namespace App\Http\Controllers;

use App\Models\EstadoCaja;
use App\Models\Venta;
use App\Models\VentaDesg;
use App\Models\Proveedor;
use App\Models\ProviderRestockForecast;
use App\Models\Usuario;
use App\Support\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WidgetsController extends Controller
{
    private const RESTOCK_HORIZONS = ['2w', '4w', '6w'];
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

    public function restockAlerts(Request $request)
    {
        $limit = (int) $request->input('limit', 5);
        $limit = max(1, min(20, $limit));
        $horizon = $this->resolveRestockHorizon($request);

        $forecastDate = ProviderRestockForecast::where('horizon', $horizon)->max('forecast_date');
        if (!$forecastDate) {
            return response()->json([
                'message' => 'No hay pronósticos disponibles. Ejecuta restock:forecast.',
                'items' => [],
            ], 404);
        }

        $rows = ProviderRestockForecast::where('forecast_date', $forecastDate)
            ->where('horizon', $horizon)
            ->orderByDesc(DB::raw('suggested_order_qty * GREATEST(avg_daily_sales, 1)'))
            ->limit($limit)
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

        $freshAverages = $this->computeFreshAverageSales($rows);
        $minimumDays = (int) SystemSettings::get('restock_min_days', 14);

        $forecastCarbon = Carbon::parse($forecastDate);

        $items = $rows->map(function (ProviderRestockForecast $row) use ($inventoryMap, $freshAverages, $minimumDays, $forecastCarbon) {
            $currentInventory = $inventoryMap->get($row->producto_ident);
            $inventoryOnHand = $currentInventory ? (int) $currentInventory->existencia : (int) $row->inventory_on_hand;
            $avgKey = $this->avgKey($row->provider_ident, $row->producto_ident, (int) $row->lookback_days);
            $avgDaily = $freshAverages[$avgKey] ?? (float) $row->avg_daily_sales;
            $daysOfCover = $avgDaily > 0 ? round($inventoryOnHand / max($avgDaily, 0.0001), 2) : null;
            $requiredDays = max(1, (int) $row->lead_time_days) + $minimumDays;
            $requiredUnits = $avgDaily * $requiredDays;
            $suggested = (int) max(0, ceil($requiredUnits - $inventoryOnHand));
            $dueDate = $forecastCarbon->copy()->addDays(max(1, (int) $row->lead_time_days))->toDateString();
            $restockAsap = $inventoryOnHand < 5;

            return [
                'provider_ident' => $row->provider_ident,
                'provider_name' => $row->provider_name,
                'producto_ident' => $row->producto_ident,
                'producto_nombre' => $row->producto_nombre,
                'inventory_on_hand' => $inventoryOnHand,
                'avg_daily_sales' => $avgDaily,
                'suggested_order_qty' => $suggested,
                'days_of_cover' => $daysOfCover,
                'restock_by_date' => $dueDate,
                'restock_asap' => $restockAsap,
            ];
        });

        return response()->json([
            'forecast_date' => $forecastDate,
            'horizon' => $horizon,
            'items' => $items,
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

            $sales = DB::table('ventadesg as vd')
                ->select([
                    'vd.proveedor_id',
                    'vd.producto_id',
                    DB::raw('SUM(vd.quantity) as unidades'),
                ])
                ->whereBetween('vd.fecha', [$startDate, $todayString])
                ->whereIn('vd.producto_id', $productIds)
                ->groupBy('vd.proveedor_id', 'vd.producto_id')
                ->get();

            foreach ($sales as $sale) {
                $key = $this->avgKey((string) $sale->proveedor_id, (string) $sale->producto_id, $days);
                $result[$key] = round((float) $sale->unidades / $days, 4);
            }
        }

        return $result;
    }

    private function avgKey(?string $providerIdent, ?string $productIdent, int $days): string
    {
        return (string) $providerIdent . ':' . (string) $productIdent . ':' . max(1, $days);
    }
}
