<?php

return [
    // Docker user/group for file permissions and exec commands
    // Local: must be set in .env (run `id -u` and `id -g` to get your values)
    // Production: falls back to www-data if not set
    'user_id' => env('PD_USER_ID', env('PD_APP_ENV') === 'local' ? null : 'www-data'),
    'group_id' => env('PD_GROUP_ID', env('PD_APP_ENV') === 'local' ? null : 'www-data'),

    'host_project_path' => env('PD_HOST_PROJECT_PATH'),
];
