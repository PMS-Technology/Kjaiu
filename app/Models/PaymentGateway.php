<?php

namespace App\Models;

/**
 * Enabled payment gateway (`shd_payment_gateways`).
 *
 * Rows are written by the administrator panel when a gateway plugin is
 * enabled; the row holds the per-gateway configuration payload.
 */
class PaymentGateway extends ShdModel
{
    protected $table = 'payment_gateways';

    protected $primaryKey = 'gateway';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'order' => 'integer',
    ];

    /**
     * Configuration values for this gateway, JSON-decoded when applicable.
     */
    public function settings(): array
    {
        if (trim((string) $this->setting) === '') {
            return [];
        }

        $decoded = json_decode((string) $this->setting, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function displayName(): string
    {
        return trim((string) $this->value) !== '' ? (string) $this->value : (string) $this->gateway;
    }
}
