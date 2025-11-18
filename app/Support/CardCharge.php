<?php

namespace App\Support;

class CardCharge
{
    protected static ?float $cachedPercent = null;

    public static function percent(): float
    {
        if (static::$cachedPercent === null) {
            $value = SystemSettings::get('card_charge_percent', '4.5');
            static::$cachedPercent = max(0.0, (float) $value);
        }
        return static::$cachedPercent;
    }

    public static function rate(): float
    {
        return static::percent() / 100.0;
    }

    public static function refresh(): void
    {
        static::$cachedPercent = null;
    }
}
