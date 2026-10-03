<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site identity
    |--------------------------------------------------------------------------
    |
    | Mirrors the `shd_configuration` settings of the original platform so the
    | two installations stay interchangeable at the API level.
    |
    */

    'name' => env('KJAIU_SITE_NAME', 'Kjaiu'),

    /*
    |--------------------------------------------------------------------------
    | Database table prefix
    |--------------------------------------------------------------------------
    |
    | The schema mirrors the original platform (智简魔方财务 / ZJMF v3.7.6) so
    | that upstream/downstream integrations and data imports stay compatible.
    |
    */

    'table_prefix' => env('KJAIU_TABLE_PREFIX', 'shd_'),

    /*
    |--------------------------------------------------------------------------
    | Password hashing (legacy compatible)
    |--------------------------------------------------------------------------
    |
    | Client passwords: "###" . md5(md5($authCode . $plain))
    | Administrator passwords: md5($plain)
    |
    */

    'password' => [
        'client_prefix' => '###',
        'authcode' => env('KJAIU_AUTH_CODE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public API (downstream facing, /v1)
    |--------------------------------------------------------------------------
    */

    'api' => [
        'path' => 'v1',
        'jwt_secret' => env('KJAIU_JWT_SECRET', ''),
        'jwt_ttl' => (int) env('KJAIU_JWT_TTL', 7200),
        'jwt_issuer' => env('KJAIU_JWT_ISSUER', 'www.idcsmart.com'),
        'jwt_audience' => env('KJAIU_JWT_AUDIENCE', 'www.idcsmart.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Administrator entry path
    |--------------------------------------------------------------------------
    */

    'admin_path' => env('KJAIU_ADMIN_PATH', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Upstream / downstream (上下游)
    |--------------------------------------------------------------------------
    */

    'upstream' => [
        'timeout' => (int) env('KJAIU_UPSTREAM_TIMEOUT', 30),
    ],
];
