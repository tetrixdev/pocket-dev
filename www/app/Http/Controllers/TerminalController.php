<?php

namespace App\Http\Controllers;

use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TerminalController extends Controller
{
    protected OpenAIService $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    public function index()
    {
        $wsUrl = $this->generateWebSocketUrl();
        $hasOpenAI = !empty(config('services.openai.api_key'));

        return view('terminal.index', [
            'wsUrl' => $wsUrl,
            'hasOpenAI' => $hasOpenAI,
            'csrfToken' => csrf_token(),
        ]);
    }

    public function transcribe(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'audio' => 'required|file|mimes:webm,wav,mp3,m4a,ogg|max:10240', // 10MB max
            ]);

            if (!config('services.openai.api_key')) {
                return response()->json([
                    'error' => 'OpenAI API key not configured'
                ], 500);
            }

            $audioFile = $request->file('audio');

            // Save audio file temporarily for debugging if needed
            $this->logAudioFile($audioFile);

            $transcription = $this->openAIService->transcribeAudio($audioFile);

            Log::info('Voice transcription completed', [
                'original_name' => $audioFile->getClientOriginalName(),
                'transcription' => $transcription,
                'transcription_length' => strlen($transcription),
            ]);

            return response()->json([
                'transcription' => trim($transcription),
                'success' => true,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Invalid audio file: ' . implode(', ', $e->errors()['audio'] ?? ['Unknown validation error'])
            ], 422);

        } catch (\Exception $e) {
            Log::error('Audio transcription failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Audio processing failed'
            ], 500);
        }
    }

    private function generateWebSocketUrl(): string
    {
        $protocol = request()->secure() ? 'wss' : 'ws';
        $host = request()->getHost();
        $port = config('app.ttyd_port', 7681);

        // Generate a simple session-based token for WebSocket authentication
        $token = $this->generateSessionToken();

        return "{$protocol}://{$host}:{$port}/?token={$token}";
    }

    private function generateSessionToken(): string
    {
        // For now, use a simple session-based token
        // In production, you might want to use JWT or similar
        $sessionId = session()->getId();
        $timestamp = time();

        return base64_encode(json_encode([
            'session' => $sessionId,
            'timestamp' => $timestamp,
            'hash' => hash('sha256', $sessionId . $timestamp . config('app.key')),
        ]));
    }

    private function logAudioFile($audioFile): void
    {
        if (config('app.debug')) {
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "voice_recording_{$timestamp}.{$audioFile->getClientOriginalExtension()}";
            $storagePath = storage_path('app/audio_recordings');

            if (!file_exists($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $fullPath = $storagePath . '/' . $filename;
            copy($audioFile->getRealPath(), $fullPath);

            Log::debug('Audio file saved for debugging', [
                'filename' => $filename,
                'path' => $fullPath,
                'size' => filesize($fullPath) . ' bytes',
            ]);
        }
    }
}