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
    // Hardcoded to 250MB for testing - will be raised to 2GB after verification
    'max_size_mb' => 250,

    // Upload directory (shared across containers via pocket-dev-shared-tmp volume)
    'directory' => env('PD_UPLOAD_DIR', '/tmp/pocketdev-uploads'),

];
