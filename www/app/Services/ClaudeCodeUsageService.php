<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeCodeUsageService
{
    private const CREDENTIALS_PATH = '/home/appuser/.claude/.credentials.json';
    private const CACHE_KEY = 'claude_code_usage_limits';
    private const CACHE_TTL = 300; // 5 minutes
    private const API_URL = 'https://api.anthropic.com/api/oauth/usage';

    /**
     * Check if a valid OAuth token exists.
     */
    public function hasValidToken(): bool
    {
        $creds = $this->readCredentials();

        if (!$creds) {
            return false;
        }

        return $creds['expiresAt'] > intval(now()->getPreciseTimestamp(3));
    }

    /**
     * Get Claude Code utilization data (5h/7d limits).
     * Returns null if unavailable.
     */
    public function getUtilization(): ?array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $creds = $this->readCredentials();

            if (!$creds) {
                return null;
            }

            if ($creds['expiresAt'] < intval(now()->getPreciseTimestamp(3))) {
                return null;
            }

            try {
                $response = Http::withToken($creds['accessToken'])
                    ->withHeaders([
                        'anthropic-beta' => 'oauth-2025-04-20',
                    ])
                    ->timeout(5)
                    ->get(self::API_URL);

                if ($response->ok()) {
                    return $response->json();
                }

                Log::warning('Claude Code usage API returned non-OK', [
                    'status' => $response->status(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::warning('Claude Code usage API request failed', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Force refresh the cached utilization data.
     */
    public function refresh(): ?array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->getUtilization();
    }

    /**
     * Read OAuth credentials from the Claude credentials file.
     */
    private function readCredentials(): ?array
    {
        if (!file_exists(self::CREDENTIALS_PATH)) {
            return null;
        }

        try {
            // Try direct read first (works if file is group-readable by www-data)
            $contents = @file_get_contents(self::CREDENTIALS_PATH);

            // If direct read fails (permission denied), try via shell as appuser
            if ($contents === false) {
                $contents = @shell_exec('cat ' . escapeshellarg(self::CREDENTIALS_PATH) . ' 2>/dev/null');
            }

            if (!$contents) {
                // Auto-fix: try to add group-read permission (file group is www-data)
                @shell_exec('chmod g+r ' . escapeshellarg(self::CREDENTIALS_PATH) . ' 2>/dev/null');
                $contents = @file_get_contents(self::CREDENTIALS_PATH);
            }

            if (!$contents) {
                Log::warning('Cannot read Claude credentials file (permission denied)');
                return null;
            }

            $data = json_decode($contents, true);

            if (!$data || !isset($data['claudeAiOauth']['accessToken'])) {
                return null;
            }

            return [
                'accessToken' => $data['claudeAiOauth']['accessToken'],
                'expiresAt' => $data['claudeAiOauth']['expiresAt'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to read Claude credentials', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
