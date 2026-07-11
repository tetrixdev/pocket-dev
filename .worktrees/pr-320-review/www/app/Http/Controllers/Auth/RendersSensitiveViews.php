<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Response;

/**
 * Shared helper for rendering views that contain secrets (TOTP secrets,
 * recovery codes). Adds cache-busting headers so the browser's bf-cache
 * or disk-cache can't retain them after the user navigates away.
 */
trait RendersSensitiveViews
{
    private function sensitiveView(string $view, array $data = []): Response
    {
        return response()
            ->view($view, $data)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
