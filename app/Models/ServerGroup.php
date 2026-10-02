<?php

namespace App\Models;

/**
 * Provisioning server group (`shd_server_groups`).
 *
 * `type` is the module identifier (bthosts, proxmoxve, nokvm, ...) and
 * `system_type` distinguishes local modules from upstream ones.
 */
class ServerGroup extends ShdModel
{
    protected $table = 'server_groups';

    protected $casts = [
        'capacity' => 'integer',
        'mode' => 'integer',
    ];

    public function servers()
    {
        return $this->hasMany(Server::class, 'gid');
    }

    public function isUpstream(): bool
    {
        return (string) $this->system_type === 'zjmf_api';
    }
}
