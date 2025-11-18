<?php

namespace App\Support;

use App\Models\SystemSetting;

class SystemSettings
{
    public static function get(string $key, $default = null)
    {
        $setting = SystemSetting::find($key);
        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, $value): void
    {
        SystemSetting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
