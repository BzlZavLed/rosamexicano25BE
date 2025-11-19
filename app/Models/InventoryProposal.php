<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryProposal extends Model
{
    protected $fillable = [
        'horizon',
        'lookback_days',
        'lead_time_days',
        'minimum_inventory_days',
        'items',
        'generated_at',
    ];

    protected $casts = [
        'items' => 'array',
        'generated_at' => 'datetime',
    ];
}
