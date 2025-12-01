<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Sanctum\HasApiTokens;
use App\Models\BiometricCredential;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\WebAuthnData;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class Usuario extends Authenticatable implements WebAuthnAuthenticatable
{
    use HasApiTokens, HasFactory, WebAuthnAuthentication;

    protected $table = 'usuarios';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = ['nombre','email','password','puesto','priv1','priv2','priv3','priv4','role','modules','staff_role_id'];

    protected $hidden = ['password'];

    // helpful default for role
    protected $attributes = [
        'puesto' => 'admin',
        'priv1'  => 1,
        'priv2'  => 1,
        'priv3'  => 1,
        'priv4'  => 1,
        'role'   => 'admin',
    ];

    protected $casts = [
        'modules' => 'array',
    ];

    public function staffRole()
    {
        return $this->belongsTo(StaffRole::class, 'staff_role_id');
    }

    public function biometricCredentials(): MorphMany
    {
        return $this->morphMany(BiometricCredential::class, 'authenticatable');
    }

    public function webAuthnData(): WebAuthnData
    {
        $displayName = $this->nombre ?: ($this->email ?? 'Admin');

        return WebAuthnData::make($this->email ?? (string) $this->getAuthIdentifier(), $displayName);
    }

    public function webAuthnId(): UuidInterface
    {
        $source = 'usuario-' . $this->getAuthIdentifier();
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $source);
    }
}
