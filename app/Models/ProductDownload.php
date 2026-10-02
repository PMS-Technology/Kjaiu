<?php

namespace App\Models;

/**
 * File attached to a product (`shd_product_downloads`).
 *
 * The link table is keyed by `product_id` / `download_id` and resolved through
 * the `Download` model, so the attachment list on the service detail page is a
 * plain join rather than a JSON column.
 */
class ProductDownload extends ShdModel
{
    protected $table = 'product_downloads';

    protected $casts = [
        'product_id' => 'integer',
        'download_id' => 'integer',
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];

    public function download()
    {
        return $this->belongsTo(Download::class, 'download_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
