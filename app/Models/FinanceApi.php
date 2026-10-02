<?php

namespace App\Models;

/**
 * Upstream supplier account (`shd_zjmf_finance_api`).
 *
 * One row per upstream 智简魔方财务 installation this site resells from.
 * `type` is zjmf_api (API reseller), manual (manually managed resource pool)
 * or resource (shared resource pool).
 */
class FinanceApi extends ShdModel
{
    protected $table = 'zjmf_finance_api';

    protected $hidden = ['password'];

    protected $casts = [
        'upstream_uid' => 'integer',
        'status' => 'integer',
        'product_num' => 'integer',
        'create_time' => 'integer',
        'is_resource' => 'integer',
        'ticket_open' => 'integer',
        'is_using' => 'integer',
        'auto_update' => 'integer',
    ];

    public const TYPE_API = 'zjmf_api';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_RESOURCE = 'resource';

    public const STATUS_OK = 1;
    public const STATUS_ERROR = 0;

    /**
     * Base URL of the upstream installation, normalised without a trailing slash.
     */
    public function baseUrl(): string
    {
        $host = trim((string) $this->hostname);

        if ($host === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }

        return rtrim($host, '/');
    }

    public function isHealthy(): bool
    {
        return (int) $this->status === self::STATUS_OK;
    }

    public function isApiReseller(): bool
    {
        return (string) $this->type === self::TYPE_API;
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'zjmf_api_id');
    }
}
