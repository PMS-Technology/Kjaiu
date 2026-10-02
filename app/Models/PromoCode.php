<?php

namespace App\Models;

/**
 * Promotional code (`shd_promo_code`).
 */
class PromoCode extends ShdModel
{
    protected $table = 'promo_code';

    protected $casts = [
        'recurring' => 'integer',
        'value' => 'decimal:2',
        'start_time' => 'integer',
        'expiration_time' => 'integer',
        'max_times' => 'integer',
        'used' => 'integer',
        'lifelong' => 'integer',
        'one_time' => 'integer',
        'only_new_client' => 'integer',
        'only_old_client' => 'integer',
        'once_per_client' => 'integer',
        'upgrades' => 'integer',
        'is_discount' => 'integer',
    ];

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    /**
     * Whether the code is currently redeemable.
     */
    public function isUsable(): bool
    {
        $now = time();

        if ((int) $this->start_time > 0 && $now < (int) $this->start_time) {
            return false;
        }

        if ((int) $this->expiration_time > 0 && $now > (int) $this->expiration_time) {
            return false;
        }

        if ((int) $this->max_times > 0 && (int) $this->used >= (int) $this->max_times) {
            return false;
        }

        return true;
    }

    /**
     * Discount amount applied to a given subtotal.
     */
    public function discountFor(float $subtotal): float
    {
        if ((string) $this->type === self::TYPE_PERCENTAGE) {
            return round($subtotal * ((float) $this->value / 100), 2);
        }

        return min($subtotal, round((float) $this->value, 2));
    }
}
