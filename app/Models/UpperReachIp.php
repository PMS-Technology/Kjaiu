<?php

namespace App\Models;

/**
 * IP assignment for a manual upstream resource (`shd_upper_reaches_ip`).
 */
class UpperReachIp extends ShdModel
{
    protected $table = 'upper_reaches_ip';

    protected $casts = [
        'hid' => 'integer',
        'res_id' => 'integer',
    ];
}
