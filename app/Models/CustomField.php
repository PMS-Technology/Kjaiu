<?php

namespace App\Models;

/**
 * Custom client or product field definition (`shd_customfields`).
 */
class CustomField extends ShdModel
{
    protected $table = 'customfields';

    protected $casts = [
        'relid' => 'integer',
        'adminonly' => 'integer',
        'required' => 'integer',
        'showorder' => 'integer',
        'showinvoice' => 'integer',
        'sortorder' => 'integer',
        'showdetail' => 'integer',
        'create_time' => 'integer',
    ];

    public const TYPE_TEXT = 'text';
    public const TYPE_LINK = 'link';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_DROPDOWN = 'dropdown';
    public const TYPE_TICKBOX = 'tickbox';
    public const TYPE_TEXTAREA = 'textarea';

    /**
     * Dropdown choices, stored as comma-separated text.
     */
    public function options(): array
    {
        if ((string) $this->fieldtype !== self::TYPE_DROPDOWN) {
            return [];
        }

        $raw = (string) $this->fieldoptions;

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
    }

    public function values()
    {
        return $this->hasMany(CustomFieldValue::class, 'fieldid');
    }
}
