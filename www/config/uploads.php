<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for file uploads in PocketDev. The infrastructure (Nginx/PHP)
    | supports up to 2GB uploads.
    |
    */

    // Maximum file size for chat attachments (in MB)
    // Hardcoded to 2GB - matches infrastructure ceiling
    'max_size_mb' => 2048,

    // Upload directory (shared across containers via pocket-dev-shared-tmp volume)
    'directory' => env('PD_UPLOAD_DIR', '/tmp/pocketdev-uploads'),

];
