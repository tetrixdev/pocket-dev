<?php

namespace App\Jobs;

use App\Services\CodexAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StartCodexDeviceAuthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow the job to run for up to 16 minutes (device code window is 15 min).
     */
    public int $timeout = 1000;

    /**
     * Don't retry — device auth is a one-shot flow.
     */
    public int $tries = 1;

    public function handle(): void
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $authFile = "{$home}/.codex/auth.json";
        $logFile = sys_get_temp_dir() . '/codex_device_auth_' . getmypid() . '.log';

        Log::info('[Codex Device Auth] Job started', ['log' => $logFile]);

        // Find codex binary
        $codexPath = $this->findCodexPath();
        if (!$codexPath) {
            $this->failWithError(
                'Codex is not installed. Install it with: npm install -g @openai/codex'
            );
            return;
        }

        Log::info('[Codex Device Auth] Found codex at: ' . $codexPath);

        // Clean up any old log
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        // Start device auth in background, redirect stdout+stderr to log file
        $cmd = 'nohup ' . escapeshellarg($codexPath) . ' login --device-auth > '
            . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd);

        // Poll log file for URL and code (up to 30 seconds)
        $verificationUrl = null;
        $userCode = null;

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

            // Look for verification URL (any HTTPS URL from auth.openai.com or similar)
            if (!$verificationUrl) {
                if (preg_match('/(https:\/\/\S+device\S*)/i', $text, $m)) {
                    $verificationUrl = rtrim($m[1], '.,)');
                } elseif (preg_match('/(https:\/\/auth\.openai\.com\/\S+)/i', $text, $m)) {
                    $verificationUrl = rtrim($m[1], '.,)');
                }
            }

            // Look for user code (e.g. CDB9-2OZPE, or similar alphanumeric-dash patterns)
            if (!$userCode) {
                if (preg_match('/\b([A-Z0-9]{4,8}[-–][A-Z0-9]{4,8})\b/', $text, $m)) {
                    $userCode = $m[1];
                }
            }

            if ($verificationUrl && $userCode) {
                break;
            }
        }

        if (!$verificationUrl || !$userCode) {
            $rawLog = file_exists($logFile) ? file_get_contents($logFile) : '(empty)';
            Log::error('[Codex Device Auth] Could not parse URL/code', ['log' => $rawLog]);
            $this->failWithError(
                'Could not get device code from Codex. Ensure Codex is properly installed and try again.'
            );
            return;
        }

        $expiresAt = time() + 900; // 15 minutes

        Log::info('[Codex Device Auth] Device code ready', [
            'url' => $verificationUrl,
            'code' => $userCode,
        ]);

        Cache::put('codex_device_auth', [
            'status' => 'ready',
            'verification_url' => $verificationUrl,
            'user_code' => $userCode,
            'expires_at' => $expiresAt,
        ], 960);

        // Poll for auth.json — codex writes it when the user completes authentication
        for ($i = 0; $i < 900; $i++) {
            sleep(1);

            if (file_exists($authFile)) {
                $content = @file_get_contents($authFile);
                $data = $content ? @json_decode($content, true) : null;

                if ($data && (isset($data['tokens']['access_token']) || isset($data['OPENAI_API_KEY']))) {
                    Log::info('[Codex Device Auth] Authentication successful');

                    Cache::put('codex_device_auth', ['status' => 'authenticated'], 120);

                    // Create default Codex agent for all workspaces
                    $this->createDefaultAgents();

                    // Cleanup log
                    if (file_exists($logFile)) {
                        unlink($logFile);
                    }

                    return;
                }
            }
        }

        // Timed out without authentication
        Log::warning('[Codex Device Auth] Session expired without authentication');
        Cache::put('codex_device_auth', ['status' => 'expired'], 120);

        if (file_exists($logFile)) {
            unlink($logFile);
        }
    }

    /**
     * Find the codex binary on the system.
     */
    private function findCodexPath(): ?string
    {
        $home = getenv('HOME') ?: '/home/appuser';

        $candidates = [
            "{$home}/.local/share/npm/bin/codex",
            "{$home}/.npm-global/bin/codex",
            "{$home}/.local/bin/codex",
            '/usr/local/bin/codex',
            '/usr/bin/codex',
        ];

        // Check via `which` first (respects PATH)
        exec('which codex 2>/dev/null', $out, $rc);
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
        Cache::put('codex_device_auth', [
            'status' => 'failed',
            'error' => $error,
        ], 300);

        Log::error('[Codex Device Auth] ' . $error);
    }

    /**
     * Create a default Codex agent for all workspaces that don't have one.
     */
    private function createDefaultAgents(): void
    {
        try {
            $agentService = app(CodexAgentService::class);
            $workspaces = \App\Models\Workspace::all();
            foreach ($workspaces as $workspace) {
                $agentService->ensureDefaultAgentExists($workspace->id);
            }
        } catch (\Exception $e) {
            Log::error('[Codex Device Auth] Failed to create default agents', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
