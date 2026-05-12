<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use SoftDeletes;

    protected $table = 'producto';   // existing table
    protected $primaryKey = 'id';
    public $timestamps = false;

    // 'ident' is your barcode/sku; 'proveedorid' ties to proveedores.ident
    protected $fillable = [
        'ident',
        'nombre',
        'descripcion',
        'fecha',
        'proveedorid',
        'usuario',
        'precio',
        'precio_proveedor',
    ];

    protected $casts = [
        'ident' => 'integer',
        'proveedorid' => 'integer',
        'precio' => 'decimal:2',
        'precio_proveedor' => 'decimal:2',
        'deleted_at' => 'datetime',
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
