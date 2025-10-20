<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClaudeSession;
use App\Services\ClaudeCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaudeController extends Controller
{
    public function __construct(
        protected ClaudeCodeService $claude
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
     * Send a query to Claude (non-streaming).
     */
    public function query(Request $request, ClaudeSession $session): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'prompt' => 'required|string',
            'options' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            // Determine if this is the first message
            $isFirstMessage = $session->turn_count == 0;

            $session->addMessage('user', $request->input('prompt'));

            $options = array_merge(
                $request->input('options', []),
                [
                    'cwd' => $session->project_path,
                    'sessionId' => $session->claude_session_id,
                    'isFirstMessage' => $isFirstMessage
                ]
            );

            $response = $this->claude->query(
                $request->input('prompt'),
                $options
            );

            $session->addMessage('assistant', $response);

            return response()->json([
                'session' => $session->fresh(),
                'response' => $response,
            ]);
        } catch (\Exception $e) {
            $session->markFailed();

            return response()->json([
                'error' => 'Query failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a streaming query to Claude (Server-Sent Events).
     */
    public function streamQuery(Request $request, ClaudeSession $session): StreamedResponse
    {
        $validator = Validator::make($request->all(), [
            'prompt' => 'required|string',
            'options' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // Determine if this is the first message (before adding current message)
        $isFirstMessage = $session->turn_count == 0;

        $session->addMessage('user', $request->input('prompt'));

        return response()->stream(function () use ($request, $session, $isFirstMessage) {
            // Disable all output buffering for true streaming
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $options = array_merge(
                $request->input('options', []),
                [
                    'cwd' => $session->project_path,
                    'sessionId' => $session->claude_session_id,
                    'isFirstMessage' => $isFirstMessage
                ]
            );

            // Accumulate the assistant's response content (structured)
            $assistantContent = [];
            $currentBlockIndex = -1;

            try {
                $this->claude->streamQuery(
                    $request->input('prompt'),
                    function ($message) use (&$assistantContent, &$currentBlockIndex) {
                        // Track assistant message content blocks
                        if (isset($message['type']) && $message['type'] === 'assistant') {
                            // Complete assistant message with content array
                            if (isset($message['message']['content']) && is_array($message['message']['content'])) {
                                $assistantContent = $message['message']['content'];
                            }
                        }

                        // Track content blocks during streaming
                        if (isset($message['event']['type'])) {
                            $eventType = $message['event']['type'];

                            if ($eventType === 'content_block_start') {
                                $currentBlockIndex++;
                                $block = $message['event']['content_block'] ?? [];
                                $assistantContent[$currentBlockIndex] = $block;
                            } elseif ($eventType === 'content_block_delta' && $currentBlockIndex >= 0) {
                                $delta = $message['event']['delta'] ?? [];

                                // Accumulate text deltas
                                if (isset($delta['text'])) {
                                    if (!isset($assistantContent[$currentBlockIndex]['type'])) {
                                        $assistantContent[$currentBlockIndex]['type'] = 'text';
                                    }
                                    if (!isset($assistantContent[$currentBlockIndex]['text'])) {
                                        $assistantContent[$currentBlockIndex]['text'] = '';
                                    }
                                    $assistantContent[$currentBlockIndex]['text'] .= $delta['text'];
                                }

                                // Accumulate thinking deltas
                                if (isset($delta['thinking'])) {
                                    if (!isset($assistantContent[$currentBlockIndex]['thinking'])) {
                                        $assistantContent[$currentBlockIndex]['thinking'] = '';
                                    }
                                    $assistantContent[$currentBlockIndex]['thinking'] .= $delta['thinking'];
                                }

                                // Accumulate tool input JSON deltas
                                if (isset($delta['partial_json'])) {
                                    if (!isset($assistantContent[$currentBlockIndex]['input_json'])) {
                                        $assistantContent[$currentBlockIndex]['input_json'] = '';
                                    }
                                    $assistantContent[$currentBlockIndex]['input_json'] .= $delta['partial_json'];
                                }
                            }
                        }

                        echo "data: " . json_encode($message) . "\n\n";
                        flush();
                    },
                    $options
                );

                // Parse tool input JSON and save structured content
                foreach ($assistantContent as &$block) {
                    if (isset($block['input_json'])) {
                        $block['input'] = json_decode($block['input_json'], true);
                        unset($block['input_json']);
                    }
                }

                // Save the accumulated assistant response with full structure
                if (!empty($assistantContent)) {
                    $session->addMessage('assistant', $assistantContent);
                }

                $session->markCompleted();
            } catch (\Exception $e) {
                $session->markFailed();
                echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
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
     * Get a specific session.
     */
    public function show(ClaudeSession $session): JsonResponse
    {
        return response()->json($session);
    }

    /**
     * Delete a session.
     */
    public function destroy(ClaudeSession $session): JsonResponse
    {
        $session->delete();

        return response()->json(['message' => 'Session deleted'], 200);
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

            if ($data && isset($data['type']) && in_array($data['type'], ['user', 'assistant'])) {
                $messages[] = [
                    'role' => $data['type'],
                    'content' => $this->extractContent($data),
                    'timestamp' => $data['timestamp'] ?? null,
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
}
