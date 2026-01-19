<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('PD_QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('PD_DB_QUEUE_CONNECTION'),
            'table' => env('PD_DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('PD_DB_QUEUE', 'default'),
            // Job reservation timeout - must be longer than the longest job duration.
            // PocketDev AI jobs can run 30+ minutes. Default Laravel value (90s) is too short.
            'retry_after' => (int) env('PD_DB_QUEUE_RETRY_AFTER', 1810),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('PD_BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('PD_BEANSTALKD_QUEUE', 'default'),
            // Job reservation timeout - must be longer than the longest job duration.
            // PocketDev AI jobs can run 30+ minutes. Default Laravel value (90s) is too short.
            'retry_after' => (int) env('PD_BEANSTALKD_QUEUE_RETRY_AFTER', 1810),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('PD_AWS_ACCESS_KEY_ID'),
            'secret' => env('PD_AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('PD_SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('PD_SQS_QUEUE', 'default'),
            'suffix' => env('PD_SQS_SUFFIX'),
            'region' => env('PD_AWS_DEFAULT_REGION', 'us-east-1'),
            // Job reservation timeout - must be longer than the longest job duration.
            // PocketDev AI jobs can run 30+ minutes. Default Laravel value (90s) is too short.
            'retry_after' => (int) env('PD_SQS_QUEUE_RETRY_AFTER', 1810),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('PD_REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('PD_REDIS_QUEUE', 'default'),
            // Job reservation timeout - must be longer than the longest job duration.
            // PocketDev AI jobs can run 30+ minutes. Default Laravel value (90s) is too short.
            'retry_after' => (int) env('PD_REDIS_QUEUE_RETRY_AFTER', 1810),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('PD_DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('PD_QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('PD_DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
