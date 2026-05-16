<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StartCursorBrowserAuthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow the job to run for up to 16 minutes (browser auth window is ~15 min).
     */
    public int $timeout = 1000;

    /**
     * Don't retry - browser auth is a one-shot flow.
     */
    public int $tries = 1;

    public function handle(): void
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $authFile = "{$home}/.config/cursor/auth.json";
        $logFile = sys_get_temp_dir() . '/cursor_browser_auth_' . getmypid() . '.log';

        Log::info('[Cursor Browser Auth] Job started', ['log' => $logFile]);

        // Find agent binary
        $agentPath = $this->findAgentPath();
        if (!$agentPath) {
            $this->failWithError(
                'Cursor Agent CLI is not installed. Install it with: curl -fsSL https://cursor.com/install | bash'
            );
            return;
        }

        Log::info('[Cursor Browser Auth] Found agent at: ' . $agentPath);

        // Clean up any old log
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        // Start browser auth in background with NO_OPEN_BROWSER to get URL in stdout
        $cmd = 'NO_OPEN_BROWSER=1 nohup ' . escapeshellarg($agentPath) . ' login > '
            . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd);

        // Poll log file for URL (up to 30 seconds)
        $verificationUrl = null;

        for ($i = 0; $i < 30; $i++) {
            sleep(1);

            if (!file_exists($logFile)) {
                continue;
            }

            $log = file_get_contents($logFile);
            if (!$log) {
                continue;
            }

            // Strip ANSI color codes
            $text = preg_replace('/\033\[[0-9;]*[mGKHF]/', '', $log);

            // Look for Cursor login URL (challenge-based, no user code needed)
            if (!$verificationUrl) {
                if (preg_match('/(https:\/\/(?:www\.)?cursor\.com\/login\S+)/i', $text, $m)) {
                    $verificationUrl = rtrim($m[1], '.,)');
                } elseif (preg_match('/(https:\/\/\S*cursor\S*auth\S+)/i', $text, $m)) {
                    $verificationUrl = rtrim($m[1], '.,)');
                } elseif (preg_match('/(https:\/\/\S+)/i', $text, $m)) {
                    // Fallback: any HTTPS URL in the output
                    $verificationUrl = rtrim($m[1], '.,)');
                }
            }

            if ($verificationUrl) {
                break;
            }
        }

        if (!$verificationUrl) {
            $rawLog = file_exists($logFile) ? file_get_contents($logFile) : '(empty)';
            Log::error('[Cursor Browser Auth] Could not parse URL from output', ['log' => $rawLog]);
            $this->failWithError(
                'Could not get login URL from Cursor Agent. Ensure it is properly installed and try again.'
            );
            return;
        }

        $expiresAt = time() + 900; // 15 minutes

        Log::info('[Cursor Browser Auth] Login URL ready', [
            'url' => $verificationUrl,
        ]);

        Cache::put('cursor_browser_auth', [
            'status' => 'ready',
            'verification_url' => $verificationUrl,
            'expires_at' => $expiresAt,
        ], 960);

        // Poll for auth.json - agent writes it when the user completes authentication
        for ($i = 0; $i < 900; $i++) {
            sleep(1);

            if (file_exists($authFile)) {
                $content = @file_get_contents($authFile);
                $data = $content ? @json_decode($content, true) : null;

                if ($data && (!empty($data['accessToken']) || !empty($data['refreshToken']))) {
                    Log::info('[Cursor Browser Auth] Authentication successful');

                    Cache::put('cursor_browser_auth', ['status' => 'authenticated'], 120);

                    // Cleanup log
                    if (file_exists($logFile)) {
                        unlink($logFile);
                    }

                    return;
                }
            }
        }

        // Timed out without authentication
        Log::warning('[Cursor Browser Auth] Session expired without authentication');
        Cache::put('cursor_browser_auth', ['status' => 'expired'], 120);

        if (file_exists($logFile)) {
            unlink($logFile);
        }
    }

    /**
     * Find the agent binary on the system.
     */
    private function findAgentPath(): ?string
    {
        $home = getenv('HOME') ?: '/home/appuser';

        $candidates = [
            "{$home}/.local/bin/agent",
            '/usr/local/bin/agent',
            '/usr/bin/agent',
        ];

        // Check via `which` first (respects PATH)
        exec('which agent 2>/dev/null', $out, $rc);
        if ($rc === 0 && !empty($out[0])) {
            return trim($out[0]);
        }

        // Check known paths
        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Store a failure state in cache so the frontend can display the error.
     */
    private function failWithError(string $error): void
    {
        Cache::put('cursor_browser_auth', [
            'status' => 'failed',
            'error' => $error,
        ], 300);

        Log::error('[Cursor Browser Auth] ' . $error);
    }
}
