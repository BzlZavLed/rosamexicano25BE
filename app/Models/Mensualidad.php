<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Proveedor;

class Mensualidad extends Model
{
    protected $table = 'mensualidad';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'fecha',
        'nombre',
        'concepto',
        'mes_cobro',
        'nota',
        'importe',
        'proveedor_id',
        'cantidad_pago',
        'restante',
        'pago_completo',
        'status',
        'mail_status',
        'payment_date',
        'receipt_path',
        'cobro_path',
    ];

    protected $casts = [
        'fecha'        => 'date',
        'payment_date' => 'date',
        'importe'      => 'decimal:2',
        'cantidad_pago'=> 'decimal:2',
        'restante'     => 'decimal:2',
        'pago_completo'=> 'boolean',
        'mail_status'  => 'boolean',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id', 'id');
    }
}
