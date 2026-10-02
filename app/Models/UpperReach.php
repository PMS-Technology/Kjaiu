<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Manually managed upstream resource (`shd_upper_reaches`).
 */
class UpperReach extends ShdModel
{
    protected $table = 'upper_reaches';

    protected $casts = [
        'create_time' => 'integer',
    ];

    public function resources(): HasMany
    {
        return $this->hasMany(UpperReachRes::class, 'hid');
    }

    public function ips(): HasMany
    {
        return $this->hasMany(UpperReachIp::class, 'hid');
    }
}
