<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranscriptionService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(AppSettingsService $appSettings)
    {
        // API key from database (set via UI)
        $this->apiKey = $appSettings->getOpenAiApiKey() ?? '';
        $baseUrl = config('ai.transcription.base_url') ?? config('ai.providers.openai.base_url') ?? 'https://api.openai.com';
        $this->baseUrl = rtrim($baseUrl, '/');
        // Normalize: remove /v1 suffix if present (will be added in request path)
        if (str_ends_with($this->baseUrl, '/v1')) {
            $this->baseUrl = substr($this->baseUrl, 0, -3);
        }
        // Note: API key validation moved to createRealtimeSession() to avoid chicken-and-egg
        // problem where the controller can't be instantiated to save the key
    }

    /**
     * Create an ephemeral token for OpenAI Realtime API transcription.
     * This token can be safely passed to the browser for direct WebSocket connection.
     *
     * @return array{client_secret: string, expires_at: int}
     * @throws \Exception
     */
    public function createRealtimeSession(): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('OpenAI API key is not configured. Set it in Config → Credentials.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->post($this->baseUrl . '/v1/realtime/client_secrets', [
                'expires_after' => [
                    'anchor' => 'created_at',
                    'seconds' => 120,
                ],
                'session' => [
                    'type' => 'transcription',
                    'audio' => [
                        'input' => [
                            'format' => [
                                'type' => 'audio/pcm',
                                'rate' => 24000,
                            ],
                            'transcription' => [
                                'model' => 'gpt-4o-transcribe',
                                'language' => 'en', // ISO-639-1 format
                            ],
                            'noise_reduction' => [
                                'type' => 'near_field',
                            ],
                            // Server-side VAD: detects speech pauses to segment transcription
                            // Note: silence_duration_ms increased from default 500 to 800 to reduce
                            // premature cutoff of final words (per OpenAI Cookbook recommendations)
                            'turn_detection' => [
                                'type' => 'server_vad',
                                'threshold' => 0.5,           // Speech detection sensitivity (0-1)
                                'prefix_padding_ms' => 300,   // Audio to include before speech
                                'silence_duration_ms' => 800, // Silence before ending turn (increased from 500)
                            ],
                        ],
                    ],
                ],
            ]);

            if (!$response->successful()) {
                $responseBody = $response->body();
                $errorDetails = json_decode($responseBody, true);

                Log::error('OpenAI Realtime session creation failed', [
                    'status' => $response->status(),
                    'response' => $responseBody,
                ]);

                $errorMessage = $errorDetails['error']['message'] ?? $responseBody;
                throw new \Exception('Failed to create realtime session: ' . $errorMessage);
            }

            $data = $response->json();

            if (empty($data['value'])) {
                throw new \Exception('Invalid response from OpenAI: missing client secret value');
            }

            return [
                'client_secret' => $data['value'],
                'expires_at' => $data['expires_at'] ?? (time() + 120),
            ];

        } catch (\Exception $e) {
            Log::error('OpenAI Realtime session error', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Transcribe an audio file using OpenAI Whisper API.
     * This is an alternative to real-time streaming - records full audio then transcribes.
     *
     * @param UploadedFile $audioFile The audio file to transcribe
     * @return string The transcribed text
     * @throws \Exception
     */
    public function transcribeAudio(UploadedFile $audioFile): string
    {
        if (empty($this->apiKey)) {
            throw new \Exception('OpenAI API key is not configured. Set it in Config → Credentials.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])
            ->timeout(1800) // 30 minutes for long recordings
            ->attach('file', file_get_contents($audioFile->getRealPath()), $audioFile->getClientOriginalName())
            ->post($this->baseUrl . '/v1/audio/transcriptions', [
                'model' => 'gpt-4o-transcribe',
                'response_format' => 'text',
                'language' => 'en', // Force English to avoid detection issues on short segments
                'temperature' => 0.2, // Lower temperature for more consistent transcription
                // Anti-truncation prompt: gpt-4o-transcribe has a known tendency to truncate
                // the final sentence. This prompt significantly reduces that behavior.
                // See: https://community.openai.com/t/persistent-truncation-issues-with-gpt-4o-transcribe
                'prompt' => 'Transcribe completely. Do NOT omit, summarize, or truncate any speech. Output every word as spoken, including the final sentence.',
            ]);

            if (!$response->successful()) {
                $responseBody = $response->body();
                $errorDetails = json_decode($responseBody, true);

                Log::error('OpenAI transcription failed', [
                    'status' => $response->status(),
                    'response' => $responseBody,
                    'filename' => $audioFile->getClientOriginalName(),
                    'size' => $audioFile->getSize(),
                ]);

                $errorMessage = $errorDetails['error']['message'] ?? $responseBody;
                throw new \Exception('Transcription failed: ' . $errorMessage);
            }

            return $response->body();

        } catch (\Exception $e) {
            Log::error('OpenAI transcription error', [
                'error' => $e->getMessage(),
                'filename' => $audioFile->getClientOriginalName(),
            ]);
            throw $e;
        }
    }
}
