<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $table = 'ventas';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'idventa',
        'totalventa',
        'ingreso_real',
        'metodo',
        'recibo',
        'cambio',
        'vendedor',
        'fecha',
        'ie',
        'concepto',
        'subtotal',
        'tarjeta_cargo',
        'costo_total',
        'ganancia_total',
    ];

    public function lineas()
    {
        // business key join (your schema uses idventa as link)
        return $this->hasMany(VentaDesg::class, 'idventa', 'idventa');
    }

}
