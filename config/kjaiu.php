<?php

return [
    'company_name' => env('KJAIU_COMPANY_NAME', 'Kjaiu'),
    'company_email' => env('KJAIU_COMPANY_EMAIL', 'support@example.com'),

    'currency' => [
        'id' => 1,
        'code' => env('KJAIU_CURRENCY_CODE', 'CNY'),
        'prefix' => env('KJAIU_CURRENCY_PREFIX', '¥'),
        'suffix' => env('KJAIU_CURRENCY_SUFFIX', '元'),
    ],

    'jwt' => [
        'secret' => env('KJAIU_JWT_SECRET'),
        'ttl' => (int) env('KJAIU_JWT_TTL', 7200),
        'issuer' => env('APP_URL', 'http://localhost'),
    ],

    'funds' => [
        'minimum' => '0.01',
        'maximum' => '1000000.00',
        'maximum_balance' => '10000000.00',
    ],
];
