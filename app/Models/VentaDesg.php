<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaDesg extends Model
{
    protected $table = 'ventadesg';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'idventa',
        'fecha',
        'idprod',
        'nombre',
        'proveedor',
        'puni',
        'cant',
        'total',
        'descuento_producto',
        'promotion',
        'hora',
        'cargo_tarjeta_proveedor',
        'proveedor_porcentaje',
        'proveedor_bruto',
        'proveedor_descuento',
        'proveedor_neto',
        'admin_ganancia',
    ];

    protected $casts = [
        'descuento_producto' => 'decimal:2',
        'promotion' => 'string',
        'proveedor_porcentaje' => 'decimal:2',
        'proveedor_bruto' => 'decimal:2',
        'proveedor_descuento' => 'decimal:2',
        'proveedor_neto' => 'decimal:2',
        'admin_ganancia' => 'decimal:2',
        'cargo_tarjeta_proveedor' => 'decimal:2',
    ];
}
