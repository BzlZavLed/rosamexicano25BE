<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens;

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
}
