<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventario extends Model
{
    protected $table = 'inventario';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'ident',        // producto.ident (barcode)
        'existencia',   // integer (current stock)
        'importe',      // numeric(11,2) = existencia * producto.precio
        'provee',       // proveedores.ident
        'precio_individual' // numeric(11,2) = last individual price set
    ];

    protected $casts = [
        'ident'      => 'integer',
        'existencia' => 'integer',
        'importe'    => 'decimal:2',
        'provee'     => 'integer',
        'precio_individual' => 'decimal:2',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'ident', 'ident');
    }

    public function proveedor()
    {
        // through producto (or directly if you keep proveedorid in inventario)
        return $this->producto()->belongsTo(Proveedor::class, 'proveedorid', 'ident');
    }
}
