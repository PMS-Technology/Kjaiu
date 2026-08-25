<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['configuration'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'configuration' => 'encrypted:array',
        ];
    }
}
