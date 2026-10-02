<?php

namespace App\Models;

/**
 * In-site message (`shd_system_message`).
 */
class SystemMessage extends ShdModel
{
    protected $table = 'system_message';

    protected $casts = [
        'uid' => 'integer',
        'is_market' => 'integer',
        'create_time' => 'integer',
        'read_time' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }

    public function isRead(): bool
    {
        return (int) $this->read_time > 0;
    }
}
