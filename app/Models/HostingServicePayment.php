<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostingServicePayment extends Model
{
    protected $table = 'hosting_service_payments';

    protected $fillable = [
        'implementation_key',
        'implementation_name',
        'service_month',
        'due_date',
        'amount',
        'paid',
        'paid_at',
    ];

    protected $casts = [
        'service_month' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'paid' => 'boolean',
        'paid_at' => 'date',
    ];
}
