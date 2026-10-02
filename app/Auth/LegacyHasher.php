<?php

namespace App\Auth;

use App\Models\Client;
use App\Support\PasswordHasher;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Hasher adapter for the legacy password schemes.
 *
 * Laravel's default hashers (bcrypt/argon) cannot verify the md5-based hashes
 * stored by the original platform, so credential checks are routed through
 * PasswordHasher instead. `make` is intentionally left to delegate to the
 * underlying driver so anything else in the framework keeps working.
 */
class LegacyHasher implements Hasher
{
    public function __construct(
        protected Hasher $fallback,
    ) {
    }

    public function info($hashedValue): array
    {
        if (str_starts_with((string) $hashedValue, PasswordHasher::CLIENT_PREFIX)) {
            return ['algo' => 'legacy-client', 'algoName' => 'legacy-client', 'options' => []];
        }

        if (preg_match('/^[0-9a-f]{32}$/i', (string) $hashedValue) === 1) {
            return ['algo' => 'legacy-md5', 'algoName' => 'legacy-md5', 'options' => []];
        }

        return $this->fallback->info($hashedValue);
    }

    public function make($value, array $options = []): string
    {
        // New passwords written by this application use the legacy scheme for
        // administrator rows and the framework hasher everywhere else.
        $mode = $options['mode'] ?? null;

        if ($mode === 'admin') {
            return PasswordHasher::admin((string) $value);
        }

        if ($mode === 'client') {
            return PasswordHasher::client((string) $value);
        }

        return $this->fallback->make($value, $options);
    }

    public function check($value, $hashedValue, array $options = []): bool
    {
        $hashedValue = (string) $hashedValue;

        if (str_starts_with($hashedValue, PasswordHasher::CLIENT_PREFIX)) {
            return PasswordHasher::checkClient((string) $value, $hashedValue);
        }

        if (preg_match('/^[0-9a-f]{32}$/i', $hashedValue) === 1) {
            return PasswordHasher::checkAdmin((string) $value, $hashedValue);
        }

        return $this->fallback->check($value, $hashedValue, $options);
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        $hashedValue = (string) $hashedValue;

        if (str_starts_with($hashedValue, PasswordHasher::CLIENT_PREFIX)
            || preg_match('/^[0-9a-f]{32}$/i', $hashedValue) === 1) {
            return false;
        }

        return $this->fallback->needsRehash($hashedValue, $options);
    }
}
