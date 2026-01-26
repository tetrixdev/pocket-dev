<?php

return [
    // Docker user for file permissions and exec commands
    // Local: must be set in .env (run `id -u` to get your value)
    // Production: falls back to www-data if not set
    'user_id' => env('PD_USER_ID', env('PD_APP_ENV') === 'local' ? null : 'www-data'),
    // Group is always www-data (33) for cross-group ownership model
    'group_id' => 'www-data',

    'host_project_path' => env('PD_HOST_PROJECT_PATH'),
];
