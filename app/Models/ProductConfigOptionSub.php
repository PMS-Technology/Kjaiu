<?php

namespace App\Models;

/**
 * Configurable option choice (`shd_product_config_options_sub`).
 */
class ProductConfigOptionSub extends ShdModel
{
    protected $table = 'product_config_options_sub';

    protected $casts = [
        'config_id' => 'integer',
        'qty_minimum' => 'integer',
        'qty_maximum' => 'integer',
        'sort_order' => 'integer',
        'hidden' => 'integer',
    ];

    public function option()
    {
        return $this->belongsTo(ProductConfigOption::class, 'config_id');
    }

    public function pricing()
    {
        return $this->hasMany(Pricing::class, 'relid')->where('type', 'configoptions');
    }
}
