<?php

namespace App\Models;

/**
 * Shopping cart (`shd_cart_session`), keyed by client id plus session id.
 */
class CartSession extends ShdModel
{
    protected $table = 'cart_session';

    protected $casts = [
        'create_time' => 'integer',
        'expire_time' => 'integer',
        'update_time' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'uid');
    }

    public function isExpired(): bool
    {
        return (int) $this->expire_time > 0 && (int) $this->expire_time < time();
    }
}
