<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\DailyCashSummary;
use App\Support\CardCharge;
use App\Support\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SettingsController extends Controller
{
    private const RESTOCK_HORIZONS = ['2w', '4w', '6w'];

    public function general(Request $request)
    {
        $this->ensureAdmin($request);

        return response()->json($this->currentSettings());
    }

    public function updateGeneral(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'horizon' => ['sometimes', 'array', 'min:1'],
            'horizon.*' => ['string'],
            'card_charge_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'restock_include_zero' => ['sometimes', 'boolean'],
            'restock_min_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        if (isset($data['horizon'])) {
            $horizonList = $this->normalizeHorizonArray($data['horizon']);
            if (empty($horizonList)) {
                $horizonList = ['2w'];
            }
            SystemSettings::set('restock_cron_horizon', implode(',', $horizonList));
        }

        if (array_key_exists('card_charge_percent', $data)) {
            $value = (string) $data['card_charge_percent'];
            SystemSettings::set('card_charge_percent', $value);
            CardCharge::refresh();
        }

        if (array_key_exists('restock_include_zero', $data)) {
            SystemSettings::set('restock_include_zero', $data['restock_include_zero'] ? '1' : '0');
        }

        if (array_key_exists('restock_min_days', $data)) {
            SystemSettings::set('restock_min_days', (string) max(0, (int) $data['restock_min_days']));
        }

        return response()->json($this->currentSettings());
    }

    public function runRestock(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'horizon' => ['sometimes', 'array', 'min:1'],
            'horizon.*' => ['string'],
        ]);

        $horizon = isset($data['horizon'])
            ? $this->normalizeHorizonArray($data['horizon'])
            : $this->getCronHorizon();
        if (empty($horizon)) {
            $horizon = ['2w'];
        }
        $value = implode(',', $horizon);

        Artisan::call('restock:forecast', [
            '--horizon' => $value,
        ]);

        return response()->json([
            'message' => 'Pronóstico ejecutado',
            'horizon' => $horizon,
        ]);
    }

    private function ensureAdmin(Request $request): void
    {
        if (!($request->user() instanceof Usuario)) {
            abort(403, 'Solo administradores pueden modificar la configuración.');
        }
    }

    private function currentSettings(): array
    {
        $horizon = $this->getCronHorizon();

        return [
            'restock' => [
                'available' => self::RESTOCK_HORIZONS,
                'horizon' => $horizon,
                'last_run' => SystemSettings::get('restock_last_run'),
                'include_zero' => $this->includeZeroFlag(),
                'min_days' => $this->getMinInventoryDays(),
            ],
            'card_charge_percent' => (float) SystemSettings::get('card_charge_percent', '4.5'),
            'last_closing_balance' => $this->getLastClosingBalance(),
        ];
    }

    private function getLastClosingBalance(): ?float
    {
        $row = DailyCashSummary::query()
            ->whereNotNull('saldo_cierre')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->first();

        return $row ? (float) ($row->saldo_cierre ?? 0) : null;
    }

    private function getCronHorizon(): array
    {
        $value = SystemSettings::get('restock_cron_horizon', '2w,4w,6w');
        $parts = $this->normalizeHorizonArray(explode(',', (string) $value));

        return $parts ?: ['2w', '4w', '6w'];
    }

    private function includeZeroFlag(): bool
    {
        return filter_var(SystemSettings::get('restock_include_zero', '0'), FILTER_VALIDATE_BOOL);
    }

    private function getMinInventoryDays(): int
    {
        return (int) SystemSettings::get('restock_min_days', 14);
    }

    /**
     * @param array<int, string|null> $values
     */
    private function normalizeHorizonArray(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $key = $this->normalizeHorizonValue($value);
            if ($key) {
                $normalized[$key] = true;
            }
        }

        return array_values(array_keys($normalized));
    }

    private function normalizeHorizonValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));
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
}
