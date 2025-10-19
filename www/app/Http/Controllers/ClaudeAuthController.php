<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ClaudeAuthController extends Controller
{
    protected string $credentialsPath;

    public function __construct()
    {
        // Credentials path - use HOME environment variable from PHP-FPM process
        // The PHP-FPM process runs with HOME=/home/appuser, so Claude CLI looks there
        $home = getenv('HOME') ?: '/home/appuser';
        $this->credentialsPath = "{$home}/.claude/.credentials.json";
    }

    /**
     * Show the authentication status page.
     */
    public function index()
    {
        return view("claude-auth", [
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
     * Upload credentials file.
     */
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            "credentials" => "required|file|mimes:json|max:10",
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "message" => "Invalid file. Please upload a valid .credentials.json file.",
                "errors" => $validator->errors(),
            ], 422);
        }

        try {
            $content = file_get_contents($request->file("credentials")->getRealPath());
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    "success" => false,
                    "message" => "Invalid JSON file.",
                ], 422);
            }

            // Validate structure
            if (!$this->isValidCredentialFile($data)) {
                return response()->json([
                    "success" => false,
                    "message" => "Invalid credentials file structure. Expected claudeAiOauth with accessToken, refreshToken, etc.",
                ], 422);
            }

            // Create directory if it does not exist
            $dir = dirname($this->credentialsPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Save the file
            file_put_contents($this->credentialsPath, $content);
            chmod($this->credentialsPath, 0600);

            Log::info("[Claude Auth] Credentials file uploaded successfully");

            return response()->json([
                "success" => true,
                "message" => "Credentials uploaded successfully.",
                "status" => $this->getAuthenticationStatus(),
            ]);

        } catch (\Exception $e) {
            Log::error("[Claude Auth] Failed to upload credentials", [
                "error" => $e->getMessage(),
            ]);

            return response()->json([
                "success" => false,
                "message" => "Failed to save credentials: " . $e->getMessage(),
            ], 500);
        }
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
                    "message" => "Invalid credentials structure. Expected claudeAiOauth with accessToken, refreshToken, etc.",
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

            Log::info("[Claude Auth] Credentials saved from JSON input");

            return response()->json([
                "success" => true,
                "message" => "Credentials saved successfully.",
                "status" => $this->getAuthenticationStatus(),
            ]);

        } catch (\Exception $e) {
            Log::error("[Claude Auth] Failed to save credentials from JSON", [
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
                Log::info("[Claude Auth] Credentials cleared");
            }

            return response()->json([
                "success" => true,
                "message" => "Logged out successfully.",
            ]);

        } catch (\Exception $e) {
            Log::error("[Claude Auth] Failed to clear credentials", [
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

            if (json_last_error() !== JSON_ERROR_NONE || !isset($data["claudeAiOauth"])) {
                return [
                    "authenticated" => false,
                    "message" => "Invalid credentials file",
                ];
            }

            $oauth = $data["claudeAiOauth"];
            $expiresAt = $oauth["expiresAt"] ?? null;
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
                "subscription_type" => $oauth["subscriptionType"] ?? "unknown",
                "expires_at" => $expiresAt ? date("Y-m-d H:i:s", $expiresAt / 1000) : null,
                "scopes" => $oauth["scopes"] ?? [],
                "days_until_expiry" => $expiresAt ? round(($expiresAt - $now) / (1000 * 60 * 60 * 24), 1) : null,
            ];

        } catch (\Exception $e) {
            Log::error("[Claude Auth] Failed to read credentials", [
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
        if (!isset($data["claudeAiOauth"])) {
            return false;
        }

        $oauth = $data["claudeAiOauth"];
        $requiredFields = ["accessToken", "refreshToken", "expiresAt"];

        foreach ($requiredFields as $field) {
            if (!isset($oauth[$field])) {
                return false;
            }
        }

        return true;
    }
}
