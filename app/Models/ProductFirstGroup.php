<?php

namespace App\Models;

/**
 * First-level product group (`shd_product_first_groups`), the "category"
 * shown in the storefront navigation.
 */
class ProductFirstGroup extends ShdModel
{
    protected $table = 'product_first_groups';

    protected $casts = [
        'hidden' => 'integer',
        'order' => 'integer',
        'is_upstream' => 'integer',
        'zjmf_api_id' => 'integer',
    ];

    public function groups()
    {
        return $this->hasMany(ProductGroup::class, 'gid')->orderBy('order');
    }
}
