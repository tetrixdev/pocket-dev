<?php

return [
    // Docker user/group for file permissions and exec commands
    // Local: must be set in .env (run `id -u` and `id -g` to get your values)
    // Production: falls back to www-data if not set
    'user_id' => env('USER_ID', env('APP_ENV') === 'local' ? null : 'www-data'),
    'group_id' => env('GROUP_ID', env('APP_ENV') === 'local' ? null : 'www-data'),

    'host_project_path' => env('HOST_PROJECT_PATH'),
];
