<?php

namespace App\Models;

/**
 * Affiliate payout request (`shd_affiliates_withdraw`).
 *
 * status: 1 待审核, 2 审核通过, 3 拒绝.
 * type:   1 提现到余额, 2 仅记录, 3 流水支持.
 */
class AffiliatesWithdraw extends ShdModel
{
    protected $table = 'affiliates_withdraw';

    protected $casts = [
        'uid' => 'integer',
        'num' => 'decimal:2',
        'type' => 'integer',
        'admin_id' => 'integer',
        'create_time' => 'integer',
        'update_time' => 'integer',
        'status' => 'integer',
    ];

    public const STATUS_PENDING = 1;
    public const STATUS_APPROVED = 2;
    public const STATUS_REJECTED = 3;

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }

    public function isPending(): bool
    {
        return (int) $this->status === self::STATUS_PENDING;
    }
}
