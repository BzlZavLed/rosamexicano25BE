<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'producto';   // existing table
    protected $primaryKey = 'id';
    public $timestamps = false;

    // 'ident' is your barcode/sku; 'proveedorid' ties to proveedores.id
    protected $fillable = [
        'ident',
        'nombre',
        'descripcion',
        'fecha',
        'proveedorid',
        'usuario',
        'precio'
    ];

    protected $casts = [
        'ident' => 'integer',
        'proveedorid' => 'integer',
        'precio' => 'decimal:2',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedorid', 'ident');
    }
    public function inventario()
    {
        // inventario.ident ↔ productos.ident
        return $this->hasOne(Inventario::class, 'ident', 'ident');
    }
}
