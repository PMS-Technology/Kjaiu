<?php

namespace App\Models;

/**
 * Provisioning server (`shd_servers`).
 */
class Server extends ShdModel
{
    protected $table = 'servers';

    protected $hidden = ['password', 'accesshash'];

    protected $casts = [
        'gid' => 'integer',
        'monthly_cost' => 'decimal:2',
        'secure' => 'integer',
        'active' => 'integer',
        'disabled' => 'integer',
        'port' => 'integer',
        'link_status' => 'integer',
    ];

    public function group()
    {
        return $this->belongsTo(ServerGroup::class, 'gid');
    }
}
