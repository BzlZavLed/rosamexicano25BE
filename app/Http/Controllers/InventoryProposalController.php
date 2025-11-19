<?php

namespace App\Http\Controllers;

use App\Mail\InventoryProposalMail;
use App\Models\InventoryProposal;
use App\Models\Mailer;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Support\ProductSalesAggregator;
use App\Support\SystemSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class InventoryProposalController extends Controller
{
    private const HORIZONS = [
        '2w' => ['label' => '2 semanas', 'lookback' => 90, 'days' => 14],
        '4w' => ['label' => '4 semanas', 'lookback' => 90, 'days' => 28],
        '6w' => ['label' => '6 semanas', 'lookback' => 90, 'days' => 42],
    ];

    public function index()
    {
        $proposals = InventoryProposal::orderBy('horizon')->get()->map(function (InventoryProposal $proposal) {
            return [
                'horizon' => $proposal->horizon,
                'generated_at' => optional($proposal->generated_at)->toDateTimeString(),
                'lookback_days' => (int) $proposal->lookback_days,
                'lead_time_days' => (int) $proposal->lead_time_days,
                'minimum_inventory_days' => (int) $proposal->minimum_inventory_days,
                'total_items' => is_array($proposal->items) ? count($proposal->items) : 0,
            ];
        });

        return response()->json([
            'proposals' => $proposals,
        ]);
    }

    public function show(string $horizon)
    {
        $key = $this->normalizeHorizon($horizon);
        if (!$key) {
            return response()->json(['message' => 'Horizonte inválido. Usa 2w, 4w o 6w.'], 422);
        }

        $proposal = InventoryProposal::where('horizon', $key)->first();
        if (!$proposal) {
            return response()->json(['message' => 'No hay propuesta registrada para este horizonte.'], 404);
        }

        return response()->json([
            'horizon' => $proposal->horizon,
            'generated_at' => optional($proposal->generated_at)->toDateTimeString(),
            'lookback_days' => (int) $proposal->lookback_days,
            'lead_time_days' => (int) $proposal->lead_time_days,
            'minimum_inventory_days' => (int) $proposal->minimum_inventory_days,
            'items' => $proposal->items ?? [],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof Usuario) {
            return response()->json(['message' => 'Solo administradores pueden generar propuestas de inventario.'], 403);
        }

        $validated = $request->validate([
            'horizon' => ['required', 'string'],
            'lookback_days' => ['sometimes', 'integer', 'min:30', 'max:365'],
        ]);

        $horizon = $this->normalizeHorizon($validated['horizon']);
        if (!$horizon) {
            return response()->json(['message' => 'Horizonte inválido. Usa 2w, 4w o 6w.'], 422);
        }

        $config = self::HORIZONS[$horizon];
        $lookbackDays = isset($validated['lookback_days'])
            ? max(30, min(365, (int) $validated['lookback_days']))
            : (int) $config['lookback'];
        $leadTimeDays = (int) $config['days'];
        $minimumDays = (int) SystemSettings::get('restock_min_days', 14);
        $today = Carbon::today();
        $startDate = $today->copy()->subDays($lookbackDays - 1)->toDateString();
        $endDate = $today->toDateString();

        $products = DB::table('producto as p')
            ->select(['p.ident', 'p.nombre', 'p.proveedorid'])
            ->get();

        if ($products->isEmpty()) {
            return response()->json(['message' => 'No hay productos registrados.'], 422);
        }

        $providers = $products->pluck('proveedorid')
            ->filter()
            ->unique()
            ->values();
        $providerMap = $providers->isEmpty()
            ? collect()
            : Proveedor::whereIn('ident', $providers)->get()->keyBy(fn ($row) => (string) $row->ident);

        $inventoryMap = DB::table('inventario as inv')
            ->select(['inv.ident', 'inv.existencia'])
            ->whereIn('inv.ident', $products->pluck('ident')->filter()->values())
            ->get()
            ->keyBy(fn ($row) => (string) $row->ident);

        $sales = ProductSalesAggregator::aggregate($startDate, $endDate);
        $salesByProduct = $sales->groupBy(fn ($row) => (string) $row->producto_ident);

        $requiredDays = $leadTimeDays + $minimumDays;
        $items = [];
        foreach ($products as $product) {
            $productIdent = (string) $product->ident;
            $salesRows = $salesByProduct->get($productIdent);
            $totalUnits = $salesRows ? $salesRows->sum(fn ($row) => (float) $row->unidades) : 0.0;
            $avgDaily = $totalUnits > 0 ? round($totalUnits / max(1, $lookbackDays), 4) : 0.0;
            $recommendedInventory = (int) max(0, ceil($avgDaily * $requiredDays));
            $inventory = $inventoryMap->get($productIdent);
            $providerId = $product->proveedorid ? (string) $product->proveedorid : null;
            $provider = $providerId ? $providerMap->get($providerId) : null;

            $items[] = [
                'producto_ident' => $productIdent,
                'producto_nombre' => $product->nombre,
                'provider_ident' => $providerId,
                'provider_name' => $provider?->nombre,
                'avg_daily_sales' => $avgDaily,
                'recommended_inventory' => $recommendedInventory,
                'inventory_on_hand' => $inventory ? (int) $inventory->existencia : null,
                'total_units' => round($totalUnits, 4),
            ];
        }

        usort($items, fn ($a, $b) => $b['recommended_inventory'] <=> $a['recommended_inventory']);

        $payload = [
            'lookback_days' => $lookbackDays,
            'lead_time_days' => $leadTimeDays,
            'minimum_inventory_days' => $minimumDays,
            'items' => $items,
            'generated_at' => now(),
        ];

        $proposal = InventoryProposal::updateOrCreate(
            ['horizon' => $horizon],
            $payload
        );

        return response()->json([
            'horizon' => $proposal->horizon,
            'generated_at' => optional($proposal->generated_at)->toDateTimeString(),
            'lookback_days' => $proposal->lookback_days,
            'lead_time_days' => $proposal->lead_time_days,
            'minimum_inventory_days' => $proposal->minimum_inventory_days,
            'items' => $proposal->items,
        ]);
    }

    public function notify(Request $request)
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

        $horizon = $this->normalizeHorizon($data['horizon']);
        if (!$horizon) {
            return response()->json(['message' => 'Horizonte inválido. Usa 2w, 4w o 6w.'], 422);
        }

        $proposal = InventoryProposal::where('horizon', $horizon)->first();
        if (!$proposal) {
            return response()->json(['message' => 'No hay propuesta registrada para este horizonte.'], 404);
        }

        $items = collect($proposal->items ?? []);
        if ($items->isEmpty()) {
            return response()->json(['message' => 'No hay productos registrados en esta propuesta.'], 422);
        }

        $providersFilter = collect($data['providers'] ?? [])
            ->filter(fn ($ident) => (string) $ident !== '')
            ->map(fn ($ident) => (string) $ident)
            ->values();

        $grouped = $items->groupBy(fn ($item) => (string) ($item['provider_ident'] ?? ''));
        $grouped = $grouped->filter(fn ($items, $ident) => $ident !== '');

        if ($grouped->isEmpty()) {
            return response()->json(['message' => 'Ningún producto tiene proveedor asignado para notificar.'], 422);
        }

        if ($providersFilter->isNotEmpty()) {
            $grouped = $grouped->filter(fn ($_, $ident) => $providersFilter->contains($ident));
            if ($grouped->isEmpty()) {
                return response()->json(['message' => 'Los proveedores seleccionados no tienen productos en esta propuesta.'], 422);
            }
        }

        $providerIdents = $grouped->keys()->values();
        $providers = Proveedor::whereIn('ident', $providerIdents)->get()->keyBy(fn ($row) => (string) $row->ident);

        $sent = [];
        $skipped = [];
        $horizonLabel = self::HORIZONS[$horizon]['label'] ?? $horizon;
        $generatedAt = optional($proposal->generated_at)->toDateTimeString() ?? now()->toDateTimeString();

        foreach ($grouped as $ident => $products) {
            $provider = $providers->get($ident);
            $providerName = $provider->nombre ?? ($products->first()['provider_name'] ?? 'Proveedor sin nombre');

            if (!$provider || empty($provider->email)) {
                $skipped[] = [
                    'provider_ident' => $ident,
                    'provider_name' => $providerName,
                    'reason' => 'missing_email',
                ];
                continue;
            }

            $payloadItems = $products->map(function ($item) {
                return [
                    'producto_ident' => $item['producto_ident'],
                    'producto_nombre' => $item['producto_nombre'] ?? null,
                    'recommended_inventory' => (int) $item['recommended_inventory'],
                    'inventory_on_hand' => $item['inventory_on_hand'] !== null ? (int) $item['inventory_on_hand'] : null,
                    'avg_daily_sales' => (float) $item['avg_daily_sales'],
                    'total_units' => (float) $item['total_units'],
                ];
            })->values()->all();

            $mailSubject = sprintf(
                'Propuesta de inventario (%s) - %s',
                $horizonLabel,
                $provider->nombre ?? 'Proveedor'
            );

            $mailViewData = [
                'provider' => $provider,
                'horizonLabel' => $horizonLabel,
                'generatedAt' => $generatedAt,
                'items' => $payloadItems,
            ];

            Mail::to($provider->email)->send(
                new InventoryProposalMail($provider, $horizonLabel, $generatedAt, $payloadItems)
            );

            $body = $this->sanitizeEmailBody(view('emails.inventory_proposal', $mailViewData)->render());

            Mailer::create([
                'mail' => 'inventory_proposal_' . $horizon,
                'email' => $provider->email,
                'asunto' => $mailSubject,
                'mensaje' => $body,
                'status' => 1,
                'fecha' => now()->toDateString(),
            ]);

            $sent[] = [
                'provider_ident' => $ident,
                'provider_name' => $providerName,
                'email' => $provider->email,
            ];
        }

        return response()->json([
            'forecast_date' => $generatedAt,
            'horizon' => $horizon,
            'sent' => count($sent),
            'skipped' => count($skipped),
            'providers_notified' => $sent,
            'providers_skipped' => $skipped,
            'message' => 'Notificaciones enviadas.',
        ]);
    }

    private function normalizeHorizon(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));
        $map = [
            'day' => '2w',
            'week' => '4w',
            'month' => '6w',
            '2weeks' => '2w',
            '4weeks' => '4w',
            '6weeks' => '6w',
        ];

        if (isset($map[$value])) {
            $value = $map[$value];
        }

        return array_key_exists($value, self::HORIZONS) ? $value : null;
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
}
