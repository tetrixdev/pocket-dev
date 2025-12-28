<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Snapshot Retention
    |--------------------------------------------------------------------------
    |
    | Default number of days to retain snapshots. This can be overridden
    | via the app_settings table (user-configurable in UI).
    |
    | Tiered retention:
    | - 0-24 hours: hourly snapshots
    | - 1-7 days: 4 per day (00:00, 06:00, 12:00, 18:00)
    | - 7-30 days: 1 per day (00:00)
    |
    */
    'snapshot_retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Snapshot Directory
    |--------------------------------------------------------------------------
    |
    | Directory where memory schema snapshots (pg_dump files) are stored.
    |
    */
    'snapshot_directory' => storage_path('app/private/memory-snapshots'),

    /*
    |--------------------------------------------------------------------------
    | Embedding Model
    |--------------------------------------------------------------------------
    |
    | The embedding model to use for generating vectors.
    | Default is OpenAI's text-embedding-3-small.
    |
    */
    'embedding_model' => env('MEMORY_EMBEDDING_MODEL', 'text-embedding-3-small'),

    /*
    |--------------------------------------------------------------------------
    | Embedding Dimensions
    |--------------------------------------------------------------------------
    |
    | The dimension of the embedding vectors.
    | Must match the model's output dimensions.
    |
    | - text-embedding-3-small: 1536
    | - text-embedding-3-large: 3072
    |
    */
    'embedding_dimensions' => env('MEMORY_EMBEDDING_DIMENSIONS', 1536),

    /*
    |--------------------------------------------------------------------------
    | Protected Tables
    |--------------------------------------------------------------------------
    |
    | Tables in the memory schema that cannot be dropped or truncated.
    | These are infrastructure tables managed by PocketDev.
    |
    */
    'protected_tables' => [
        'embeddings',
        'schema_registry',
    ],
];
