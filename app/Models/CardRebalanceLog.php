<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardRebalanceLog extends Model
{
    protected $table = 'card_rebalance_logs';

    protected $fillable = [
        'date_param',
        'venta_id',
        'sales_processed',
        'sales_updated',
        'lines_updated',
        'sale_ids',
        'message',
        'triggered_by',
        'triggered_by_name',
    ];
}
