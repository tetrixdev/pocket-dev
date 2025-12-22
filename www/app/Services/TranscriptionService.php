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
        // Note: API key validation moved to transcribeAudio() to avoid chicken-and-egg
        // problem where the controller can't be instantiated to save the key
    }

    public function transcribeAudio(UploadedFile $audioFile): string
    {
        if (empty($this->apiKey)) {
            throw new \Exception('OpenAI API key is not configured. Set it in Config → Credentials.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])
            ->timeout(1800) // 30 minute timeout for longer audio recordings
            ->attach('file', file_get_contents($audioFile->getRealPath()), $audioFile->getClientOriginalName())
            ->post($this->baseUrl . '/v1/audio/transcriptions', [
                'model' => 'gpt-4o-transcribe',
                'response_format' => 'text',
                'language' => 'en', // Optimize for English, but Whisper can auto-detect
            ]);

            if (!$response->successful()) {
                $responseBody = $response->body();
                $errorDetails = json_decode($responseBody, true);

                Log::error('OpenAI transcription API error', [
                    'status' => $response->status(),
                    'response' => $responseBody,
                    'file_size' => $audioFile->getSize(),
                    'file_mime' => $audioFile->getMimeType(),
                ]);

                // Extract user-friendly error message
                if ($errorDetails && isset($errorDetails['error']['message'])) {
                    $errorMessage = $errorDetails['error']['message'];
                } else {
                    $errorMessage = $responseBody;
                }

                throw new \Exception('OpenAI API error: ' . $errorMessage);
            }

            $transcription = $response->body();

            if (empty(trim($transcription))) {
                Log::warning('OpenAI returned empty transcription', [
                    'file_size' => $audioFile->getSize(),
                    'file_mime' => $audioFile->getMimeType(),
                ]);

                throw new \Exception('No speech detected in audio file');
            }

            return $transcription;

        } catch (\Exception $e) {
            Log::error('OpenAI transcription service error', [
                'error' => $e->getMessage(),
                'file_size' => $audioFile->getSize(),
                'file_mime' => $audioFile->getMimeType(),
            ]);

            throw $e;
        }
    }
}
