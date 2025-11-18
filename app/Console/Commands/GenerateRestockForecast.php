<?php

namespace App\Console\Commands;

use App\Models\ProviderRestockForecast;
use App\Support\SystemSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateRestockForecast extends Command
{
    protected $signature = 'restock:forecast {--horizon=week} {--lookback=} {--leadtime=}';

    protected $description = 'Generate restock forecasts per proveedor/producto and store suggestions.';

    public function handle(): int
    {
        $horizonsInput = $this->option('horizon') ?: 'week';
        $horizons = collect(explode(',', $horizonsInput))
            ->map(fn ($h) => strtolower(trim($h)))
            ->filter(fn ($h) => in_array($h, array_keys(self::PRESETS)))
            ->unique()
            ->values();

        if ($horizons->isEmpty()) {
            $this->warn('No horizon provided; using week.');
            $horizons = collect(['week']);
        }

        $forecastDate = Carbon::today()->toDateString();
        ProviderRestockForecast::where('forecast_date', $forecastDate)
            ->whereIn('horizon', $horizons)
            ->delete();

        $totalInserted = 0;
        foreach ($horizons as $horizon) {
            [$lookbackDays, $leadTimeDays] = $this->resolveWindow($horizon);
            $rows = $this->buildForecastRows($lookbackDays, $leadTimeDays, $horizon, $forecastDate);
            if (empty($rows)) {
                $this->warn("No data for horizon {$horizon}.");
                continue;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                ProviderRestockForecast::insert($chunk);
            }

            $totalInserted += count($rows);
            $this->info("Inserted " . count($rows) . " rows for horizon {$horizon} (lookback {$lookbackDays}, lead {$leadTimeDays}).");
        }

        $this->info('Total rows inserted: ' . $totalInserted);
        SystemSettings::set('restock_last_run', now()->toDateTimeString());

        return Command::SUCCESS;
    }

    private const PRESETS = [
        'day' => ['lookback' => 7, 'leadtime' => 1],
        'week' => ['lookback' => 30, 'leadtime' => 7],
        'month' => ['lookback' => 60, 'leadtime' => 30],
    ];

    private function resolveWindow(string $horizon): array
    {
        $lookbackOption = $this->option('lookback');
        $leadOption = $this->option('leadtime');
        $lookback = $lookbackOption !== null ? (int) $lookbackOption : self::PRESETS[$horizon]['lookback'];
        $lead = $leadOption !== null ? (int) $leadOption : self::PRESETS[$horizon]['leadtime'];

        return [max(1, $lookback), max(1, $lead)];
    }

    private function buildForecastRows(int $lookbackDays, int $leadTimeDays, string $horizon, string $forecastDate): array
    {
        $today = Carbon::createFromFormat('Y-m-d', $forecastDate);
        $startDate = $today->copy()->subDays($lookbackDays - 1);

        $this->info("Processing horizon {$horizon}: lookback {$lookbackDays} days, lead time {$leadTimeDays} days");

        $sales = DB::table('ventadesg as vd')
            ->select([
                'vd.proveedor_id',
                'vd.producto_id as producto_ident',
                DB::raw('SUM(vd.quantity) as unidades'),
                DB::raw('COUNT(DISTINCT vd.fecha) as dias_con_venta'),
            ])
            ->whereBetween('vd.fecha', [$startDate->toDateString(), $today->toDateString()])
            ->groupBy('vd.proveedor_id', 'vd.producto_id')
            ->havingRaw('SUM(vd.quantity) > 0')
            ->get();

        if ($sales->isEmpty()) {
            return [];
        }

        $productoIdents = $sales->pluck('producto_ident')->unique()->filter()->values();
        $proveedorIdents = $sales->pluck('proveedor_id')->unique()->filter()->values();

        $productoMap = DB::table('producto as p')
            ->select(['p.ident', 'p.nombre'])
            ->whereIn('p.ident', $productoIdents)
            ->get()
            ->keyBy(fn ($row) => (string) $row->ident);

        $proveedorMap = DB::table('proveedores as pr')
            ->select(['pr.ident', 'pr.nombre'])
            ->whereIn('pr.ident', $proveedorIdents)
            ->get()
            ->keyBy(fn ($row) => (string) $row->ident);

        $inventarioMap = DB::table('inventario as inv')
            ->select(['inv.ident', 'inv.existencia'])
            ->whereIn('inv.ident', $productoIdents)
            ->get()
            ->keyBy(fn ($row) => (string) $row->ident);

        $rows = [];
        foreach ($sales as $sale) {
            $providerIdentValue = $sale->proveedor_id ?? null;
            $providerIdent = $providerIdentValue !== null ? (string) $providerIdentValue : '';
            if ($providerIdent === '') {
                continue;
            }
            $productIdent = (string) $sale->producto_ident;
            $totalUnits = (float) $sale->unidades;

            $avgDaily = round($totalUnits / max(1, $lookbackDays), 4);
            $inventory = (int) ($inventarioMap->get($productIdent)->existencia ?? 0);
            $projectedDemand = round($avgDaily * $leadTimeDays, 4);
            $suggested = (int) max(0, ceil($projectedDemand - $inventory));

            if ($avgDaily <= 0 && $inventory > 0) {
                continue;
            }

            $daysOfCover = $avgDaily > 0 ? round($inventory / max($avgDaily, 0.0001), 2) : null;

            $rows[] = [
                'forecast_date' => $forecastDate,
                'horizon' => $horizon,
                'provider_ident' => $providerIdent,
                'provider_name' => optional($proveedorMap->get($providerIdent))->nombre,
                'producto_ident' => $productIdent,
                'producto_nombre' => optional($productoMap->get($productIdent))->nombre,
                'avg_daily_sales' => $avgDaily,
                'lookback_days' => $lookbackDays,
                'lead_time_days' => $leadTimeDays,
                'projected_demand' => $projectedDemand,
                'inventory_on_hand' => $inventory,
                'suggested_order_qty' => $suggested,
                'days_of_cover' => $daysOfCover,
                'details' => json_encode([
                    'total_units' => $totalUnits,
                    'dias_con_venta' => (int) $sale->dias_con_venta,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }
}
