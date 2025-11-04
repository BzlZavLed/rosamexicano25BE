<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entrada extends Model
{
    protected $table = 'entradas';
    public $timestamps = false;

    protected $fillable = [
        'prodnombre',
        'prodid',
        'provid',
        'ingreal',
        'fecha',
        'accion',
        'usuario',
    ];

    protected $casts = [
        'ingreal' => 'integer',
        'accion' => 'integer',
    ];
}
