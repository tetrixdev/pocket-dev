<?php

namespace App\Providers;

use App\Panels\PanelRegistry;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PanelRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS scheme when behind a reverse proxy that terminates SSL
        // Enable via FORCE_HTTPS=true in .env (e.g., when using Tailscale Serve)
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        // Share sidebar data with all config views
        view()->composer('layouts.config', \App\Http\View\Composers\ConfigSidebarComposer::class);
    }
}
