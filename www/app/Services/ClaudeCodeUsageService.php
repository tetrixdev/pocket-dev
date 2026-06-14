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
        // Check credentials before caching to avoid locking out null for 5 min
        $creds = $this->readCredentials();
        if (!$creds || $creds['expiresAt'] < intval(now()->getPreciseTimestamp(3))) {
            return null;
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () use ($creds) {
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
            // Try direct read first (works if file is group-readable)
            $contents = @file_get_contents(self::CREDENTIALS_PATH);

            // If permission denied, use SUID helper binary (always runs as root)
            // Install via: pd system:package add --name="read-claude-creds" ...
            if ($contents === false && is_executable('/usr/local/bin/read-claude-creds')) {
                $contents = @shell_exec('/usr/local/bin/read-claude-creds 2>/dev/null');
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
