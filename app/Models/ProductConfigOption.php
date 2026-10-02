<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable option (`shd_product_config_options`).
 *
 * option_type: 1 = dropdown, 2 = radio, 3 = checkbox (multi-select),
 * 4 = quantity.
 */
class ProductConfigOption extends ShdModel
{
    protected $table = 'product_config_options';

    protected $casts = [
        'gid' => 'integer',
        'option_type' => 'integer',
        'qty_minimum' => 'integer',
        'qty_maximum' => 'integer',
        'order' => 'integer',
        'hidden' => 'integer',
        'upgrade' => 'integer',
        'is_discount' => 'integer',
        'is_rebate' => 'integer',
        'auto' => 'integer',
        'qty_stage' => 'integer',
    ];

    public const TYPE_DROPDOWN = 1;
    public const TYPE_RADIO = 2;
    public const TYPE_CHECKBOX = 3;
    public const TYPE_QUANTITY = 4;

    public function group()
    {
        return $this->belongsTo(ProductConfigGroup::class, 'gid');
    }

    public function subOptions(): HasMany
    {
        return $this->hasMany(ProductConfigOptionSub::class, 'config_id')->orderBy('sort_order');
    }
}
