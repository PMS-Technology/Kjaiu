<?php

namespace App\Models;

/**
 * Product / configurable-option upgrade (`shd_upgrades`).
 *
 * One row is written when an upgrade order is generated; it is settled by the
 * payment callback that marks the linked invoice paid.
 */
class Upgrade extends ShdModel
{
    protected $table = 'upgrades';

    protected $casts = [
        'uid' => 'integer',
        'order_id' => 'integer',
        'date' => 'integer',
        'relid' => 'integer',
        'amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'days_remaining' => 'integer',
        'total_days_in_cycle' => 'integer',
        'new_recurring_amount' => 'decimal:2',
        'recurring_change' => 'decimal:2',
    ];

    public const STATUS_PENDING = 'Pending';
    public const STATUS_COMPLETED = 'Completed';

    public const TYPE_PRODUCT = 'product';
    public const TYPE_CONFIG_OPTIONS = 'configoptions';

    public function host()
    {
        return $this->belongsTo(Host::class, 'relid');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }
}
