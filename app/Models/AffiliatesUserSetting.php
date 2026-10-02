<?php

namespace App\Models;

/**
 * Per-client affiliate switches (`shd_affiliates_user_setting`).
 *
 * `affiliate_type` / `affiliate_renew_type` / `affiliate_reorder_type` are the
 * commission base types (1 fixed amount, 2 percentage).
 */
class AffiliatesUserSetting extends ShdModel
{
    protected $table = 'affiliates_user_setting';

    protected $casts = [
        'uid' => 'integer',
        'create_time' => 'integer',
        'affiliate_enabled' => 'integer',
        'affiliate_is_reorder' => 'integer',
        'affiliate_reorder' => 'decimal:2',
        'affiliate_is_renew' => 'integer',
        'affiliate_renew' => 'decimal:2',
        'affiliate_bates' => 'decimal:2',
        'affiliate_type' => 'integer',
        'affiliate_renew_type' => 'integer',
        'affiliate_reorder_type' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }
}
