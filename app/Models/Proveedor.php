<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Proveedor extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'proveedores';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'ident',
        'nombre',
        'fecha',
        'tel',
        'email',
        'calle',
        'bancaria',
        'ciudad',
        'importe',
        'sucursal',
        // 'passhash', // <- add this ONLY if you'll mass-assign it
    ];

    protected $casts = [
        'ident'   => 'integer',
        'importe' => 'decimal:2',
    ];

    protected $hidden = ['passhash'];

    public function productos()
    {
        return $this->hasMany(Producto::class, 'proveedorid', 'id');
    }

    // Providers log in with phone number
    public function getAuthIdentifierName()
    {
        return 'tel';
    }
}
