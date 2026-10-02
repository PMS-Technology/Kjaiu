<?php

namespace App\Models;

/**
 * Transaction ledger entry (`shd_accounts`).
 *
 * Every payment, refund and balance movement is recorded here; the client area
 * renders these rows in the transaction views.
 */
class Account extends ShdModel
{
    protected $table = 'accounts';

    protected $casts = [
        'uid' => 'integer',
        'amount_in' => 'decimal:2',
        'amount_out' => 'decimal:2',
        'fees' => 'decimal:2',
        'rate' => 'decimal:5',
        'invoice_id' => 'integer',
        'refund' => 'integer',
        'create_time' => 'integer',
        'pay_time' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
