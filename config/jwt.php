<?php

return [

    /*
    |--------------------------------------------------------------------------
    | JWT Authentication Secret
    |--------------------------------------------------------------------------
    | Generated via: php artisan jwt:secret
    */
    'secret' => env('JWT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | JWT Authentication Keys
    | For RS256 / RS512 algorithms — not used with HS256
    */
    'keys' => [
        'public'   => env('JWT_PUBLIC_KEY'),
        'private'  => env('JWT_PRIVATE_KEY'),
        'passphrase' => env('JWT_PASSPHRASE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | JWT time to live — access token expiry in minutes
    | Default: 1440 = 24 hours
    */
    'ttl' => env('JWT_TTL', 1440),

    /*
    |--------------------------------------------------------------------------
    | Refresh Token time to live in minutes
    | Default: 20160 = 14 days
    */
    'refresh_ttl' => env('JWT_REFRESH_TTL', 20160),

    /*
    |--------------------------------------------------------------------------
    | JWT hashing algorithm
    */
    'algo' => env('JWT_ALGO', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Required Claims
    */
    'required_claims' => [
        'iss',
        'iat',
        'exp',
        'nbf',
        'sub',
        'jti',
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistent Claims
    | Claims that persist when refreshing a token
    */
    'persistent_claims' => [],

    /*
    |--------------------------------------------------------------------------
    | Lock Subject
    | Lock the subject — prevents token from being used by another user
    */
    'lock_subject' => true,

    /*
    |--------------------------------------------------------------------------
    | Leeway (seconds)
    | Accounts for clock skew between servers
    */
    'leeway' => env('JWT_LEEWAY', 0),

    /*
    |--------------------------------------------------------------------------
    | Blacklist
    | Revoked tokens are blacklisted until they expire
    */
    'blacklist_enabled' => env('JWT_BLACKLIST_ENABLED', true),

    'blacklist_grace_period' => env('JWT_BLACKLIST_GRACE_PERIOD', 0),

    'show_black_list_exception' => env('JWT_SHOW_BLACKLIST_EXCEPTION', true),

    /*
    |--------------------------------------------------------------------------
    | Providers
    */
    'providers' => [
        'jwt'   => PHPOpenSourceSaver\JWTAuth\Providers\JWT\Lcobucci::class,
        'auth'  => PHPOpenSourceSaver\JWTAuth\Providers\Auth\Illuminate::class,
        'storage' => PHPOpenSourceSaver\JWTAuth\Providers\Storage\Illuminate::class,
    ],

];
