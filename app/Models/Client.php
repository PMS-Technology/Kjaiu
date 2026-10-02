<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client account (`shd_clients`).
 *
 * Named "Client" rather than "User" because `shd_user` holds administrator
 * accounts in the mirrored schema.
 */
class Client extends ShdModel implements AuthenticatableContract
{
    // The client-area guard signs rows from this table in, so the model must
    // satisfy the Authenticatable contract that SessionGuard::login() requires
    // (previously missing, which made every client login fatal).
    use AuthenticatableTrait;

    protected $table = 'clients';

    protected $hidden = ['password', 'authdata', 'cardnum', 'bankacct', 'bankcode'];

    protected $casts = [
        'credit' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'credit_limit_balance' => 'decimal:2',
        'status' => 'integer',
        'groupid' => 'integer',
        'currency' => 'integer',
        'create_time' => 'integer',
        'lastlogin' => 'integer',
        'api_open' => 'integer',
        'second_verify' => 'integer',
        'is_open_credit_limit' => 'integer',
    ];

    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 0;
    public const STATUS_CLOSED = 2;

    public function fullName(): string
    {
        return $this->username !== '' && $this->username !== null
            ? (string) $this->username
            : (string) ($this->email ?: $this->phonenumber);
    }

    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(Host::class, 'uid');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'uid');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'uid');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'uid');
    }

    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class, 'uid');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'uid');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SystemMessage::class, 'uid');
    }

    public function group()
    {
        return $this->belongsTo(ClientGroup::class, 'groupid');
    }

    /**
     * Apply a balance change and record it in the credit ledger.
     */
    public function addCredit(float $amount, string $description, ?int $relid = null): Credit
    {
        $this->credit = round((float) $this->credit + $amount, 2);
        $this->save();

        return Credit::create([
            'uid' => $this->id,
            'create_time' => time(),
            'description' => $description,
            'amount' => round($amount, 2),
            'relid' => $relid ?? 0,
            'balance' => $this->credit,
        ]);
    }
}
