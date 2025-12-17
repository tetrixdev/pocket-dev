<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust Docker network proxies and localhost
        // PocketDev runs behind its own proxy container and may be behind additional proxies
        $middleware->trustProxies(at: [
            '127.0.0.1',       // Localhost
            '172.16.0.0/12',   // Docker networks (172.16.x.x - 172.31.x.x)
            '10.0.0.0/8',      // Private network range (common in Docker/k8s)
            '192.168.0.0/16',  // Private network range
        ]);

        // Redirect to setup wizard if not complete
        $middleware->web(append: [
            \App\Http\Middleware\EnsureSetupComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
