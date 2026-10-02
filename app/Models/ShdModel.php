<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model for the mirrored ZJMF schema.
 *
 * The original platform stores timestamps as unix integers in create_time /
 * update_time columns and never uses soft deletes, so Eloquent's timestamp
 * handling is disabled by default here.
 */
abstract class ShdModel extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    /**
     * Read a unix timestamp attribute as a Carbon instance.
     */
    public function toCarbonDate(string $attribute): ?\Carbon\Carbon
    {
        $value = $this->getAttribute($attribute);

        return $value ? \Carbon\Carbon::createFromTimestamp((int) $value) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}
