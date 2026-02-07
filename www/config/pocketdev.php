<?php

return [
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
