<?php

namespace App\Models;

/**
 * Download category (`shd_downloadcats`).
 */
class DownloadCategory extends ShdModel
{
    protected $table = 'downloadcats';

    protected $casts = [
        'parentid' => 'integer',
        'hidden' => 'integer',
        'order' => 'integer',
    ];

    public function downloads()
    {
        return $this->hasMany(Download::class, 'category');
    }
}
