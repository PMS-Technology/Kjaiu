<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Administrator permission group (`shd_role`).
 */
class Role extends ShdModel
{
    protected $table = 'role';

    protected $casts = [
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');
    }

    /**
     * Permission ids granted to this role.
     */
    public function rules(): array
    {
        if (trim((string) $this->rules) === '') {
            return [];
        }

        $decoded = json_decode((string) $this->rules, true);

        if (is_array($decoded)) {
            return array_map('intval', $decoded);
        }

        return array_map('intval', array_filter(explode(',', (string) $this->rules)));
    }
}
