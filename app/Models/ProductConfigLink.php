<?php

namespace App\Models;

/**
 * Product-to-option-group link (`shd_product_config_links`).
 */
class ProductConfigLink extends ShdModel
{
    protected $table = 'product_config_links';

    public $timestamps = false;

    protected $casts = [
        'gid' => 'integer',
        'pid' => 'integer',
    ];

    public function group()
    {
        return $this->belongsTo(ProductConfigGroup::class, 'gid');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'pid');
    }
}
