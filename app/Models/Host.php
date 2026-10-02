<?php

namespace App\Models;

/**
 * Provisioned service (`shd_host`).
 */
class Host extends ShdModel
{
    protected $table = 'host';

    protected $casts = [
        'uid' => 'integer',
        'orderid' => 'integer',
        'productid' => 'integer',
        'serverid' => 'integer',
        'firstpaymentamount' => 'decimal:2',
        'amount' => 'decimal:2',
        'regdate' => 'integer',
        'nextduedate' => 'integer',
        'nextinvoicedate' => 'integer',
        'termination_date' => 'integer',
        'suspend_time' => 'integer',
        'initiative_renew' => 'integer',
        'port' => 'integer',
    ];

    public const STATUS_PENDING = 'Pending';
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_SUSPENDED = 'Suspended';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_TERMINATED = 'Terminated';
    public const STATUS_FRAUD = 'Fraud';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_DELETED = 'Deleted';

    /** Statuses that still count as a live, billable service. */
    public const LIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'productid');
    }

    public function server()
    {
        return $this->belongsTo(Server::class, 'serverid');
    }

    public function isActive(): bool
    {
        return (string) $this->domainstatus === self::STATUS_ACTIVE;
    }

    public function isLive(): bool
    {
        return in_array((string) $this->domainstatus, self::LIVE_STATUSES, true);
    }

    public function isSuspended(): bool
    {
        return (string) $this->domainstatus === self::STATUS_SUSPENDED;
    }

    /**
     * Change status, recording the timestamp the original schema expects.
     */
    public function markAs(string $status): void
    {
        $this->domainstatus = $status;

        if ($status === self::STATUS_SUSPENDED) {
            $this->suspend_time = time();
        }

        if (in_array($status, [self::STATUS_CANCELLED, self::STATUS_TERMINATED], true)) {
            $this->termination_date = time();
        }

        $this->update_time = time();
        $this->save();
    }
}
