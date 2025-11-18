<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderRestockForecast extends Model
{
    protected $table = 'provider_restock_forecasts';

    protected $fillable = [
        'forecast_date',
        'horizon',
        'provider_ident',
        'provider_name',
        'producto_ident',
        'producto_nombre',
        'avg_daily_sales',
        'lookback_days',
        'lead_time_days',
        'projected_demand',
        'inventory_on_hand',
        'suggested_order_qty',
        'days_of_cover',
        'details',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'avg_daily_sales' => 'decimal:4',
        'projected_demand' => 'decimal:4',
        'days_of_cover' => 'decimal:2',
        'details' => 'array',
    ];
}
