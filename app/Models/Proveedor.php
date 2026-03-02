<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\BiometricCredential;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\WebAuthnData;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class Proveedor extends Authenticatable implements WebAuthnAuthenticatable
{
    use HasApiTokens, WebAuthnAuthentication;

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
        'tipo',
        'porcentaje_comision',
        // 'passhash',
    ];

    protected $casts = [
        'ident'   => 'integer',
        'importe' => 'decimal:2',
        'porcentaje_comision' => 'integer',
    ];

    protected $hidden = ['passhash'];

    /**
     * Prefer ident as canonical route key while keeping backward compatibility
     * with legacy links that still send the internal numeric id.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = static::query();

        if ($field !== null) {
            return $query->where($field, $value)->firstOrFail();
        }

        return $query
            ->where('ident', $value)
            ->orWhere('id', $value)
            ->firstOrFail();
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'proveedorid', 'ident');
    }

    public function recommendedImporte()
    {
        return $this->hasOne(RecommendedImporte::class, 'provider_ident', 'ident');
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

    public function biometricCredentials(): MorphMany
    {
        return $this->morphMany(BiometricCredential::class, 'authenticatable');
    }

    public function webAuthnData(): WebAuthnData
    {
        $displayName = $this->nombre ?: 'Proveedor';
        return WebAuthnData::make($this->tel ?? (string) $this->ident, $displayName);
    }

    public function webAuthnId(): UuidInterface
    {
        $source = 'proveedor-' . $this->getAuthIdentifier();
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $source);
    }
}
