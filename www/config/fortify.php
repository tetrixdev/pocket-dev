<?php

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Fortify will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    |
    | This value defines which model attribute should be considered as your
    | application's "username" field. Typically, this might be the email
    | address of the users but you are free to change this value here.
    |
    */

    'username' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Lowercase Usernames
    |--------------------------------------------------------------------------
    |
    | This value defines whether usernames should be lowercased before saving
    | them in the database, as some database system string fields are case
    | sensitive. You may disable this for your application if necessary.
    |
    */

    'lowercase_usernames' => true,

    /*
    |--------------------------------------------------------------------------
    | Home Path
    |--------------------------------------------------------------------------
    |
    | Here you may configure the path where users will get redirected during
    | authentication or password reset when the operations are successful
    | and the user is authenticated. You are free to change this value.
    |
    */

    'home' => '/',

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Prefix / Subdomain
    |--------------------------------------------------------------------------
    |
    | Here you may specify which prefix Fortify will assign to all the routes
    | that it registers with the application. If necessary, you may change
    | subdomain under which all of the Fortify routes will be available.
    |
    */

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Fortify will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | By default, Fortify will throttle logins to five requests per minute for
    | every email and IP address combination. However, if you would like to
    | specify a custom rate limiter to call then you may specify it here.
    |
    */

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of the Fortify features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features or you can even remove all of these if you need to.
    |
    */

    'features' => [
        // Registration disabled - handled via setup wizard
        // Features::registration(),
        // Password reset disabled for now (no email configured)
        // Features::resetPasswords(),
        // Features::emailVerification(),
        // Features::updateProfileInformation(),
        // Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            // 'window' => 0,
        ]),
    ],

];
