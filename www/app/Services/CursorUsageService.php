<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CursorUsageService
{
    private const AUTH_FILE = '/home/appuser/.config/cursor/auth.json';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private AppSettingsService $settings
    ) {}

    /**
     * Check if Cursor credentials are available (local JWT or team API key).
     */
    public function hasCredentials(): bool
    {
        return $this->getSessionToken() !== null || $this->getTeamApiKey() !== null;
    }

    /**
     * Get usage data from Cursor's individual usage API.
     * Uses local JWT from ~/.config/cursor/auth.json.
     */
    public function getUsage(): ?array
    {
        // Check credentials before caching to avoid locking out null for 5 min
        $session = $this->getSessionToken();
        if (!$session) {
            return null;
        }

        return Cache::remember('cursor_usage', self::CACHE_TTL, function () use ($session) {
            try {
                $response = Http::withHeaders([
                        'Cookie' => 'WorkosCursorSessionToken=' . $session['token'],
                    ])
                    ->timeout(5)
                    ->get('https://cursor.com/api/usage', [
                        'user' => $session['userId'],
                    ]);

                if (!$response->ok()) {
                    Log::warning('Cursor usage API returned non-OK', ['status' => $response->status()]);
                    return null;
                }

                return $response->json();
            } catch (\Exception $e) {
                Log::warning('Cursor usage API request failed', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Get subscription/plan data from Cursor's Stripe endpoint.
     */
    public function getSubscription(): ?array
    {
        $session = $this->getSessionToken();
        if (!$session) {
            return null;
        }

        return Cache::remember('cursor_subscription', self::CACHE_TTL, function () use ($session) {
            try {
                $response = Http::withHeaders([
                        'Cookie' => 'WorkosCursorSessionToken=' . $session['token'],
                    ])
                    ->timeout(5)
                    ->get('https://cursor.com/api/auth/stripe');

                if (!$response->ok()) {
                    return null;
                }

                return $response->json();
            } catch (\Exception $e) {
                Log::warning('Cursor subscription API failed', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Get combined usage + subscription data for the dashboard.
     */
    public function getDashboardData(): ?array
    {
        $usage = $this->getUsage();
        $subscription = $this->getSubscription();

        if ($usage === null && $subscription === null) {
            return null;
        }

        // Extract the most relevant usage data
        $gpt4 = $usage['gpt-4'] ?? [];

        return [
            'usage' => [
                'requests_used' => $gpt4['numRequests'] ?? 0,
                'requests_total' => $gpt4['numRequestsTotal'] ?? 0,
                'tokens_used' => $gpt4['numTokens'] ?? 0,
                'max_requests' => $gpt4['maxRequestUsage'] ?? null,
                'start_of_month' => $usage['startOfMonth'] ?? null,
            ],
            'plan' => [
                'type' => $subscription['membershipType'] ?? 'unknown',
                'individual_type' => $subscription['individualMembershipType'] ?? null,
                'is_team' => $subscription['isTeamMember'] ?? false,
                'team_type' => $subscription['teamMembershipType'] ?? null,
                'balance' => $subscription['customerBalance'] ?? 0,
                'is_yearly' => $subscription['isYearlyPlan'] ?? false,
                'pending_cancellation' => $subscription['pendingCancellationDate'] ?? null,
            ],
        ];
    }

    /**
     * Get team usage events (requires team Admin API key).
     */
    public function getTeamEvents(int $days = 14): ?array
    {
        $apiKey = $this->getTeamApiKey();
        if (!$apiKey) {
            return null;
        }

        return Cache::remember("cursor_team_events_{$days}", self::CACHE_TTL, function () use ($apiKey, $days) {
            try {
                $response = Http::withBasicAuth($apiKey, '')
                    ->timeout(10)
                    ->post('https://api.cursor.com/teams/filtered-usage-events', [
                        'startDate' => now()->subDays($days)->getTimestampMs(),
                        'endDate' => now()->getTimestampMs(),
                        'pageSize' => 500,
                    ]);

                return $response->ok() ? $response->json() : null;
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /**
     * Force refresh all cached data.
     */
    public function refresh(): void
    {
        Cache::forget('cursor_usage');
        Cache::forget('cursor_subscription');
        // getTeamEvents() keys its cache by day range (1-90); clear them all.
        foreach (range(1, 90) as $days) {
            Cache::forget("cursor_team_events_{$days}");
        }
    }

    // -------------------------------------------------------------------------
    // Auth helpers
    // -------------------------------------------------------------------------

    /**
     * Build a session token from the local JWT for cursor.com API calls.
     * Returns ['userId' => ..., 'token' => ...] or null.
     */
    private function getSessionToken(): ?array
    {
        if (!file_exists(self::AUTH_FILE)) {
            return null;
        }

        try {
            $contents = @file_get_contents(self::AUTH_FILE);
            if (!$contents) {
                // Try via shell if permission denied
                $contents = @shell_exec('cat ' . escapeshellarg(self::AUTH_FILE) . ' 2>/dev/null');
            }
            if (!$contents) {
                return null;
            }

            $auth = json_decode($contents, true);
            $jwt = $auth['accessToken'] ?? null;
            if (!$jwt) {
                return null;
            }

            // Decode JWT payload (no signature verification needed)
            $parts = explode('.', $jwt);
            if (count($parts) < 2) {
                return null;
            }

            $payload = json_decode(
                base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)),
                true
            );

            $sub = $payload['sub'] ?? '';
            $userId = str_contains($sub, '|') ? explode('|', $sub)[1] : $sub;

            if (!$userId) {
                return null;
            }

            // Build session token in Cursor's expected format
            $sessionToken = urlencode($userId) . '%3A%3A' . urlencode($jwt);

            return [
                'userId' => $userId,
                'token' => $sessionToken,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to read Cursor auth', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getTeamApiKey(): ?string
    {
        $key = $this->settings->get('cursor_admin_api_key');
        return $key ?: null;
    }
}
