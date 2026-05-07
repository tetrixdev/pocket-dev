<?php

namespace App\Http\Middleware;

use App\Services\AppSettingsService;
use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Request;

/**
 * Bypass-aware authentication middleware.
 *
 * Extends Laravel's built-in Authenticate middleware to honour PocketDev's
 * two auth-bypass modes (permanent and session-based).  Without this,
 * routes that apply the `auth` middleware as defense-in-depth would reject
 * bypassed users — they pass through EnsureSetupComplete (which knows about
 * bypass) but fail the standard Auth::check() because no user is logged in.
 */
class Authenticate extends BaseAuthenticate
{
    /**
     * Determine if the user is logged in to any of the given guards,
     * allowing bypass modes to skip the check entirely.
     */
    protected function authenticate($request, array $guards): void
    {
        $settings = app(AppSettingsService::class);

        // When auth is bypassed there is no user to authenticate — let through.
        if ($settings->isAuthBypassPermanent()
            || $request->session()->get('auth_bypass_session')) {
            return;
        }

        parent::authenticate($request, $guards);
    }

    /**
     * Get the path the user should be redirected to when not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }
}
