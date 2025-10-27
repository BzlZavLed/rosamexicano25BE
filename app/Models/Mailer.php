<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mailer extends Model
{
    protected $table = 'mailer';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'mail',
        'asunto',
        'mensaje',
        'status',
        'fecha',
    ];
}

