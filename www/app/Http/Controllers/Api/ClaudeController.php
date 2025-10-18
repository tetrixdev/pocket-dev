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
            $options = array_merge(
                $request->input('options', []),
                ['cwd' => $session->project_path]
            );

            try {
                $this->claude->streamQuery(
                    $request->input('prompt'),
                    function ($message) {
                        echo "data: " . json_encode($message) . "\n\n";
                        ob_flush();
                        flush();
                    },
                    $options
                );

                $session->markCompleted();
            } catch (\Exception $e) {
                $session->markFailed();
                echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
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
}
