<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('PD_POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('PD_RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('PD_AWS_ACCESS_KEY_ID'),
        'secret' => env('PD_AWS_SECRET_ACCESS_KEY'),
        'region' => env('PD_AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('PD_SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('PD_SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // OpenAI credentials managed via UI (stored in database via AppSettingsService)

];
