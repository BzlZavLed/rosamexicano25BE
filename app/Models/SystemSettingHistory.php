<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSettingHistory extends Model
{
    protected $table = 'system_settings_history';

    protected $fillable = [
        'key',
        'old_value',
        'new_value',
        'changed_by',
        'changed_by_name',
    ];
}
