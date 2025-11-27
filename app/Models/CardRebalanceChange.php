<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardRebalanceChange extends Model
{
    protected $table = 'card_rebalance_changes';

    protected $fillable = [
        'venta_id',
        'ventadesg_id',
        'fecha_sale',
        'public_total',
        'total_venta',
        'old_credit_card_discount',
        'new_credit_card_discount',
        'proveedor_id',
    ];
}
