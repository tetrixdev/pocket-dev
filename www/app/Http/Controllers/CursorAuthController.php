<?php

namespace App\Http\Controllers;

use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CursorAuthController extends Controller
{
    protected string $credentialsPath;

    public function __construct()
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $this->credentialsPath = "{$home}/.config/cursor/auth.json";
    }

    /**
     * Show the authentication status page.
     */
    public function index()
    {
        return view("cursor-auth", [
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
                    "message" => "Invalid credentials structure. Expected accessToken and refreshToken.",
                ], 422);
            }

            // Create directory if it does not exist
            $dir = dirname($this->credentialsPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }

            // Save the file
            file_put_contents($this->credentialsPath, json_encode($data, JSON_PRETTY_PRINT));

            // Try to set group-writable permissions (non-fatal if we're not the owner)
            @chmod($dir, 0770);
            @chmod($this->credentialsPath, 0660);

            Log::info("[Cursor Auth] Credentials saved from JSON input");

            return response()->json([
                "success" => true,
                "message" => "Credentials saved successfully.",
                "status" => $this->getAuthenticationStatus(),
            ]);

        } catch (\Exception $e) {
            Log::error("[Cursor Auth] Failed to save credentials from JSON", [
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
            // Run `agent logout` to clear any cached state
            $home = getenv('HOME') ?: '/home/appuser';
            $agentPath = "{$home}/.local/bin/agent";
            if (is_executable($agentPath)) {
                exec(escapeshellarg($agentPath) . ' logout 2>/dev/null');
            }

            if (file_exists($this->credentialsPath)) {
                unlink($this->credentialsPath);
                Log::info("[Cursor Auth] Credentials cleared");
            }

            // Also clear API key if stored
            $settings = app(AppSettingsService::class);
            if ($settings->hasCursorAgentApiKey()) {
                $settings->deleteCursorAgentApiKey();
            }

            return response()->json([
                "success" => true,
                "message" => "Logged out successfully.",
            ]);

        } catch (\Exception $e) {
            Log::error("[Cursor Auth] Failed to clear credentials", [
                "error" => $e->getMessage(),
            ]);

            return response()->json([
                "success" => false,
                "message" => "Failed to clear credentials: " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start browser auth flow - dispatches a background job that runs `agent login`.
     */
    public function startBrowserAuth(): JsonResponse
    {
        // Already authenticated
        if (file_exists($this->credentialsPath)) {
            return response()->json([
                'success' => false,
                'error' => 'Already authenticated. Logout first if you want to switch accounts.',
            ], 400);
        }

        // Check if there's already an active session in progress
        $existing = Cache::get('cursor_browser_auth');
        if ($existing && in_array($existing['status'], ['starting', 'ready'], true)) {
            $expiresAt = $existing['expires_at'] ?? 0;
            if ($expiresAt > time()) {
                return response()->json([
                    'success' => true,
                    'status' => $existing['status'],
                    'verification_url' => $existing['verification_url'] ?? null,
                    'expires_in' => max(0, $expiresAt - time()),
                ]);
            }
        }

        // Store initial "starting" state
        Cache::put('cursor_browser_auth', [
            'status' => 'starting',
            'started_at' => time(),
            'expires_at' => time() + 960,
        ], 960);

        // Dispatch the long-running job
        \App\Jobs\StartCursorBrowserAuthJob::dispatch();

        Log::info('[Cursor Browser Auth] Job dispatched');

        return response()->json([
            'success' => true,
            'status' => 'starting',
        ]);
    }

    /**
     * Return the current browser auth session status.
     */
    public function browserAuthStatus(): JsonResponse
    {
        // Auth file already exists
        if (file_exists($this->credentialsPath)) {
            Cache::forget('cursor_browser_auth');

            return response()->json([
                'success' => true,
                'status' => 'authenticated',
            ]);
        }

        $session = Cache::get('cursor_browser_auth');

        if (!$session) {
            return response()->json(['success' => true, 'status' => 'none']);
        }

        // Check expiry
        if (($session['expires_at'] ?? 0) <= time()) {
            Cache::forget('cursor_browser_auth');
            return response()->json(['success' => true, 'status' => 'expired']);
        }

        // Propagate job-reported "authenticated" status
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
            'expires_in' => max(0, ($session['expires_at'] ?? 0) - time()),
            'error' => $session['error'] ?? null,
        ]);
    }

    /**
     * Get current authentication status.
     */
    protected function getAuthenticationStatus(): array
    {
        // Check API key first
        $settings = app(AppSettingsService::class);
        if ($settings->hasCursorAgentApiKey()) {
            $key = $settings->getCursorAgentApiKey();
            return [
                "authenticated" => true,
                "auth_type" => "api_key",
                "key_preview" => substr($key, 0, 8) . "..." . substr($key, -4),
            ];
        }

        if (!file_exists($this->credentialsPath)) {
            return [
                "authenticated" => false,
                "message" => "Not authenticated",
            ];
        }

        try {
            // Use `agent status --format json` for a reliable check
            $home = getenv('HOME') ?: '/home/appuser';
            $agentPath = "{$home}/.local/bin/agent";

            if (is_executable($agentPath)) {
                $output = [];
                $returnCode = 0;
                exec(escapeshellarg($agentPath) . ' status --format json 2>/dev/null', $output, $returnCode);

                if ($returnCode === 0 && !empty($output)) {
                    $statusData = json_decode(implode('', $output), true);
                    if (is_array($statusData)) {
                        $isAuth = $statusData['isAuthenticated'] ?? false;
                        $email = $statusData['email'] ?? null;

                        if ($isAuth) {
                            return [
                                "authenticated" => true,
                                "auth_type" => "subscription",
                                "email" => $email,
                            ];
                        }
                    }
                }
            }

            // Fallback: check file contents directly
            $content = file_get_contents($this->credentialsPath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    "authenticated" => false,
                    "message" => "Invalid credentials file",
                ];
            }

            if (!empty($data['accessToken']) && !empty($data['refreshToken'])) {
                return [
                    "authenticated" => true,
                    "auth_type" => "subscription",
                ];
            }

            return [
                "authenticated" => false,
                "message" => "Unknown credentials format",
            ];

        } catch (\Exception $e) {
            Log::error("[Cursor Auth] Failed to read credentials", [
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
        // Valid if has accessToken and refreshToken (Cursor OAuth format)
        if (!empty($data['accessToken']) && !empty($data['refreshToken'])) {
            return true;
        }

        return false;
    }
}
