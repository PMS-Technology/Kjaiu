<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable option group (`shd_product_config_groups`).
 */
class ProductConfigGroup extends ShdModel
{
    protected $table = 'product_config_groups';

    protected $casts = [
        'global' => 'integer',
        'upstream_id' => 'integer',
    ];

    public function options(): HasMany
    {
        return $this->hasMany(ProductConfigOption::class, 'gid')->orderBy('order');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ProductConfigLink::class, 'gid');
    }
}
