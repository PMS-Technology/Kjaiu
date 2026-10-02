<?php

namespace App\Models;

/**
 * A single resource row under a manual upstream (`shd_upper_reaches_res`).
 */
class UpperReachRes extends ShdModel
{
    protected $table = 'upper_reaches_res';

    protected $casts = [
        'hid' => 'integer',
        'create_time' => 'integer',
    ];

    public function upstream()
    {
        return $this->belongsTo(UpperReach::class, 'hid');
    }
}
