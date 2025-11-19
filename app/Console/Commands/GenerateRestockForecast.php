<?php

namespace App\Console\Commands;

use App\Models\ProviderRestockForecast;
use App\Support\ProductSalesAggregator;
use App\Support\SystemSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateRestockForecast extends Command
{
    protected $signature = 'restock:forecast {--horizon=2w} {--lookback=}';

    protected $description = 'Genera pronósticos de resurtido para 2, 4 y 6 semanas usando ventas históricas.';

    private const HORIZONS = [
        '2w' => ['label' => '2 semanas', 'days' => 14],
        '4w' => ['label' => '4 semanas', 'days' => 28],
        '6w' => ['label' => '6 semanas', 'days' => 42],
    ];

    private const DEFAULT_LOOKBACK_DAYS = 90;

    public function handle(): int
    {
        $horizonsInput = $this->option('horizon') ?: '2w';
        $horizons = collect(explode(',', $horizonsInput))
            ->map(fn ($h) => $this->normalizeHorizon($h))
            ->filter()
            ->unique()
            ->values();

        if ($horizons->isEmpty()) {
            $this->warn('No horizon provided; using 2w.');
            $horizons = collect(['2w']);
        }

        $forecastDate = Carbon::today()->toDateString();
        ProviderRestockForecast::where('forecast_date', $forecastDate)
            ->whereIn('horizon', $horizons)
            ->delete();

        $minimumDays = $this->getMinimumInventoryDays();

        $totalInserted = 0;
        foreach ($horizons as $horizon) {
            [$lookbackDays, $horizonDays] = $this->resolveWindow($horizon);
            $rows = $this->buildForecastRows($lookbackDays, $horizonDays, $horizon, $forecastDate, $minimumDays);
            if (empty($rows)) {
                $this->warn("No data for horizon {$horizon}.");
                continue;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                ProviderRestockForecast::insert($chunk);
            }

            $totalInserted += count($rows);
            $this->info("Inserted " . count($rows) . " rows for horizon {$horizon} (lookback {$lookbackDays}, horizon {$horizonDays} days, min {$minimumDays} days).");
        }

        $this->info('Total rows inserted: ' . $totalInserted);
        SystemSettings::set('restock_last_run', now()->toDateTimeString());

        return Command::SUCCESS;
    }

    private function normalizeHorizon(string $value): ?string
    {
        $value = strtolower(trim($value));
        $legacyMap = [
            'day' => '2w',
            'week' => '4w',
            'month' => '6w',
            '2weeks' => '2w',
            '4weeks' => '4w',
            '6weeks' => '6w',
        ];

        if (isset($legacyMap[$value])) {
            return $legacyMap[$value];
        }

        return array_key_exists($value, self::HORIZONS) ? $value : null;
    }

    private function resolveWindow(string $horizon): array
    {
        $lookbackOption = $this->option('lookback');
        if ($lookbackOption !== null) {
            $lookback = max(30, (int) $lookbackOption);
        } else {
            $lookback = (int) SystemSettings::get('restock_lookback_days', self::DEFAULT_LOOKBACK_DAYS);
            $lookback = max(30, min(365, $lookback));
        }
        $horizonDays = self::HORIZONS[$horizon]['days'];

        return [$lookback, $horizonDays];
    }

    private function getMinimumInventoryDays(): int
    {
        $value = (int) SystemSettings::get('restock_min_days', 14);
        return max(0, $value);
    }

    private function buildForecastRows(int $lookbackDays, int $horizonDays, string $horizon, string $forecastDate, int $minimumDays): array
    {
        $today = Carbon::createFromFormat('Y-m-d', $forecastDate);
        $startDate = $today->copy()->subDays($lookbackDays - 1);

        $this->info("Processing horizon {$horizon}: lookback {$lookbackDays} days, horizon {$horizonDays} days, min coverage {$minimumDays} days");

        $sales = ProductSalesAggregator::aggregate(
            $startDate->toDateString(),
            $today->toDateString()
        )->filter(fn ($row) => (float) $row->unidades > 0);

        if ($sales->isEmpty()) {
            return [];
        }

        $productoIdents = $sales->pluck('producto_ident')->unique()->filter()->values();
        $proveedorIdents = $sales->pluck('provider_ident')->unique()->filter()->values();

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
            $providerIdentValue = $sale->provider_ident ?? null;
            $providerIdent = $providerIdentValue !== null ? (string) $providerIdentValue : '';
            if ($providerIdent === '') {
                continue;
            }

            $productIdent = (string) $sale->producto_ident;
            $totalUnits = (float) $sale->unidades;

            $avgDaily = round($totalUnits / max(1, $lookbackDays), 6);
            if ($avgDaily <= 0) {
                continue;
            }

            $inventory = (int) ($inventarioMap->get($productIdent)->existencia ?? 0);

            $requiredDays = $horizonDays + $minimumDays;
            $projectedDemand = round($avgDaily * $horizonDays, 4);
            $requiredUnits = $avgDaily * $requiredDays;
            $suggested = (int) max(0, ceil($requiredUnits - $inventory));

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
                'lead_time_days' => $horizonDays,
                'projected_demand' => $projectedDemand,
                'inventory_on_hand' => $inventory,
                'suggested_order_qty' => $suggested,
                'days_of_cover' => $daysOfCover,
                'details' => json_encode([
                    'total_units' => $totalUnits,
                    'dias_con_venta' => (int) $sale->dias_con_venta,
                    'minimum_inventory_days' => $minimumDays,
                    'required_days' => $requiredDays,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }
}
