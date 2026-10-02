<?php

namespace App\Models;

/**
 * Stored value of a custom field (`shd_customfieldsvalues`).
 */
class CustomFieldValue extends ShdModel
{
    protected $table = 'customfieldsvalues';

    protected $casts = [
        'fieldid' => 'integer',
        'relid' => 'integer',
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];

    public function field()
    {
        return $this->belongsTo(CustomField::class, 'fieldid');
    }
}
