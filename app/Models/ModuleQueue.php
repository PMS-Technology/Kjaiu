<?php

namespace App\Models;

/**
 * Provisioning task queue (`shd_module_queue`).
 *
 * Checked by the cron runner every minute; each row is one module call that
 * still needs to run against a provisioning server.
 */
class ModuleQueue extends ShdModel
{
    protected $table = 'module_queue';

    protected $casts = [
        'service_id' => 'integer',
        'last_attempt' => 'integer',
        'num_retries' => 'integer',
        'completed' => 'integer',
        'create_time' => 'integer',
    ];

    public const SERVICE_HOST = 'host';
    public const SERVICE_DOMAIN = 'domain';

    public function host()
    {
        return $this->belongsTo(Host::class, 'service_id');
    }

    public function isCompleted(): bool
    {
        return (int) $this->completed === 1;
    }
}
