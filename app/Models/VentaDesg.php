<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaDesg extends Model
{
    protected $table = 'ventadesg';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'idventa','fecha','idprod','nombre','proveedor','puni','cant','total','product_desc','hora'
    ];
}
