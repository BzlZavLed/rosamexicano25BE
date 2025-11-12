<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaDesg extends Model
{
    protected $table = 'ventadesg';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'idventa','fecha','idprod','nombre','proveedor','puni','cant','total','descuento_producto','promotion','hora','cargo_tarjeta_proveedor','proveedor_pago','proveedor_porcentaje',
    ];

    protected $casts = [
        'descuento_producto' => 'decimal:2',
        'promotion' => 'string',
        'proveedor_pago' => 'decimal:2',
        'proveedor_porcentaje' => 'decimal:2',
    ];
}
