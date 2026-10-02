<?php

namespace App\Models;

/**
 * Affiliate programme account (`shd_affiliates`), one row per participating
 * client. `balance` is the withdrawable commission, `audited_balance` the
 * commission still awaiting confirmation and `withdraw_ing` the amount held by
 * a pending payout request.
 */
class Affiliates extends ShdModel
{
    protected $table = 'affiliates';

    protected $casts = [
        'date' => 'integer',
        'uid' => 'integer',
        'visitors' => 'integer',
        'registcount' => 'integer',
        'payamount' => 'integer',
        'onetime' => 'integer',
        'audited_balance' => 'decimal:2',
        'balance' => 'decimal:2',
        'withdrawn' => 'decimal:2',
        'withdraw_ing' => 'decimal:2',
        'created_time' => 'integer',
        'updated_time' => 'integer',
        'sum' => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }
}
