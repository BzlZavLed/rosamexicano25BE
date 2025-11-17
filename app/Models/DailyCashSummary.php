<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyCashSummary extends Model
{
    protected $table = 'daily_cash_summaries';

    protected $fillable = [
        'fecha',
        'saldo_inicial',
        'efectivo',
        'transferencia',
        'tarjeta',
        'egresos',
        'saldo_cierre',
    ];

    protected $casts = [
        'fecha' => 'date',
        'saldo_inicial' => 'decimal:2',
        'efectivo' => 'decimal:2',
        'transferencia' => 'decimal:2',
        'tarjeta' => 'decimal:2',
        'egresos' => 'decimal:2',
        'saldo_cierre' => 'decimal:2',
    ];
}
