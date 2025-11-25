<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaCancelacion extends Model
{
    protected $table = 'venta_cancelaciones';

    protected $fillable = [
        'venta_id',
        'idventa',
        'admin_id',
        'reason',
        'venta_payload',
        'lineas_payload',
    ];

    protected $casts = [
        'venta_payload' => 'array',
        'lineas_payload' => 'array',
    ];

    public function admin()
    {
        return $this->belongsTo(Usuario::class, 'admin_id');
    }
}
