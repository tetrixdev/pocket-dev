<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AppSettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        // Routes that should always be accessible (before any auth check).
        // Listed explicitly rather than via `setup/*` glob so provider-setup
        // (/setup/provider) stays behind the normal auth flow. Adding a new
        // first-run wizard step requires adding it here.
        $publicRoutes = [
            'setup',                                    // First-run index
            'setup/skip',                               // "Skip auth" handler
            'setup/credentials',                        // Wizard step 1 (GET+POST)
            'setup/totp',                               // Wizard step 2 (GET+POST)
            'setup/recovery',                           // Wizard step 3 (GET+POST)
            'login',                                    // Login page (Fortify GET/POST)
            'logout',                                   // Logout (Fortify POST)
            'two-factor-challenge', 'two-factor-challenge/*', // 2FA challenge
            'claude/auth', 'claude/auth/*',             // Claude CLI OAuth flow
            'codex/auth', 'codex/auth/*',               // Codex CLI OAuth flow
        ];

        foreach ($publicRoutes as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        // Check if authentication is bypassed permanently (user chose "don't ask again")
        if ($this->settings->isAuthBypassPermanent()) {
            return $next($request);
        }

        // Check if authentication is bypassed (temporarily via session)
        if ($request->session()->get('auth_bypass_session')) {
            return $next($request);
        }

        // If no users exist, redirect to setup wizard (first-run)
        if (User::count() === 0) {
            // For API requests, return JSON response instead of redirect
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('setup.index');
        }

        // If setup is not complete (API keys not configured), redirect to setup
        if (!$this->settings->isSetupComplete()) {
            // But only if logged in - otherwise go to login first
            if (!Auth::check()) {
                // For API requests, return JSON response instead of redirect
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }
                return redirect()->route('login');
            }
            // Authenticated user on the provider-setup page itself: let it through
            // to avoid a redirect loop.
            if ($request->is('setup/provider')) {
                return $next($request);
            }
            // For API requests, return JSON response instead of redirect
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('setup.provider');
        }

        // If not logged in, redirect to login page
        if (!Auth::check()) {
            // For API requests, return JSON response instead of redirect
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('login');
        }

        return $next($request);
    }
}
