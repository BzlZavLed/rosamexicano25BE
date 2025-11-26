<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffRole extends Model
{
    use HasFactory;

    protected $table = 'staff_roles';

    protected $fillable = [
        'name',
        'slug',
        'base_role',
        'modules',
        'is_default',
    ];

    protected $casts = [
        'modules' => 'array',
        'is_default' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(Usuario::class, 'staff_role_id');
    }
}
