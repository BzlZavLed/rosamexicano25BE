<?php

namespace App\Support;

use App\Models\DailyCashSummary;
use Illuminate\Support\Carbon;

class CashboxAutoCloser
{
    public static function closePending(): array
    {
        $today = Carbon::today();

        $pending = DailyCashSummary::query()
            ->where(function ($query) {
                $query->whereNull('saldo_cierre')
                    ->orWhere('saldo_cierre', '=', 0);
            })
            ->whereDate('fecha', '<=', $today->toDateString())
            ->get();

        if ($pending->isEmpty()) {
            return ['count' => 0, 'dates' => []];
        }

        $dates = [];

        $pending->each(function (DailyCashSummary $summary) use (&$dates) {
            $saldoInicial = (float) ($summary->saldo_inicial ?? 0);
            $efectivo = (float) ($summary->efectivo ?? 0);
            $egresos = (float) ($summary->egresos ?? 0);

            $summary->saldo_cierre = round($saldoInicial + $efectivo - $egresos, 2);
            $summary->save();

            if ($summary->fecha) {
                $dates[] = Carbon::parse($summary->fecha)->toDateString();
            }

            //self::recalculateFutureSummaries($summary);
        });

        $uniqueDates = array_values(array_unique($dates));

        return [
            'count' => $pending->count(),
            'dates' => $uniqueDates,
        ];
    }
    private static function recalculateFutureSummaries(DailyCashSummary $closedSummary): void
    {
        $fecha = $closedSummary->fecha;
        if (!$fecha) {
            return;
        }

        $next = DailyCashSummary::query()
            ->where('fecha', '>', $fecha)
            ->orderBy('fecha')
            ->get();

        $previousClosing = (float) $closedSummary->saldo_cierre;

        foreach ($next as $summary) {
            $summary->saldo_inicial = $previousClosing;
            $efectivo = (float) ($summary->efectivo ?? 0);
            $egresos = (float) ($summary->egresos ?? 0);
            $summary->saldo_cierre = round($summary->saldo_inicial + $efectivo - $egresos, 2);
            $summary->save();
            $previousClosing = (float) $summary->saldo_cierre;
        }
    }
}
