<?php

namespace App\Http\Middleware;

use App\Services\AppSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupComplete
{
    public function __construct(
        protected AppSettingsService $settings
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for setup routes and API routes
        if ($request->is('setup*') || $request->is('api/*') || $request->is('claude/auth*')) {
            return $next($request);
        }

        // If setup is not complete, redirect to setup wizard
        if (!$this->settings->isSetupComplete()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
