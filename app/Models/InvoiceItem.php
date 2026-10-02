<?php

namespace App\Models;

/**
 * Invoice line item (`shd_invoice_items`).
 *
 * `type` is one of hosting, renew, upgrade, product, addon, configoptions,
 * credit, refund or bill.
 */
class InvoiceItem extends ShdModel
{
    protected $table = 'invoice_items';

    protected $casts = [
        'invoice_id' => 'integer',
        'uid' => 'integer',
        'rel_id' => 'integer',
        'amount' => 'decimal:2',
        'due_time' => 'integer',
        'taxed' => 'integer',
    ];

    public const TYPE_HOSTING = 'hosting';
    public const TYPE_RENEW = 'renew';
    public const TYPE_UPGRADE = 'upgrade';
    public const TYPE_CONFIG_OPTIONS = 'configoptions';
    public const TYPE_CREDIT = 'credit';
    public const TYPE_REFUND = 'refund';

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function host()
    {
        return $this->belongsTo(Host::class, 'rel_id');
    }

    public function isDeleted(): bool
    {
        return (int) $this->delete_time > 0;
    }
}
