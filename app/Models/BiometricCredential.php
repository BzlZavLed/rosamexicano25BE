<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BiometricCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'credential_id',
        'identifier',
        'token_hash',
        'device_label',
        'user_agent',
        'authenticatable_type',
        'authenticatable_id',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
