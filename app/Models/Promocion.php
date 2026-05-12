<?php

// app/Models/Promocion.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promocion extends Model
{
    protected $table = 'promociones';
    public $timestamps = false;

    protected $fillable = [
        'producto',       // int -> producto.ident (nullable)
        'proveedor',      // int -> proveedores.ident (nullable)
        'tipo',           // 'descuento'|'gratis'
        'descuento',      // numeric %
        'monto',          // fixed price total for N products
        'mincompra',      // int
        'gratis',         // int
        'inicia',         // date
        'vence',          // date
        'estado',         // bool
    ];

    protected $casts = [
        'descuento' => 'float',
        'monto' => 'float',
        'mincompra' => 'integer',
        'gratis' => 'integer',
        'inicia' => 'date',
        'vence' => 'date',
        'estado' => 'boolean',
    ];

    // Relations (using idents)
    public function productoRef()
    {
        // promociones.producto (ident) -> producto.ident
        return $this->belongsTo(Producto::class, 'producto', 'ident');
    }

    public function proveedorRef()
    {
        // promociones.proveedor (ident) -> proveedores.ident
        return $this->belongsTo(Proveedor::class, 'proveedor', 'ident');
    }

    /** Is this promotion active "now"? (simple helper) */
    public function getActivaAttribute(): bool
    {
        if (!$this->estado)
            return false;
        $today = now()->startOfDay();
        if ($this->inicia && $today->lt($this->inicia))
            return false;
        if ($this->vence && $today->gt($this->vence))
            return false;
        return true;
    }
}
