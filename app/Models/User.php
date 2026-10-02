<?php

namespace App\Models;

use App\Support\PasswordHasher;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

/**
 * Administrator account (`shd_user`).
 *
 * Passwords are stored with the legacy ThinkCMF scheme; see PasswordHasher.
 */
class User extends ShdModel implements AuthenticatableContract
{
    use AuthenticatableTrait;

    protected $table = 'user';

    protected $hidden = ['user_pass', 'user_activation_key'];

    protected $casts = [
        'is_sale' => 'integer',
        'user_status' => 'integer',
        'last_login_time' => 'integer',
        'create_time' => 'integer',
    ];

    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;

    public function getAuthPassword(): string
    {
        return (string) $this->user_pass;
    }

    public function displayName(): string
    {
        return (string) ($this->user_nickname ?: $this->user_login);
    }

    public function isEnabled(): bool
    {
        return (int) $this->user_status === self::STATUS_ENABLED;
    }

    public function isAdministrator(): bool
    {
        return (int) $this->id === 1;
    }

    /**
     * Verify a plain-text password against the stored legacy hash.
     */
    public function checkPassword(string $plain): bool
    {
        return PasswordHasher::checkAdmin($plain, (string) $this->user_pass);
    }

    /**
     * Store a new plain-text password using the legacy scheme.
     */
    public function setPassword(string $plain): void
    {
        $this->user_pass = PasswordHasher::admin($plain);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }
}
