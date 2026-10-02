<?php

namespace App\Models;

/**
 * Client login record (`shd_activity_log`); the client area shows these in the
 * 登录日志 view.
 */
class LoginLog extends ShdModel
{
    protected $table = 'activity_log';

    protected $casts = [
        'uid' => 'integer',
        'create_time' => 'integer',
        'type' => 'integer',
    ];
}
