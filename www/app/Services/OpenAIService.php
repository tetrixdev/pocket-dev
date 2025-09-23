<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
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
                'model' => 'whisper-1',
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

    public function enhanceCommand(string $transcription): string
    {
        // Future enhancement: use GPT to improve voice-to-command conversion
        // For now, just clean up common voice transcription issues

        $enhanced = $this->cleanTranscription($transcription);

        return $enhanced;
    }

    private function cleanTranscription(string $text): string
    {
        // Basic cleanup for common voice transcription issues
        $cleanupRules = [
            // Common voice-to-text corrections for terminal commands
            '/\blist\b/i' => 'ls',
            '/\blist files\b/i' => 'ls -la',
            '/\bchange directory\b/i' => 'cd',
            '/\bmake directory\b/i' => 'mkdir',
            '/\bremove file\b/i' => 'rm',
            '/\bcopy file\b/i' => 'cp',
            '/\bmove file\b/i' => 'mv',
            '/\bprint working directory\b/i' => 'pwd',
            '/\bclear screen\b/i' => 'clear',
            '/\bgit status\b/i' => 'git status',
            '/\bgit add all\b/i' => 'git add .',
            '/\bgit commit\b/i' => 'git commit',
            '/\bdocker compose up\b/i' => 'docker compose up',
            '/\bdocker compose down\b/i' => 'docker compose down',
            '/\bnpm install\b/i' => 'npm install',
            '/\bnpm run dev\b/i' => 'npm run dev',
            '/\bcomposer install\b/i' => 'composer install',
            '/\bartisan\b/i' => 'php artisan',
        ];

        $cleaned = $text;
        foreach ($cleanupRules as $pattern => $replacement) {
            $cleaned = preg_replace($pattern, $replacement, $cleaned);
        }

        // Clean up extra spaces and common punctuation issues
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);

        return $cleaned;
    }
}