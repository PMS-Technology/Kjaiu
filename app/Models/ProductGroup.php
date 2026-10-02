<?php

namespace App\Models;

/**
 * Second-level product group (`shd_product_groups`), belonging to a
 * first-level group (`shd_product_first_groups`).
 */
class ProductGroup extends ShdModel
{
    protected $table = 'product_groups';

    protected $casts = [
        'hidden' => 'integer',
        'order' => 'integer',
        'type' => 'integer',
        'gid' => 'integer',
        'is_upstream' => 'integer',
        'zjfm_api_id' => 'integer',
    ];

    public function firstGroup()
    {
        return $this->belongsTo(ProductFirstGroup::class, 'gid');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'gid');
    }
}
