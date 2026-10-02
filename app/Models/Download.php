<?php

namespace App\Models;

/**
 * Downloadable resource (`shd_downloads`).
 */
class Download extends ShdModel
{
    protected $table = 'downloads';

    protected $casts = [
        'category' => 'integer',
        'downloads' => 'integer',
        'clientsonly' => 'integer',
        'hidden' => 'integer',
        'productdownload' => 'integer',
        'create_time' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(DownloadCategory::class, 'category');
    }
}
