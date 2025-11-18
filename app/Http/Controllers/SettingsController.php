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
            'horizon.*' => ['in:day,week,month'],
            'card_charge_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        if (isset($data['horizon'])) {
            $value = implode(',', $data['horizon']);
            SystemSettings::set('restock_cron_horizon', $value);
        }

        if (array_key_exists('card_charge_percent', $data)) {
            $value = (string) $data['card_charge_percent'];
            SystemSettings::set('card_charge_percent', $value);
            CardCharge::refresh();
        }

        return response()->json($this->currentSettings());
    }

    public function runRestock(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'horizon' => ['sometimes', 'array', 'min:1'],
            'horizon.*' => ['in:day,week,month'],
        ]);

        $horizon = $data['horizon'] ?? $this->getCronHorizon();
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
                'available' => ['day', 'week', 'month'],
                'horizon' => $horizon,
                'last_run' => SystemSettings::get('restock_last_run'),
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
        $value = SystemSettings::get('restock_cron_horizon', 'day,week,month');
        $parts = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $value))), function ($h) {
            return in_array($h, ['day', 'week', 'month'], true);
        }));

        return $parts ?: ['day', 'week', 'month'];
    }
}
