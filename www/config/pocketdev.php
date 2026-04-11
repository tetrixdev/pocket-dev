<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Project Name
    |--------------------------------------------------------------------------
    |
    | The project name used for Docker container and volume naming. This allows
    | running multiple PocketDev instances on the same server with different
    | container/volume prefixes.
    |
    */
    // Handle both unset and empty string to match Docker Compose ${VAR:-default} behavior
    'project_name' => (($projectName = env('PD_PROJECT_NAME')) !== null && $projectName !== '')
        ? $projectName
        : 'pocket-dev',

    /*
    |--------------------------------------------------------------------------
    | Allowed Path Prefixes
    |--------------------------------------------------------------------------
    |
    | These are the directory prefixes that are allowed for file operations.
    | Any path must start with one of these prefixes (or be an exact match
    | to the prefix without trailing slash) to be considered valid.
    |
    */
    'allowed_paths' => [
        '/workspace/',
        '/pocketdev-source/',
        '/home/appuser/',
        '/tmp/',
    ],
];
