<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeveloperAccess
{
    /**
     * Ensure developer routes are only reachable from local env
     * or via authenticated basic auth requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        // If reverse proxy auth is active and propagated to PHP, allow.
        if (!empty($request->server('PHP_AUTH_USER')) || !empty($request->server('REMOTE_USER'))) {
            return $next($request);
        }

        // Fallback: validate Authorization header directly against env credentials.
        $configuredUser = env('BASIC_AUTH_USER');
        $configuredPass = env('BASIC_AUTH_PASS');
        if (!empty($configuredUser) && !empty($configuredPass) && $this->matchesBasicAuth($request, $configuredUser, $configuredPass)) {
            return $next($request);
        }

        return response('Unauthorized', 401, [
            'WWW-Authenticate' => 'Basic realm="PocketDev Developer Tools"',
        ]);
    }

    private function matchesBasicAuth(Request $request, string $expectedUser, string $expectedPass): bool
    {
        $header = (string) $request->header('Authorization', '');
        if (!str_starts_with($header, 'Basic ')) {
            return false;
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return false;
        }

        [$user, $pass] = explode(':', $decoded, 2);

        return hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
    }
}

