<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\ModelRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CodexAuthController extends Controller
{
    protected string $credentialsPath;

    public function __construct(
        protected ModelRepository $models
    ) {
        // Credentials path - use HOME environment variable from PHP-FPM process
        $home = getenv('HOME') ?: '/home/appuser';
        $this->credentialsPath = "{$home}/.codex/auth.json";
    }

    /**
     * Show the authentication status page.
     */
    public function index()
    {
        $status = $this->getAuthenticationStatus();

        // When user arrives at this page already authenticated (e.g., after device-auth
        // flow and page refresh), ensure a default Codex agent exists so they can use it.
        if ($status["authenticated"]) {
            $this->ensureDefaultAgentExists();
        }

        return view("codex-auth", [
            "status" => $status,
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
            chmod($this->credentialsPath, 0600);

            Log::info("[Codex Auth] Credentials saved from JSON input");

            // Create a default Codex agent if one doesn't exist yet
            $this->ensureDefaultAgentExists();

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

            // Check for OAuth format (accessToken indicates OAuth login)
            if (isset($data["accessToken"])) {
                $expiresAt = $data["expiresAt"] ?? null;
                $now = time() * 1000; // Convert to milliseconds

                if ($expiresAt && $expiresAt < $now) {
                    return [
                        "authenticated" => false,
                        "message" => "Token expired",
                        "expired_at" => date("Y-m-d H:i:s", $expiresAt / 1000),
                    ];
                }

                return [
                    "authenticated" => true,
                    "auth_type" => "subscription",
                    "expires_at" => $expiresAt ? date("Y-m-d H:i:s", $expiresAt / 1000) : null,
                    "days_until_expiry" => $expiresAt ? round(($expiresAt - $now) / (1000 * 60 * 60 * 24), 1) : null,
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

        // Valid if has accessToken (OAuth format)
        if (isset($data["accessToken"]) && !empty($data["accessToken"])) {
            return true;
        }

        return false;
    }

    /**
     * Ensure a default Codex agent exists.
     *
     * When Codex is set up via Settings (not the initial wizard), no agent gets
     * created automatically. This method fills that gap so the user can actually
     * use Codex after authenticating.
     */
    protected function ensureDefaultAgentExists(): void
    {
        if (Agent::enabled()->defaultFor('codex')->exists()) {
            return;
        }

        $defaultModel = $this->models->getDefaultModel('codex');
        if (!$defaultModel) {
            Log::warning('[Codex Auth] Cannot create default agent: no models configured');
            return;
        }

        Agent::create([
            'name' => 'Codex',
            'description' => 'Codex agent with full tool access for development tasks.',
            'provider' => 'codex',
            'model' => $defaultModel['model_id'],
            'is_default' => true,
            'enabled' => true,
            'response_level' => 1,
            'allowed_tools' => null,
        ]);

        Log::info('[Codex Auth] Created default Codex agent');
    }
}
