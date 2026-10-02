<?php

namespace App\Models;

/**
 * Pending billing-cycle change (`shd_renew_cycle`).
 *
 * Written when a customer renews onto a different cycle; the cron runner
 * applies the new recurring amount once the renewal invoice is paid.
 */
class RenewCycle extends ShdModel
{
    protected $table = 'renew_cycle';

    protected $casts = [
        'uid' => 'integer',
        'relid' => 'integer',
        'new_recurring_amount' => 'decimal:2',
        'recurringchange' => 'decimal:2',
        'create_time' => 'integer',
        'expire_time' => 'integer',
        'delete_time' => 'integer',
        'duration' => 'integer',
    ];

    public const STATUS_PENDING = 'Pending';
    public const STATUS_COMPLETED = 'Completed';

    public function host()
    {
        return $this->belongsTo(Host::class, 'relid');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }

    public function isPending(): bool
    {
        return (string) $this->status === self::STATUS_PENDING;
    }
}
