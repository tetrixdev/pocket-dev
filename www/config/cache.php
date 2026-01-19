<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('PD_CACHE_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "octane", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('PD_DB_CACHE_CONNECTION'),
            'table' => env('PD_DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('PD_DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('PD_DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('PD_MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('PD_MEMCACHED_USERNAME'),
                env('PD_MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('PD_MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('PD_MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('PD_REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('PD_REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('PD_AWS_ACCESS_KEY_ID'),
            'secret' => env('PD_AWS_SECRET_ACCESS_KEY'),
            'region' => env('PD_AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('PD_DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('PD_DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('PD_CACHE_PREFIX', Str::slug((string) env('PD_APP_NAME', 'laravel')).'-cache-'),

];
