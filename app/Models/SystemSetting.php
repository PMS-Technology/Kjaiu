<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['mail_configuration'];

    protected function casts(): array
    {
        return ['mail_configuration' => 'encrypted:array'];
    }
}
