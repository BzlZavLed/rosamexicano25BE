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
        });

        $uniqueDates = array_values(array_unique($dates));

        return [
            'count' => $pending->count(),
            'dates' => $uniqueDates,
        ];
    }
}
