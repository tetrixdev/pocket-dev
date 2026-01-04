<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS scheme when APP_URL is configured with HTTPS
        // This handles reverse proxies like Tailscale Serve that terminate SSL
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Share sidebar data with all config views
        view()->composer('layouts.config', \App\Http\View\Composers\ConfigSidebarComposer::class);
    }
}
