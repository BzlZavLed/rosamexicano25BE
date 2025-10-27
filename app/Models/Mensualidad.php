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
        'status',
        'payment_date',
        'receipt_path',
    ];

    protected $casts = [
        'fecha'        => 'date',
        'payment_date' => 'date',
        'importe'      => 'decimal:2',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id', 'id');
    }
}
