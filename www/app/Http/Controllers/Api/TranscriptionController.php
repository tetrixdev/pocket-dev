<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TranscriptionService;
use App\Services\AppSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Controller for voice transcription and OpenAI API key management.
 * Used by the chat interface for voice input functionality.
 */
class TranscriptionController extends Controller
{
    public function __construct(
        protected AppSettingsService $appSettings,
        protected TranscriptionService $transcriptionService
    ) {}

    /**
     * Create a realtime transcription session.
     * Returns an ephemeral token for direct WebSocket connection to OpenAI.
     */
    public function createRealtimeSession(): JsonResponse
    {
        try {
            // Check if API key is configured
            if (!$this->appSettings->hasOpenAiApiKey()) {
                return response()->json([
                    'error' => 'OpenAI API key not configured',
                    'requires_setup' => true
                ], 428);
            }

            $session = $this->transcriptionService->createRealtimeSession();

            return response()->json([
                'success' => true,
                'client_secret' => $session['client_secret'],
                'expires_at' => $session['expires_at'],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create realtime session', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to create transcription session: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transcribe an uploaded audio file.
     * Alternative to real-time streaming - records full audio then transcribes.
     */
    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:webm,wav,mp3,m4a,ogg,mp4,mpeg,mpga,flac|max:25600', // 25MB
        ]);

        if (!$this->appSettings->hasOpenAiApiKey()) {
            return response()->json([
                'error' => 'OpenAI API key not configured',
                'requires_setup' => true
            ], 428);
        }

        try {
            $transcription = $this->transcriptionService->transcribeAudio($request->file('audio'));

            return response()->json([
                'success' => true,
                'transcription' => trim($transcription),
            ]);

        } catch (\Exception $e) {
            Log::error('Transcription failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Transcription failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if OpenAI API key is configured.
     */
    public function checkOpenAiKey(): JsonResponse
    {
        return response()->json([
            'configured' => $this->appSettings->hasOpenAiApiKey(),
        ]);
    }

    /**
     * Set OpenAI API key.
     */
    public function setOpenAiKey(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'api_key' => 'required|string|min:20',
            ]);

            $this->appSettings->setOpenAiApiKey($request->input('api_key'));

            return response()->json([
                'success' => true,
                'message' => 'OpenAI API key saved successfully',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Invalid API key format'
            ], 422);
        }
    }

    /**
     * Delete OpenAI API key.
     */
    public function deleteOpenAiKey(): JsonResponse
    {
        $deleted = $this->appSettings->deleteOpenAiApiKey();

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'API key deleted' : 'No API key found',
        ]);
    }

    /**
     * Check if Anthropic API key is configured (for Claude Code CLI).
     */
    public function checkAnthropicKey(): JsonResponse
    {
        return response()->json([
            'configured' => $this->appSettings->hasAnthropicApiKey(),
        ]);
    }

    /**
     * Set Anthropic API key.
     */
    public function setAnthropicKey(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'api_key' => 'required|string|min:20',
            ]);

            $this->appSettings->setAnthropicApiKey($request->input('api_key'));

            return response()->json([
                'success' => true,
                'message' => 'Anthropic API key saved successfully',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Invalid API key format'
            ], 422);
        }
    }

    /**
     * Delete Anthropic API key.
     */
    public function deleteAnthropicKey(): JsonResponse
    {
        $deleted = $this->appSettings->deleteAnthropicApiKey();

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'API key deleted' : 'No API key found',
        ]);
    }
}
