<?php

namespace App\Console\Commands;

use App\Models\CardRebalanceChange;
use App\Models\Venta;
use App\Models\VentaDesg;
use App\Support\CardCharge;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebalanceCardFees extends Command
{
    protected $signature = 'card:rebalance {date? : Fecha (yyyy-mm-dd). Requerida si no se especifica --venta_id} {--venta_id= : ID de venta a recalcular (opcional; requerido si no hay fecha)} {--user_id=} {--user_name=}';

    protected $description = 'Recalcula el cargo por tarjeta (4.5%) prorrateado por proveedor y actualiza credit_card_discount en ventadesg. Requiere fecha o --venta_id.';

    public function handle(): int
    {
        $dateInput = $this->argument('date');
        $ventaId = $this->option('venta_id');

        if (!$dateInput && !$ventaId) {
            $this->error('Debes proporcionar una fecha o un --venta_id.');
            return self::FAILURE;
        }

        $date = null;
        if ($dateInput) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $dateInput)->toDateString();
            } catch (\Throwable $e) {
                $this->error('Formato de fecha inválido. Usa yyyy-mm-dd.');
                return self::FAILURE;
            }
        } elseif ($ventaId) {
            $ventaDate = Venta::where('idventa', (int) $ventaId)->value('fecha');
            if (!$ventaDate) {
                $this->error('No se pudo determinar la fecha de la venta indicada.');
                return self::FAILURE;
            }
            $date = $ventaDate instanceof \DateTimeInterface
                ? $ventaDate->toDateString()
                : date('Y-m-d', strtotime((string) $ventaDate));
        }

        $rate = CardCharge::rate();
        if ($rate <= 0) {
            $this->warn('La tasa de tarjeta es 0; no hay nada que recalcular.');
            return self::SUCCESS;
        }

        $query = Venta::with('lineas')
            ->where('metodo', 'tarjeta')
            ->when($ventaId, fn ($q) => $q->where('idventa', (int) $ventaId))
            ->orderBy('idventa');

        if ($date) {
            $query->where(function ($q) use ($date) {
                $q->whereDate('fecha', $date)->orWhere('fecha', $date);
            });
        }

        $ventas = $query->get();
        if ($ventas->isEmpty()) {
            $this->info('No se encontraron ventas con tarjeta para recalcular.');
            return self::SUCCESS;
        }

        $updatedSales = 0;
        $updatedLines = 0;

        foreach ($ventas as $venta) {
            if (strtolower($venta->metodo ?? '') !== 'tarjeta') {
                $this->info(sprintf('Venta %d saltada: método %s.', $venta->idventa, $venta->metodo));
                continue;
            }

            $lineas = $venta->lineas;
            if ($lineas->isEmpty()) {
                $this->info(sprintf('Venta %d sin líneas, se omite.', $venta->idventa));
                continue;
            }

            $lineUpdates = 0;

            DB::transaction(function () use ($venta, $lineas, $rate, &$lineUpdates) {
                foreach ($lineas as $line) {
                    $base = max(
                        0,
                        (float) ($line->public_total ?? 0)
                        - (float) ($line->promotion_discount_amount ?? 0)
                        - (float) ($line->manual_discount_amount ?? 0)
                    );
                    $new = round($base * $rate, 2);
                    $old = (float) ($line->credit_card_discount ?? 0);
                    $provId = $line->proveedor_id ?? $line->proveedor ?? null;

                    $this->info(sprintf(
                        'Venta %d linea %d prov %s: base=%.2f, cargo_calculado=%.2f, actual=%.2f',
                        $venta->idventa,
                        $line->id,
                        $provId ?? '—',
                        $base,
                        $new,
                        $old
                    ));

                    CardRebalanceChange::create([
                        'venta_id' => (int) $venta->idventa,
                        'ventadesg_id' => (int) $line->id,
                        'fecha_sale' => $venta->fecha instanceof \DateTimeInterface ? $venta->fecha->toDateString() : date('Y-m-d', strtotime((string) $venta->fecha)),
                        'public_total' => (float) ($line->public_total ?? 0),
                        'total_venta' => (float) ($venta->totalventa ?? 0),
                        'old_credit_card_discount' => $old,
                        'new_credit_card_discount' => $new,
                        'proveedor_id' => $provId,
                    ]);

                    if ($old !== $new) {
                        $line->credit_card_discount = $new;
                        $line->provider_payment = round(max(0, (float) ($line->provider_payment ?? 0) - ($new - $old)), 2);
                        $line->save();
                        $lineUpdates++;
                    }
                }
            });

            if ($lineUpdates > 0) {
                $updatedSales++;
                $updatedLines += $lineUpdates;
                $this->info(sprintf('Venta %d: cargo tarjeta recalculado en %d renglones.', $venta->idventa, $lineUpdates));
            } else {
                $this->info(sprintf('Venta %d: sin cambios aplicados.', $venta->idventa));
            }
        }

        $this->info(sprintf('Proceso completado: ventas actualizadas %d, renglones actualizados %d.', $updatedSales, $updatedLines));
        return self::SUCCESS;
    }
}
