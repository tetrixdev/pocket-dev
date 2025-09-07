<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard');
    }

    public function transcribeAudio(Request $request)
    {
        try {
            $request->validate([
                'audio' => 'required|file|mimes:webm,wav,mp3,m4a|max:10240', // 10MB max
            ]);

            $audioFile = $request->file('audio');
            $openaiApiKey = config('services.openai.api_key');

            if (!$openaiApiKey) {
                return response()->json(['error' => 'OpenAI API key not configured'], 500);
            }

            // Save audio file temporarily for debugging
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "voice_recording_{$timestamp}.webm";
            $storagePath = storage_path('app/audio_recordings');
            
            // Create directory if it doesn't exist
            if (!file_exists($storagePath)) {
                mkdir($storagePath, 0755, true);
            }
            
            $fullPath = $storagePath . '/' . $filename;
            copy($audioFile->getRealPath(), $fullPath);
            
            Log::info('Audio file saved for debugging', [
                'filename' => $filename,
                'path' => $fullPath,
                'size' => filesize($fullPath) . ' bytes'
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $openaiApiKey,
            ])->attach('file', file_get_contents($audioFile->getRealPath()), $audioFile->getClientOriginalName())
              ->post('https://api.openai.com/v1/audio/transcriptions', [
                  'model' => 'gpt-4o-transcribe',
                  'response_format' => 'text',
              ]);

            if ($response->successful()) {
                $transcription = $response->body();
                
                Log::info('Transcription completed', [
                    'filename' => $filename,
                    'transcription' => $transcription,
                    'transcription_length' => strlen($transcription)
                ]);
                
                return response()->json(['transcription' => trim($transcription)]);
            } else {
                Log::error('OpenAI API error', [
                    'filename' => $filename,
                    'response' => $response->body()
                ]);
                return response()->json(['error' => 'Transcription failed'], 500);
            }
        } catch (\Exception $e) {
            Log::error('Audio transcription error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Audio processing failed'], 500);
        }
    }
}
