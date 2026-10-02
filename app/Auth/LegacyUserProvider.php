<?php

namespace App\Auth;

use App\Models\Client;
use App\Models\User;
use App\Support\PasswordHasher;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * User provider that validates against the legacy password schemes stored in
 * the mirrored schema.
 *
 * Administrator rows (`shd_user`) hold md5($plain); client rows
 * (`shd_clients`) hold "###" . md5(md5($authCode . $plain)). Neither matches
 * Laravel's default hasher, so password verification is delegated to
 * PasswordHasher while credential lookup stays standard Eloquent.
 */
class LegacyUserProvider implements UserProvider
{
    public function __construct(
        protected string $model,
        protected Hasher $hasher,
    ) {
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->newModel()->newQuery()->find($identifier);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        $model = $this->newModel();

        if (! method_exists($model, 'getRememberTokenName')) {
            return null;
        }

        $column = $model->getRememberTokenName();

        if (! $column || ! $this->hasColumn($model, $column)) {
            return null;
        }

        return $model->newQuery()
            ->where($model->getAuthIdentifierName(), $identifier)
            ->where($column, $token)
            ->first();
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        if ($user instanceof Client) {
            // Client rows have no remember-token column; sessions are the only
            // persistence mechanism in the original schema.
            return;
        }

        if (! method_exists($user, 'getRememberTokenName')) {
            return;
        }

        $column = $user->getRememberTokenName();

        if (! $column || ! $this->hasColumn($user, $column)) {
            return;
        }

        $user->setRememberToken($token);
        $user->save();
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $query = $this->newModel()->newQuery();

        foreach ($credentials as $key => $value) {
            if (in_array($key, ['password', 'checkPassword', 'mk', 'token', 'captcha', 'remember'], true)) {
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            $query->where($key, $value);
        }

        return $query->first();
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $submitted = (string) ($credentials['password'] ?? '');

        if ($submitted === '') {
            return false;
        }

        // Client-area forms encrypt the password before submit; API callers
        // send it in the clear. Accept either.
        $plain = $user instanceof Client
            ? PasswordHasher::acceptedPlain($submitted)
            : $submitted;

        return $this->hasher->check($plain, $user);
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        // Legacy hashes are never rewritten on login.
    }

    protected function newModel(): Authenticatable
    {
        return new $this->model();
    }

    protected function hasColumn(object $model, string $column): bool
    {
        static $columns = [];

        $table = $model->getTable();

        if (! isset($columns[$table])) {
            $columns[$table] = method_exists($model, 'getConnection')
                ? $model->getConnection()->getSchemaBuilder()->getColumnListing($table)
                : [];
        }

        return in_array($column, $columns[$table], true);
    }
}
