<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for file uploads in PocketDev. The infrastructure (Nginx/PHP)
    | supports up to 2GB, but the application default is more conservative.
    |
    | To increase the limit, set PD_MAX_UPLOAD_SIZE_MB in your .env file.
    | Note: The hard limit is 2GB due to infrastructure constraints.
    |
    */

    // Maximum file size for chat attachments (in MB)
    // Default: 250MB, Max: 2048MB (2GB)
    'max_size_mb' => min(
        (int) env('PD_MAX_UPLOAD_SIZE_MB', 250),
        2048 // Hard limit - infrastructure ceiling
    ),

    // Upload directory (shared across containers via pocket-dev-shared-tmp volume)
    'directory' => env('PD_UPLOAD_DIR', '/tmp/pocketdev-uploads'),

];
