<?php

namespace App\Models;

/**
 * Balance ledger entry (`shd_credit`).
 */
class Credit extends ShdModel
{
    protected $table = 'credit';

    protected $casts = [
        'uid' => 'integer',
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'relid' => 'integer',
        'create_time' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }
}
