<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(AppSettingsService $appSettings)
    {
        // Try environment variable first, then fall back to database
        $this->apiKey = config('services.openai.api_key') ?: $appSettings->getOpenAiApiKey();
        $this->baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');

        if (empty($this->apiKey)) {
            throw new \Exception('OpenAI API key is not configured');
        }
    }

    public function transcribeAudio(UploadedFile $audioFile): string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])
            ->timeout(30) // 30 second timeout for audio processing
            ->attach('file', file_get_contents($audioFile->getRealPath()), $audioFile->getClientOriginalName())
            ->post($this->baseUrl . '/audio/transcriptions', [
                'model' => 'gpt-4o-transcribe',
                'response_format' => 'text',
                'language' => 'en', // Optimize for English, but Whisper can auto-detect
            ]);

            if (!$response->successful()) {
                Log::error('OpenAI transcription API error', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'file_size' => $audioFile->getSize(),
                    'file_mime' => $audioFile->getMimeType(),
                ]);

                throw new \Exception('OpenAI API returned error: ' . $response->status());
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
