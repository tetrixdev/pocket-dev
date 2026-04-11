<?php

namespace App\Http\Controllers;

use App\Services\CodexAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CodexAuthController extends Controller
{
    protected string $credentialsPath;
    protected CodexAgentService $codexAgentService;

    public function __construct(CodexAgentService $codexAgentService)
    {
        $this->codexAgentService = $codexAgentService;

        // Credentials path - use HOME environment variable from PHP-FPM process
        $home = getenv('HOME') ?: '/home/appuser';
        $this->credentialsPath = "{$home}/.codex/auth.json";
    }

    /**
     * Show the authentication status page.
     */
    public function index()
    {
        return view("codex-auth", [
            "status" => $this->getAuthenticationStatus(),
        ]);
    }

    /**
     * Get current authentication status.
     */
    public function status(): JsonResponse
    {
        return response()->json($this->getAuthenticationStatus());
    }

    /**
     * Upload credentials from JSON text.
     */
    public function uploadJson(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            "json" => "required|string",
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "message" => "JSON content is required.",
                "errors" => $validator->errors(),
            ], 422);
        }

        try {
            $data = json_decode($request->input("json"), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    "success" => false,
                    "message" => "Invalid JSON: " . json_last_error_msg(),
                ], 422);
            }

            // Validate structure
            if (!$this->isValidCredentialFile($data)) {
                return response()->json([
                    "success" => false,
                    "message" => "Invalid credentials structure. Expected OPENAI_API_KEY or OAuth tokens.",
                ], 422);
            }

            // Create directory if it does not exist
            $dir = dirname($this->credentialsPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Save the file
            file_put_contents($this->credentialsPath, json_encode($data, JSON_PRETTY_PRINT));
            chmod($this->credentialsPath, 0660);

            Log::info("[Codex Auth] Credentials saved from JSON input");

            // Create default agent for all workspaces that don't have one
            $workspaces = \App\Models\Workspace::all();
            foreach ($workspaces as $workspace) {
                $this->codexAgentService->ensureDefaultAgentExists($workspace->id);
            }

            return response()->json([
                "success" => true,
                "message" => "Credentials saved successfully.",
                "status" => $this->getAuthenticationStatus(),
            ]);

        } catch (\Exception $e) {
            Log::error("[Codex Auth] Failed to save credentials from JSON", [
                "error" => $e->getMessage(),
            ]);

            return response()->json([
                "success" => false,
                "message" => "Failed to save credentials: " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear credentials (logout).
     */
    public function logout(): JsonResponse
    {
        try {
            if (file_exists($this->credentialsPath)) {
                unlink($this->credentialsPath);
                Log::info("[Codex Auth] Credentials cleared");
            }

            return response()->json([
                "success" => true,
                "message" => "Logged out successfully.",
            ]);

        } catch (\Exception $e) {
            Log::error("[Codex Auth] Failed to clear credentials", [
                "error" => $e->getMessage(),
            ]);

            return response()->json([
                "success" => false,
                "message" => "Failed to clear credentials: " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get current authentication status.
     */
    protected function getAuthenticationStatus(): array
    {
        if (!file_exists($this->credentialsPath)) {
            return [
                "authenticated" => false,
                "message" => "Not authenticated",
            ];
        }

        try {
            $content = file_get_contents($this->credentialsPath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    "authenticated" => false,
                    "message" => "Invalid credentials file",
                ];
            }

            // Check for API key format
            if (isset($data["OPENAI_API_KEY"])) {
                $key = $data["OPENAI_API_KEY"];
                return [
                    "authenticated" => true,
                    "auth_type" => "api_key",
                    "key_preview" => substr($key, 0, 6) . "..." . substr($key, -4),
                ];
            }

            // Check for OAuth format (tokens.access_token indicates OAuth login)
            if (isset($data["tokens"]["access_token"])) {
                $lastRefresh = $data["last_refresh"] ?? null;
                // Codex source uses TOKEN_REFRESH_INTERVAL = 8 days (codex-rs/core/src/auth.rs)
                $refreshIntervalDays = (int) config('ai.providers.codex.token_refresh_days', 8);

                if ($lastRefresh) {
                    $refreshDate = new \DateTime($lastRefresh);
                    $now = new \DateTime();
                    $expiryDate = (clone $refreshDate)->modify("+{$refreshIntervalDays} days");

                    if ($now > $expiryDate) {
                        return [
                            "authenticated" => false,
                            "message" => "Token expired",
                            "expired_at" => $expiryDate->format("Y-m-d H:i:s"),
                        ];
                    }

                    $daysUntilExpiry = round(($expiryDate->getTimestamp() - $now->getTimestamp()) / 86400, 1);

                    return [
                        "authenticated" => true,
                        "auth_type" => "subscription",
                        "expires_at" => $expiryDate->format("Y-m-d H:i:s"),
                        "days_until_expiry" => $daysUntilExpiry,
                    ];
                }

                return [
                    "authenticated" => true,
                    "auth_type" => "subscription",
                    "expires_at" => null,
                    "days_until_expiry" => null,
                ];
            }

            return [
                "authenticated" => false,
                "message" => "Unknown credentials format",
            ];

        } catch (\Exception $e) {
            Log::error("[Codex Auth] Failed to read credentials", [
                "error" => $e->getMessage(),
            ]);

            return [
                "authenticated" => false,
                "message" => "Error reading credentials",
                "error" => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate credential file structure.
     */
    protected function isValidCredentialFile(array $data): bool
    {
        // Valid if has OPENAI_API_KEY (API key format)
        if (isset($data["OPENAI_API_KEY"]) && !empty($data["OPENAI_API_KEY"])) {
            return true;
        }

        // Valid if has tokens.access_token (OAuth format)
        if (isset($data["tokens"]["access_token"]) && !empty($data["tokens"]["access_token"])) {
            return true;
        }

        return false;
    }

    /**
     * Serve the local upload script with this instance's URL pre-filled.
     * Users can pipe it directly: bash <(curl -s https://pocketdev/codex/auth/upload-script)
     */
    public function downloadScript(): Response
    {
        $scriptPath = public_path('scripts/codex-auth.sh');
        if (!file_exists($scriptPath) || !is_readable($scriptPath)) {
            Log::error('[Codex Auth] Upload script missing or unreadable', ['path' => $scriptPath]);
            return response('Upload script not found', 404);
        }

        $script = file_get_contents($scriptPath);
        if ($script === false) {
            Log::error('[Codex Auth] Failed to read upload script', ['path' => $scriptPath]);
            return response('Failed to read upload script', 500);
        }

        // Pre-fill the PocketDev URL so the user doesn't have to type it
        $instanceUrl = rtrim(url('/'), '/');
        $script = str_replace(
            'PD_URL="${1:-${POCKETDEV_URL:-}}"',
            'PD_URL="${1:-${POCKETDEV_URL:-' . $instanceUrl . '}}"',
            $script
        );

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'inline; filename="codex-auth.sh"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Start device auth flow — dispatches a background job that runs codex login.
     * The job parses the URL/code from codex output and stores them in cache.
     * Frontend polls deviceStatus() to get the URL and code to show the user.
     */
    public function startDeviceAuth(): JsonResponse
    {
        // Already authenticated — no need to start a new session
        if (file_exists($this->credentialsPath)) {
            return response()->json([
                'success' => false,
                'error' => 'Already authenticated. Logout first if you want to switch accounts.',
            ], 400);
        }

        // Check if there's already an active session in progress
        $existing = Cache::get('codex_device_auth');
        if ($existing && in_array($existing['status'], ['starting', 'ready'], true)) {
            $expiresAt = $existing['expires_at'] ?? 0;
            if ($expiresAt > time()) {
                return response()->json([
                    'success' => true,
                    'status' => $existing['status'],
                    'verification_url' => $existing['verification_url'] ?? null,
                    'user_code' => $existing['user_code'] ?? null,
                    'expires_in' => max(0, $expiresAt - time()),
                ]);
            }
        }

        // Store initial "starting" state so the polling endpoint can respond immediately
        Cache::put('codex_device_auth', [
            'status' => 'starting',
            'started_at' => time(),
            'expires_at' => time() + 960,
        ], 960);

        // Dispatch the long-running job to the queue container
        \App\Jobs\StartCodexDeviceAuthJob::dispatch();

        Log::info('[Codex Device Auth] Job dispatched');

        return response()->json([
            'success' => true,
            'status' => 'starting',
        ]);
    }

    /**
     * Return the current device auth session status.
     * Frontend polls this every 2-3 seconds after calling startDeviceAuth().
     */
    public function deviceStatus(): JsonResponse
    {
        // Auth.json already exists — authentication complete
        if (file_exists($this->credentialsPath)) {
            // Clear any leftover session cache
            Cache::forget('codex_device_auth');

            return response()->json([
                'success' => true,
                'status' => 'authenticated',
            ]);
        }

        $session = Cache::get('codex_device_auth');

        if (!$session) {
            return response()->json(['success' => true, 'status' => 'none']);
        }

        // Check expiry
        if (($session['expires_at'] ?? 0) <= time()) {
            Cache::forget('codex_device_auth');
            return response()->json(['success' => true, 'status' => 'expired']);
        }

        // Propagate job-reported "authenticated" status even if auth.json isn't visible here
        if ($session['status'] === 'authenticated') {
            return response()->json([
                'success' => true,
                'status' => 'authenticated',
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $session['status'],
            'verification_url' => $session['verification_url'] ?? null,
            'user_code' => $session['user_code'] ?? null,
            'expires_in' => max(0, ($session['expires_at'] ?? 0) - time()),
            'error' => $session['error'] ?? null,
        ]);
    }

    /**
     * Ensure a default Codex agent exists for the given workspace.
     *
     * Called after successful authentication to create a default agent
     * if none exists, so users can immediately start using Codex.
     *
     * @param string $workspaceId The workspace to create the agent in
     * @return \App\Models\Agent|null The default agent, or null if creation failed
     */
    public function ensureDefaultAgentExists(string $workspaceId): ?\App\Models\Agent
    {
        return $this->codexAgentService->ensureDefaultAgentExists($workspaceId);
    }
}
