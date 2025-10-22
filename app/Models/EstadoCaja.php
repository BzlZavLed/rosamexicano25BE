<?php

// app/Models/EstadoCaja.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoCaja extends Model
{
    protected $table = 'estadocaja';
    public $timestamps = false;

    protected $fillable = [
        'estado',        // int: 1 abierta, 0 cerrada
        'fecha',         // varchar(10) d/m/y
        'saldoinicial',  // float
        'saldofinal',    // float
        'saldosistema',  // float
        'usuario',       // varchar
    ];

    protected $casts = [
        'estado'        => 'integer',
        'saldoinicial'  => 'float',
        'saldofinal'    => 'float',
        'saldosistema'  => 'float',
    ];
}

