<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class MailerTrack extends Model
{
    protected $table = 'mailer_track';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'sent_count' => 'integer',
    ];
    protected $fillable = [
        'year',
        'month',
        'sent_count',
    ];

    public static function incrementForDate($date): void
    {
        $carbon = $date instanceof Carbon ? $date : (Carbon::make($date) ?? now());
        $year = (int) $carbon->format('Y');
        $month = (int) $carbon->format('n');

        $track = static::firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['sent_count' => 0]
        );

        $track->increment('sent_count');
    }
}
