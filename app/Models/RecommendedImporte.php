<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecommendedImporte extends Model
{
    use HasFactory;

    protected $fillable = [
        'proveedor_id',
        'provider_ident',
        'provider_name',
        'provider_email',
        'current_importe',
        'avg_monthly_sales',
        'recommended_importe',
        'total_sales',
        'months',
        'is_recommended',
        'percentage_used',
        'months_window',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'current_importe' => 'decimal:2',
        'avg_monthly_sales' => 'decimal:2',
        'recommended_importe' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'is_recommended' => 'boolean',
        'percentage_used' => 'decimal:2',
        'months_window' => 'integer',
        'months' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }
}
