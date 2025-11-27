<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\DailyCashSummary;
use App\Support\CardCharge;
use App\Support\CashboxAutoCloser;
use App\Support\SystemSettings;
use App\Models\Venta;
use App\Models\SystemSettingHistory;
use App\Models\CardRebalanceLog;
use App\Models\CardRebalanceChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

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
            'restock_lookback_days' => ['sometimes', 'integer', 'min:30', 'max:365'],
            'recommended_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'recommended_months' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        $changes = [];

        if (isset($data['horizon'])) {
            $horizonList = $this->normalizeHorizonArray($data['horizon']);
            if (empty($horizonList)) {
                $horizonList = ['2w'];
            }
            $old = SystemSettings::get('restock_cron_horizon', null);
            $new = implode(',', $horizonList);
            if ($old !== $new) {
                $changes[] = ['key' => 'restock_cron_horizon', 'old' => $old, 'new' => $new];
                SystemSettings::set('restock_cron_horizon', $new);
            }
        }

        if (array_key_exists('card_charge_percent', $data)) {
            $old = SystemSettings::get('card_charge_percent', null);
            $new = (string) $data['card_charge_percent'];
            if ($old !== $new) {
                $changes[] = ['key' => 'card_charge_percent', 'old' => $old, 'new' => $new];
                SystemSettings::set('card_charge_percent', $new);
                CardCharge::refresh();
            }
        }

        if (array_key_exists('restock_include_zero', $data)) {
            $old = SystemSettings::get('restock_include_zero', null);
            $new = $data['restock_include_zero'] ? '1' : '0';
            if ($old !== $new) {
                $changes[] = ['key' => 'restock_include_zero', 'old' => $old, 'new' => $new];
                SystemSettings::set('restock_include_zero', $new);
            }
        }

        if (array_key_exists('restock_min_days', $data)) {
            $old = SystemSettings::get('restock_min_days', null);
            $new = (string) max(0, (int) $data['restock_min_days']);
            if ($old !== $new) {
                $changes[] = ['key' => 'restock_min_days', 'old' => $old, 'new' => $new];
                SystemSettings::set('restock_min_days', $new);
            }
        }

        if (array_key_exists('restock_lookback_days', $data)) {
            $old = SystemSettings::get('restock_lookback_days', null);
            $new = (string) max(30, min(365, (int) $data['restock_lookback_days']));
            if ($old !== $new) {
                $changes[] = ['key' => 'restock_lookback_days', 'old' => $old, 'new' => $new];
                SystemSettings::set('restock_lookback_days', $new);
            }
        }

        if (array_key_exists('recommended_percentage', $data)) {
            $old = SystemSettings::get('analysis_recommended_pct', null);
            $new = (string) $data['recommended_percentage'];
            if ($old !== $new) {
                $changes[] = ['key' => 'analysis_recommended_pct', 'old' => $old, 'new' => $new];
                SystemSettings::set('analysis_recommended_pct', $new);
            }
        }

        if (array_key_exists('recommended_months', $data)) {
            $old = SystemSettings::get('analysis_recommended_months', null);
            $new = (string) max(1, (int) $data['recommended_months']);
            if ($old !== $new) {
                $changes[] = ['key' => 'analysis_recommended_months', 'old' => $old, 'new' => $new];
                SystemSettings::set('analysis_recommended_months', $new);
            }
        }

        if (!empty($changes)) {
            $user = $request->user();
            foreach ($changes as $change) {
                SystemSettingHistory::create([
                    'key' => $change['key'],
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                    'changed_by' => $user?->id,
                    'changed_by_name' => $user?->nombre ?? $user?->email ?? null,
                ]);
            }
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

    public function runCashAutoClose(Request $request)
    {
        $this->ensureAdmin($request);
        $result = CashboxAutoCloser::closePending();
        return response()->json([
            'message' => 'Cierre automático ejecutado.',
            'dates' => $result['dates'],
            'count' => $result['count'],
        ]);
    }

    public function runCardRebalance(Request $request)
    {
        $this->ensureAdmin($request);

        $validator = Validator::make($request->all(), [
            'date' => ['required_without:venta_id', 'date_format:Y-m-d'],
            'venta_id' => ['required_without:date', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $date = $request->input('date');
        $ventaId = $request->input('venta_id');

        if (!$date && $ventaId) {
            $venta = Venta::where('idventa', (int) $ventaId)->first();
            if (!$venta || !$venta->fecha) {
                return response()->json(['message' => 'No se encontró la venta o no tiene fecha.'], 404);
            }
            $date = $venta->fecha instanceof \DateTimeInterface
                ? $venta->fecha->format('Y-m-d')
                : date('Y-m-d', strtotime((string) $venta->fecha));
        }

        Artisan::call('card:rebalance', [
            'date' => $date,
            '--venta_id' => $ventaId,
            '--user_id' => $request->user()?->id,
            '--user_name' => $request->user()?->nombre ?? $request->user()?->email,
        ]);

        $latestLog = CardRebalanceLog::query()->orderByDesc('id')->first();

        return response()->json([
            'message' => $latestLog?->message ?? 'Rebalanceo de cargos de tarjeta ejecutado.',
            'stats' => $latestLog ? [
                'sales_processed' => $latestLog->sales_processed,
                'sales_updated' => $latestLog->sales_updated,
                'lines_updated' => $latestLog->lines_updated,
                'venta_id' => $latestLog->venta_id,
                'date' => $latestLog->date_param,
            ] : null,
            'log' => $latestLog?->message,
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
                'lookback_days' => $this->getLookbackDays(),
            ],
            'card_charge_percent' => (float) SystemSettings::get('card_charge_percent', '4.5'),
            'last_closing_balance' => $this->getLastClosingBalance(),
            'analysis' => [
                'recommended_percentage' => (float) SystemSettings::get('analysis_recommended_pct', '5'),
                'recommended_months' => (int) SystemSettings::get('analysis_recommended_months', '12'),
            ],
            'history' => SystemSettingHistory::query()
                ->orderByDesc('id')
                ->limit(20)
                ->get([
                    'key',
                    'old_value',
                    'new_value',
                    'changed_by',
                    'changed_by_name',
                    'created_at',
                ]),
            'card_rebalance_history' => CardRebalanceLog::query()
                ->orderByDesc('id')
                ->limit(20)
                ->get([
                    'date_param',
                    'venta_id',
                    'sales_processed',
                    'sales_updated',
                    'lines_updated',
                    'sale_ids',
                    'message',
                    'triggered_by',
                    'triggered_by_name',
                    'created_at',
                ]),
            'card_rebalance_changes' => CardRebalanceChange::query()
                ->orderByDesc('id')
                ->limit(50)
                ->get([
                    'venta_id',
                    'ventadesg_id',
                    'fecha_sale',
                    'public_total',
                    'total_venta',
                    'old_credit_card_discount',
                    'new_credit_card_discount',
                    'proveedor_id',
                    'created_at',
                ]),
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

    private function getLookbackDays(): int
    {
        $value = (int) SystemSettings::get('restock_lookback_days', 90);
        return max(30, min(365, $value > 0 ? $value : 90));
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
