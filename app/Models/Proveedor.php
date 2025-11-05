<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;

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

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if ($model->ident !== null && ($model->isDirty('ident') || empty($model->passhash))) {
                $model->passhash = Hash::make((string) $model->ident);
            }
        });
    }

    public function setTelAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['tel'] = null;
            return;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);
        $digits = $digits !== null ? trim($digits) : '';
        $this->attributes['tel'] = $digits !== '' ? $digits : null;
    }

    // Providers log in with phone number
    public function getAuthIdentifierName()
    {
        return 'tel';
    }
}
