<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Product (`shd_products`).
 */
class Product extends ShdModel
{
    protected $table = 'products';

    protected $casts = [
        'hidden' => 'integer',
        'retired' => 'integer',
        'is_featured' => 'integer',
        'stock_control' => 'integer',
        'qty' => 'integer',
        'tax' => 'integer',
        'allow_qty' => 'integer',
        'server_group' => 'integer',
        'gid' => 'integer',
        'groupid' => 'integer',
        'zjmf_api_id' => 'integer',
        'upstream_pid' => 'integer',
        'upstream_price_value' => 'decimal:2',
        'config_options_upgrade' => 'integer',
        'clientscount' => 'integer',
        'order' => 'integer',
    ];

    public const API_TYPE_NORMAL = 'normal';
    public const API_TYPE_ZJMF = 'zjmf_api';
    public const API_TYPE_MANUAL = 'manual';
    public const API_TYPE_RESOURCE = 'resource';

    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'gid');
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(Pricing::class, 'relid')->where('type', 'product');
    }

    public function configLinks(): HasMany
    {
        return $this->hasMany(ProductConfigLink::class, 'pid');
    }

    /**
     * Decoded `pay_type` payload (cycles, trial settings, hourly/day cycles).
     */
    public function payType(): array
    {
        $decoded = json_decode((string) $this->pay_type, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isUpstream(): bool
    {
        return in_array((string) $this->api_type, [self::API_TYPE_ZJMF, self::API_TYPE_RESOURCE], true);
    }

    /**
     * Whether the product is still purchasable in the storefront.
     */
    public function isPurchasable(): bool
    {
        // `hidden` is also Eloquent's own protected property, so it must be read
        // through getAttribute() from inside the model: a bare $this->hidden
        // returns the attributes-to-hide array, and casting that to int yields 0
        // — which would make every hidden product purchasable.
        if ((int) $this->getAttribute('hidden') === 1 || (int) $this->getAttribute('retired') === 1) {
            return false;
        }

        if ((int) $this->stock_control === 1 && (int) $this->qty <= 0) {
            return false;
        }

        return true;
    }
}
