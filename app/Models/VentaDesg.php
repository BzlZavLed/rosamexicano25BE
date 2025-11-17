<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaDesg extends Model
{
    protected $table = 'ventadesg';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'idventa',
        'fecha',
        'hora',
        'producto_id',
        'nombre',
        'proveedor_id',
        'unit_price',
        'quantity',
        'free_quantity',
        'public_total',
        'venta_total',
        'promotion_discount_percentage',
        'promotion_discount_amount',
        'manual_discount_amount',
        'credit_card_discount',
        'provider_percentage_discount',
        'consigna_discount',
        'provider_cost',
        'provider_payment',
        'admin_earnings',
        'free_product',
    ];

    protected $casts = [
        'promotion_discount_percentage' => 'decimal:2',
        'promotion_discount_amount' => 'decimal:2',
        'manual_discount_amount' => 'decimal:2',
        'credit_card_discount' => 'decimal:2',
        'provider_percentage_discount' => 'decimal:2',
        'consigna_discount' => 'decimal:2',
        'provider_cost' => 'decimal:2',
        'provider_payment' => 'decimal:2',
        'admin_earnings' => 'decimal:2',
        'has_promotion' => 'boolean',
        'free_product' => 'boolean',
        'fecha' => 'date',
    ];
}
