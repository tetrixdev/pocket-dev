<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClaudeSession;
use App\Services\ClaudeCodeService;
use App\Services\OpenAIService;
use App\Services\AppSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaudeController extends Controller
{
    public function __construct(
        protected ClaudeCodeService $claude,
        protected AppSettingsService $appSettings
    ) {}

    /**
     * Create a new Claude session.
     */
    public function createSession(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'project_path' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $session = ClaudeSession::create([
            'title' => $request->input('title'),
            'project_path' => $request->input('project_path'),
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        return response()->json([
            'session' => $session,
        ], 201);
    }


    /**
     * Send a streaming query to Claude (Server-Sent Events).
     */
    public function streamQuery(Request $request, ClaudeSession $session): StreamedResponse
    {
        \Log::info('[ClaudeController] streamQuery called', [
            'session_id' => $session->id,
            'claude_session_id' => $session->claude_session_id,
            'prompt_length' => strlen($request->input('prompt')),
            'prompt_preview' => substr($request->input('prompt'), 0, 50),
        ]);

        $validator = Validator::make($request->all(), [
            'prompt' => 'required|string',
            'options' => 'nullable|array',
            'thinking_level' => 'nullable|integer|min:0|max:4',
        ]);

        if ($validator->fails()) {
            \Log::error('[ClaudeController] Validation failed', [
                'errors' => $validator->errors()
            ]);
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // Determine if this is the first message
        $isFirstMessage = $session->turn_count == 0;

        \Log::info('[ClaudeController] Starting stream', [
            'session_id' => $session->id,
            'turn_count' => $session->turn_count,
            'is_first_message' => $isFirstMessage,
            'current_process_pid' => $session->process_pid,
            'current_process_status' => $session->process_status,
        ]);

        // Increment turn count
        $session->incrementTurn();

        return response()->stream(function () use ($request, $session, $isFirstMessage) {
            // Disable all output buffering for true streaming
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $options = array_merge(
                $request->input('options', []),
                [
                    'cwd' => $session->project_path,
                    'model' => config('claude.model'),
                    'permission_mode' => config('claude.permission_mode'),
                ]
            );

            $stdoutPath = "/tmp/claude-{$session->claude_session_id}-stdout.jsonl";

            try {
                // Refresh session from DB to get latest process state
                $session->refresh();

                // Determine if this is a new turn or reconnection to in-progress stream
                $hasPid = !empty($session->process_pid);
                $processAlive = $hasPid ? $this->claude->isProcessAlive($session->process_pid) : false;
                $isNewTurn = !$hasPid || !$processAlive;

                \Log::info('[ClaudeController] DEBUG: Stream type detection', [
                    'session_process_pid' => $session->process_pid,
                    'session_process_status' => $session->process_status,
                    'session_status' => $session->status,
                    'has_pid' => $hasPid,
                    'process_alive_check' => $processAlive,
                    'is_new_turn' => $isNewTurn,
                    'logic' => 'is_new_turn = !has_pid || !process_alive'
                ]);

                // Start background process if this is a new turn
                if ($isNewTurn) {
                    \Log::info('[ClaudeController] Starting NEW background process for new turn');

                    $pid = $this->claude->startBackgroundProcess(
                        $request->input('prompt'),
                        $options,
                        $session->claude_session_id,
                        $isFirstMessage,
                        $request->input('thinking_level', 0)
                    );

                    $session->startStreaming($pid);

                    // Log file status immediately after process start
                    \Log::info('[ClaudeController] Background process started', [
                        'pid' => $pid,
                        'file_exists_before_sleep' => file_exists($stdoutPath),
                        'file_size_before_sleep' => file_exists($stdoutPath) ? filesize($stdoutPath) : 0
                    ]);

                    // Give process a moment to start writing
                    usleep(100000); // 100ms

                    \Log::info('[ClaudeController] After 100ms sleep', [
                        'file_exists_after_sleep' => file_exists($stdoutPath),
                        'file_size_after_sleep' => file_exists($stdoutPath) ? filesize($stdoutPath) : 0
                    ]);
                }

                // Read existing lines ONLY if reconnecting to in-progress stream
                // For new turns, skip old data and start from current file position
                $linesSent = 0;
                if (!$isNewTurn && file_exists($stdoutPath)) {
                    \Log::info('[ClaudeController] RECONNECTION: Replaying existing lines from in-progress stream');
                    $existingLines = @file($stdoutPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                    \Log::info('[ClaudeController] Reading existing lines', [
                        'file_size' => filesize($stdoutPath),
                        'lines_count' => count($existingLines),
                        'line_previews' => array_map(function($line) {
                            $decoded = json_decode($line, true);
                            return [
                                'type' => $decoded['type'] ?? 'unknown',
                                'message_role' => $decoded['message']['role'] ?? null,
                                'event_type' => $decoded['event']['type'] ?? null,
                            ];
                        }, array_slice($existingLines, 0, 5))
                    ]);
                    foreach ($existingLines as $idx => $line) {
                        \Log::debug("[ClaudeController] Sending existing line {$idx}: " . substr($line, 0, 100));
                        echo "data: {$line}\n\n";
                        flush();
                        $linesSent++;
                    }
                } elseif ($isNewTurn && file_exists($stdoutPath)) {
                    \Log::info('[ClaudeController] NEW TURN: Skipping old data, will tail only new content', [
                        'existing_file_size' => filesize($stdoutPath)
                    ]);
                } else {
                    \Log::warning('[ClaudeController] Output file does not exist yet');
                }

                // Track file position for tailing
                $lastSize = file_exists($stdoutPath) ? filesize($stdoutPath) : 0;
                $lastPosition = $lastSize; // Start from current position (end of file for new turns, or after replayed lines for reconnection)

                \Log::info('[ClaudeController] Starting tail with clearstatcache approach', [
                    'existing_lines' => $linesSent,
                    'pid' => $session->process_pid,
                    'file_exists' => file_exists($stdoutPath),
                    'initial_file_size' => $lastSize,
                    'starting_position' => $lastPosition
                ]);

                // Tail for new lines while process is running
                $maxWaitTime = 300; // 5 minutes max
                $startTime = time();
                $loopCount = 0;
                $linesRead = 0;
                $noGrowthCount = 0;

                while (true) {
                    $loopCount++;

                    // Clear stat cache to get fresh file size
                    clearstatcache(false, $stdoutPath);
                    $currentSize = file_exists($stdoutPath) ? filesize($stdoutPath) : 0;

                    // Check if file has grown
                    if ($currentSize > $lastSize) {
                        $noGrowthCount = 0; // Reset no-growth counter

                        // Open file, seek to last position, read new data
                        $file = fopen($stdoutPath, 'r');
                        if ($file) {
                            fseek($file, $lastPosition);

                            // Read all new lines
                            while (!feof($file)) {
                                $line = fgets($file);
                                if ($line !== false && trim($line) !== '') {
                                    $linesRead++;
                                    echo "data: " . trim($line) . "\n\n";
                                    flush();
                                }
                            }

                            // Update position
                            $lastPosition = ftell($file);
                            fclose($file);

                            $lastSize = $currentSize;
                        }
                    } else {
                        // No growth detected
                        $noGrowthCount++;

                        // Check if process finished
                        $processAlive = $this->claude->isProcessAlive($session->process_pid);

                        if (!$processAlive) {
                            // Process died, do one final read to catch any remaining data
                            clearstatcache(false, $stdoutPath);
                            $finalSize = file_exists($stdoutPath) ? filesize($stdoutPath) : 0;

                            if ($finalSize > $lastSize) {
                                $file = fopen($stdoutPath, 'r');
                                if ($file) {
                                    fseek($file, $lastPosition);
                                    while (!feof($file)) {
                                        $line = fgets($file);
                                        if ($line !== false && trim($line) !== '') {
                                            $linesRead++;
                                            echo "data: " . trim($line) . "\n\n";
                                            flush();
                                        }
                                    }
                                    fclose($file);
                                }
                            }

                            \Log::info('[ClaudeController] Process finished', [
                                'loop_iterations' => $loopCount,
                                'lines_read_in_tail' => $linesRead,
                                'final_file_size' => $finalSize
                            ]);
                            break;
                        }

                        // If no growth for 10 seconds after process died, assume done
                        if (!$processAlive && $noGrowthCount > 200) {
                            \Log::info('[ClaudeController] No growth after process finished', [
                                'no_growth_iterations' => $noGrowthCount
                            ]);
                            break;
                        }
                    }

                    // Check timeout
                    if ((time() - $startTime) > $maxWaitTime) {
                        \Log::warning('[ClaudeController] Timeout reached', [
                            'loop_iterations' => $loopCount,
                            'lines_read_in_tail' => $linesRead
                        ]);
                        break;
                    }

                    // Wait 50ms before next check
                    usleep(50000);

                    // Log every 100 iterations (every ~5 seconds)
                    if ($loopCount % 100 === 0) {
                        \Log::info('[ClaudeController] Tail loop status', [
                            'loop_count' => $loopCount,
                            'lines_read' => $linesRead,
                            'process_alive' => $this->claude->isProcessAlive($session->process_pid),
                            'current_file_size' => $currentSize,
                            'last_position' => $lastPosition,
                            'elapsed_seconds' => time() - $startTime
                        ]);
                    }
                }

                \Log::info('[ClaudeController] Closing and completing', [
                    'total_lines_sent' => $linesSent,
                    'lines_read_in_tail' => $linesRead,
                    'total_loop_iterations' => $loopCount
                ]);

                $session->completeStreaming();

                \Log::info('[ClaudeController] Session state after completion', [
                    'process_pid' => $session->process_pid,
                    'process_status' => $session->process_status,
                ]);

            } catch (\Exception $e) {
                \Log::error('[ClaudeController] Streaming failed', [
                    'error' => $e->getMessage()
                ]);
                $session->markFailed();
                echo "data: " . json_encode(['type' => 'error', 'error' => $e->getMessage()]) . "\n\n";
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Get historical streaming messages for reconnection.
     */
    public function getStreamingHistory(ClaudeSession $session): JsonResponse
    {
        $stdoutPath = "/tmp/claude-{$session->claude_session_id}-stdout.jsonl";

        if (!file_exists($stdoutPath)) {
            return response()->json(['messages' => []]);
        }

        // Read all messages from stdout file
        $lines = @file($stdoutPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $messages = [];

        if ($lines) {
            foreach ($lines as $line) {
                $message = json_decode($line, true);
                if ($message) {
                    $messages[] = $message;
                }
            }
        }

        return response()->json([
            'messages' => $messages,
            'last_index' => count($messages),
        ]);
    }

    /**
     * Get session status.
     */
    public function getSessionStatus(ClaudeSession $session): JsonResponse
    {
        return response()->json([
            'session_id' => $session->id,
            'claude_session_id' => $session->claude_session_id,
            'process_pid' => $session->process_pid,
            'process_status' => $session->process_status,
            'last_message_index' => $session->last_message_index,
            'turn_count' => $session->turn_count,
            'status' => $session->status,
        ]);
    }


    /**
     * Get all sessions.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = ClaudeSession::query()
            ->when($request->input('status'), fn($q, $status) => $q->where('status', $status))
            ->when($request->input('project_path'), fn($q, $path) => $q->where('project_path', $path))
            ->orderBy('last_activity_at', 'desc')
            ->paginate(20);

        return response()->json($sessions);
    }


    /**
     * Check if Claude CLI is available.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'available' => $this->claude->isAvailable(),
            'version' => $this->claude->getVersion(),
        ]);
    }

    /**
     * List sessions from Claude's storage.
     */
    public function listClaudeSessions(Request $request): JsonResponse
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $projectPath = $request->input('project_path', '/var/www');

        // Convert path to directory name (e.g., /var/www -> -var-www)
        $dirName = str_replace('/', '-', $projectPath);
        $sessionsDir = "{$home}/.claude/projects/{$dirName}";

        if (!is_dir($sessionsDir)) {
            return response()->json(['sessions' => []]);
        }

        $sessions = [];
        $files = glob("{$sessionsDir}/*.jsonl");

        foreach ($files as $file) {
            $sessionId = basename($file, '.jsonl');

            // Read lines to get the first real user prompt (skip "Warmup" messages)
            if ($handle = fopen($file, 'r')) {
                $prompt = 'Unnamed session';
                $timestamp = null;

                while (($line = fgets($handle)) !== false) {
                    $data = json_decode($line, true);

                    if ($data && $data['type'] === 'user') {
                        $extractedPrompt = $this->extractPrompt($data);

                        // Skip "Warmup" messages and look for the first real prompt
                        if (strtolower(trim($extractedPrompt)) !== 'warmup') {
                            $prompt = $extractedPrompt;
                            $timestamp = $data['timestamp'] ?? null;
                            break;
                        }
                    }
                }

                fclose($handle);

                $sessions[] = [
                    'id' => $sessionId,
                    'timestamp' => $timestamp,
                    'prompt' => $prompt,
                    'file_size' => filesize($file),
                    'modified' => filemtime($file),
                ];
            }
        }

        // Sort by modified time (newest first)
        usort($sessions, fn($a, $b) => $b['modified'] - $a['modified']);

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * Load a specific session history from Claude's storage.
     */
    public function loadClaudeSession(Request $request, string $sessionId): JsonResponse
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $projectPath = $request->input('project_path', '/var/www');

        // Convert path to directory name
        $dirName = str_replace('/', '-', $projectPath);
        $sessionFile = "{$home}/.claude/projects/{$dirName}/{$sessionId}.jsonl";

        if (!file_exists($sessionFile)) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $messages = [];
        $handle = fopen($sessionFile, 'r');

        while (($line = fgets($handle)) !== false) {
            $data = json_decode($line, true);

            // Filter out sidechain messages (warmup and other parallel conversations)
            $isSidechain = $data['isSidechain'] ?? false;

            if ($data && isset($data['type']) && in_array($data['type'], ['user', 'assistant']) && !$isSidechain) {
                // Usage can be at root level OR inside message object
                $usage = $data['usage'] ?? $data['message']['usage'] ?? null;
                $model = $data['message']['model'] ?? null;

                // Calculate cost server-side
                $cost = null;
                if ($usage && $model) {
                    $cost = $this->calculateMessageCost($usage, $model);
                }

                $messages[] = [
                    'role' => $data['type'],
                    'content' => $this->extractContent($data),
                    'timestamp' => $data['timestamp'] ?? null,
                    'usage' => $usage,
                    'model' => $model,
                    'cost' => $cost,
                ];
            }
        }

        fclose($handle);

        return response()->json([
            'session_id' => $sessionId,
            'messages' => $messages,
        ]);
    }

    /**
     * Calculate cost for a message based on usage and model pricing.
     */
    private function calculateMessageCost(?array $usage, ?string $modelName): ?float
    {
        if (!$usage || !$modelName) {
            \Log::debug('[COST-CALC] Missing usage or model', ['usage' => !!$usage, 'model' => $modelName]);
            return null;
        }

        // Get pricing for this model
        $pricing = \App\Models\ModelPricing::where('model_name', $modelName)->first();

        if (!$pricing || !$pricing->input_price_per_million || !$pricing->output_price_per_million) {
            \Log::debug('[COST-CALC] No pricing configured for model', [
                'model' => $modelName,
                'pricing_exists' => !!$pricing,
                'has_input_price' => $pricing ? !!$pricing->input_price_per_million : false,
                'has_output_price' => $pricing ? !!$pricing->output_price_per_million : false
            ]);
            return null;
        }

        // Extract token counts
        $inputTokens = $usage['input_tokens'] ?? 0;
        $cacheCreationTokens = $usage['cache_creation_input_tokens'] ?? 0;
        $cacheReadTokens = $usage['cache_read_input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;

        // Get multipliers (with defaults)
        $cacheWriteMultiplier = $pricing->cache_write_multiplier ?? 1.25;
        $cacheReadMultiplier = $pricing->cache_read_multiplier ?? 0.1;

        // Calculate costs with multipliers
        $inputCost = ($inputTokens / 1000000) * $pricing->input_price_per_million;
        $cacheWriteCost = ($cacheCreationTokens / 1000000) * $pricing->input_price_per_million * $cacheWriteMultiplier;
        $cacheReadCost = ($cacheReadTokens / 1000000) * $pricing->input_price_per_million * $cacheReadMultiplier;
        $outputCost = ($outputTokens / 1000000) * $pricing->output_price_per_million;

        $totalCost = $inputCost + $cacheWriteCost + $cacheReadCost + $outputCost;

        \Log::debug('[COST-CALC] Calculated cost', [
            'model' => $modelName,
            'inputTokens' => $inputTokens,
            'cacheCreationTokens' => $cacheCreationTokens,
            'cacheReadTokens' => $cacheReadTokens,
            'outputTokens' => $outputTokens,
            'totalCost' => $totalCost
        ]);

        return $totalCost;
    }

    /**
     * Extract prompt from session data.
     */
    protected function extractPrompt(array $data): string
    {
        if (isset($data['message']['content'])) {
            $content = $data['message']['content'];

            if (is_string($content)) {
                // Try to parse as JSON first (CLI sends {"prompt":"..."})
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['prompt'])) {
                    return substr($decoded['prompt'], 0, 100);
                }
                // Otherwise return the plain string content
                return substr($content, 0, 100);
            }

            if (is_array($content) && isset($content[0]['text'])) {
                return substr($content[0]['text'], 0, 100);
            }
        }

        return 'Unnamed session';
    }

    /**
     * Extract message content from session data.
     * Returns the full content structure including thinking, tool calls, etc.
     */
    protected function extractContent(array $data): mixed
    {
        if (!isset($data['message']['content'])) {
            return '';
        }

        $content = $data['message']['content'];

        // Return content as-is (could be string or structured array)
        return $content;
    }

    /**
     * Cancel a running Claude request and write interruption marker.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $session = ClaudeSession::findOrFail($id);

        // Mark as cancelling
        $session->updateProcessStatus('cancelled');

        // Try to kill the Claude CLI process
        $killed = false;
        if ($session->process_pid) {
            $killed = $this->claude->killProcess($session->process_pid);

            // Wait 1 second for in-flight blocks to flush to disk
            if ($killed) {
                sleep(1);
            }
        }

        // Write interruption marker to session file
        $this->claude->writeInterruptionMarker(
            $session->claude_session_id,
            $session->project_path
        );

        // Clean up
        $session->cancelStreaming();

        \Log::info('[Claude Code] Request cancelled by user', [
            'session_id' => $session->id,
            'claude_session_id' => $session->claude_session_id,
            'process_killed' => $killed
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request cancelled',
            'process_killed' => $killed,
            'process_status' => 'cancelled'
        ]);
    }

    /**
     * Transcribe audio using OpenAI Whisper.
     */
    public function transcribe(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'audio' => 'required|file|mimes:webm,wav,mp3,m4a,ogg|max:10240', // 10MB max
            ]);

            // Check if API key is configured
            if (!$this->appSettings->hasOpenAiApiKey()) {
                return response()->json([
                    'error' => 'OpenAI API key not configured',
                    'requires_setup' => true
                ], 428); // 428 Precondition Required
            }

            $audioFile = $request->file('audio');

            // Log audio file for debugging if needed
            if (config('app.debug')) {
                $this->logAudioFile($audioFile);
            }

            // Transcribe using OpenAI service
            $openAI = app(OpenAIService::class);
            $transcription = $openAI->transcribeAudio($audioFile);

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
                'error' => 'Audio processing failed: ' . $e->getMessage()
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
     * Log audio file for debugging.
     */
    private function logAudioFile($audioFile): void
    {
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
