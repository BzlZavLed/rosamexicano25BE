<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Mailer extends Model
{
    protected $table = 'mailer';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'mail',
        'email',
        'asunto',
        'mensaje',
        'status',
        'fecha',
    ];

    protected static function booted(): void
    {
        static::created(function (Mailer $mailer) {
            if ((int) $mailer->status !== 1) {
                return;
            }

            $date = $mailer->fecha ? (Carbon::make($mailer->fecha) ?? now()) : now();
            MailerTrack::incrementForDate($date);
        });
    }
}
