<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $table = 'ventas';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'idventa',
        'totalventa',
        'total_recibido',
        'metodo',
        'cambio',
        'vendedor',
        'fecha',
        'hora',
        'receipt_printed',
        'receipt_emailed',
    ];

    protected $casts = [
        'receipt_printed' => 'boolean',
        'receipt_emailed' => 'boolean',
        'fecha' => 'date',
    ];

    public function lineas()
    {
        // business key join (your schema uses idventa as link)
        return $this->hasMany(VentaDesg::class, 'idventa', 'idventa');
    }

}
