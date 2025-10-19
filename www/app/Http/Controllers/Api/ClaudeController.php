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
            $session->addMessage('user', $request->input('prompt'));

            $options = array_merge(
                $request->input('options', []),
                ['cwd' => $session->project_path]
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

        $session->addMessage('user', $request->input('prompt'));

        return response()->stream(function () use ($request, $session) {
            // Disable all output buffering for true streaming
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $options = array_merge(
                $request->input('options', []),
                ['cwd' => $session->project_path]
            );

            try {
                $this->claude->streamQuery(
                    $request->input('prompt'),
                    function ($message) {
                        echo "data: " . json_encode($message) . "\n\n";
                        flush();
                    },
                    $options
                );

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

            // Read first line to get initial prompt
            if ($handle = fopen($file, 'r')) {
                $firstLine = fgets($handle);
                fclose($handle);

                if ($firstLine) {
                    $data = json_decode($firstLine, true);
                    $sessions[] = [
                        'id' => $sessionId,
                        'timestamp' => $data['timestamp'] ?? null,
                        'prompt' => $this->extractPrompt($data),
                        'file_size' => filesize($file),
                        'modified' => filemtime($file),
                    ];
                }
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
                // Try to parse as JSON first
                $decoded = json_decode($content, true);
                if (isset($decoded['prompt'])) {
                    return substr($decoded['prompt'], 0, 100);
                }
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
     */
    protected function extractContent(array $data): string
    {
        if (!isset($data['message']['content'])) {
            return '';
        }

        $content = $data['message']['content'];

        // Handle string content
        if (is_string($content)) {
            return $content;
        }

        // Handle array content (API response format)
        if (is_array($content)) {
            $text = '';
            foreach ($content as $item) {
                if (isset($item['type']) && $item['type'] === 'text' && isset($item['text'])) {
                    $text .= $item['text'];
                }
            }
            return $text;
        }

        return '';
    }
}
