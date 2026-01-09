<?php

return [
    'user_id' => env('USER_ID'),
    'group_id' => env('GROUP_ID'),
    'host_project_path' => env('HOST_PROJECT_PATH'),

    // Docker exec user/group for credential setup commands
    // Uses USER_ID/GROUP_ID if set, otherwise falls back based on environment
    // Local: 1000 (appuser), Production: 33 (www-data)
    'exec_user' => env('USER_ID') ?: (env('APP_ENV') === 'local' ? '1000' : '33'),
    'exec_group' => env('GROUP_ID') ?: (env('APP_ENV') === 'local' ? '1000' : '33'),
];
