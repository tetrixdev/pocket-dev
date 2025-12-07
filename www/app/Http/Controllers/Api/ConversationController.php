<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessConversationStream;
use App\Models\Conversation;
use App\Services\ProviderFactory;
use App\Services\StreamManager;
use App\Streaming\SseWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for the new multi-provider conversation system.
 * Uses database-backed conversations with background streaming via Redis.
 */
class ConversationController extends Controller
{
    public function __construct(
        private ProviderFactory $providerFactory,
        private StreamManager $streamManager,
    ) {}

    /**
     * Create a new conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'working_directory' => 'required|string|max:500',
            'provider_type' => 'nullable|string|in:anthropic,openai,claude_code',
            'model' => 'nullable|string|max:100',
        ]);

        $providerType = $validated['provider_type'] ?? config('ai.default_provider', 'anthropic');
        $provider = $this->providerFactory->make($providerType);

        $conversation = Conversation::create([
            'provider_type' => $providerType,
            'model' => $validated['model'] ?? config("ai.providers.{$providerType}.default_model"),
            'title' => $validated['title'] ?? null,
            'working_directory' => $validated['working_directory'],
        ]);

        return response()->json([
            'conversation' => $conversation,
            'provider' => [
                'type' => $provider->getProviderType(),
                'available' => $provider->isAvailable(),
                'models' => $provider->getModels(),
            ],
        ], 201);
    }

    /**
     * Get a conversation with its messages.
     */
    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load('messages');

        return response()->json([
            'conversation' => $conversation,
        ]);
    }

    /**
     * List conversations.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Conversation::query()
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('provider_type'), fn($q, $t) => $q->where('provider_type', $t))
            ->when($request->input('working_directory'), fn($q, $d) => $q->where('working_directory', $d))
            ->orderByDesc('last_activity_at');

        if ($request->boolean('include_messages')) {
            $query->with('messages');
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Start streaming a message to the conversation.
     *
     * Dispatches a background job to handle the actual streaming.
     * Frontend should then connect to streamEvents() to receive updates.
     */
    public function stream(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string',
            'thinking_level' => 'nullable|integer|min:0|max:4',
            'response_level' => 'nullable|integer|min:0|max:3',
        ]);

        $provider = $this->providerFactory->make($conversation->provider_type);

        if (!$provider->isAvailable()) {
            return response()->json([
                'success' => false,
                'error' => "Provider '{$conversation->provider_type}' is not available. Check API key configuration.",
            ], 400);
        }

        // Check if already streaming
        if ($this->streamManager->isStreaming($conversation->uuid)) {
            return response()->json([
                'success' => false,
                'error' => 'Conversation is already streaming',
            ], 409);
        }

        $options = [
            'thinking_level' => $validated['thinking_level'] ?? 0,
            'response_level' => $validated['response_level'] ?? config('ai.response.default_level', 1),
        ];

        // Dispatch background job
        ProcessConversationStream::dispatch(
            $conversation->uuid,
            $validated['prompt'],
            $options
        );

        return response()->json([
            'success' => true,
            'conversation_uuid' => $conversation->uuid,
            'message' => 'Streaming started. Connect to /stream-events to receive updates.',
        ]);
    }

    /**
     * Get stream status for a conversation.
     */
    public function streamStatus(Conversation $conversation): JsonResponse
    {
        $status = $this->streamManager->getStatus($conversation->uuid);
        $metadata = $this->streamManager->getMetadata($conversation->uuid);
        $eventCount = $this->streamManager->getEventCount($conversation->uuid);

        return response()->json([
            'conversation_uuid' => $conversation->uuid,
            'stream_status' => $status,  // 'streaming', 'completed', 'failed', or null
            'is_streaming' => $status === 'streaming',
            'event_count' => $eventCount,
            'metadata' => $metadata,
            'conversation_status' => $conversation->status,
        ]);
    }

    /**
     * SSE endpoint for receiving stream events.
     *
     * Sends all buffered events first, then streams live updates.
     * Query params:
     *   - from_index: Start from this event index (for reconnection)
     */
    public function streamEvents(Request $request, Conversation $conversation): StreamedResponse
    {
        $fromIndex = (int) $request->query('from_index', 0);

        return response()->stream(function () use ($conversation, $fromIndex) {
            $sse = new SseWriter();

            // Send all buffered events first
            $events = $this->streamManager->getEvents($conversation->uuid, $fromIndex);
            $currentIndex = $fromIndex;

            foreach ($events as $event) {
                $sse->writeRaw(json_encode(array_merge($event, ['index' => $currentIndex])));
                $currentIndex++;
            }

            // Check if stream is still active
            $status = $this->streamManager->getStatus($conversation->uuid);

            if ($status !== 'streaming') {
                // Stream is done, send final status
                $sse->writeRaw(json_encode([
                    'type' => 'stream_status',
                    'status' => $status ?? 'not_found',
                    'final_index' => $currentIndex - 1,
                ]));
                return;
            }

            // Subscribe to live updates
            // Use polling instead of blocking pub/sub for better compatibility
            $lastCheck = microtime(true);
            $timeout = 60; // 60 second timeout for SSE connection

            while (true) {
                // Check for new events
                $newEvents = $this->streamManager->getEvents($conversation->uuid, $currentIndex);

                foreach ($newEvents as $event) {
                    $sse->writeRaw(json_encode(array_merge($event, ['index' => $currentIndex])));
                    $currentIndex++;
                }

                // Check stream status
                $status = $this->streamManager->getStatus($conversation->uuid);
                if ($status !== 'streaming') {
                    $sse->writeRaw(json_encode([
                        'type' => 'stream_status',
                        'status' => $status,
                        'final_index' => $currentIndex - 1,
                    ]));
                    break;
                }

                // Check timeout
                if ((microtime(true) - $lastCheck) > $timeout) {
                    $sse->writeRaw(json_encode([
                        'type' => 'timeout',
                        'message' => 'Connection timeout, please reconnect',
                        'last_index' => $currentIndex - 1,
                    ]));
                    break;
                }

                // Small delay to prevent CPU spinning
                usleep(100000); // 100ms
                flush();
            }
        }, 200, SseWriter::headers());
    }

    /**
     * Get conversation status (includes both DB status and stream status).
     */
    public function status(Conversation $conversation): JsonResponse
    {
        $provider = $this->providerFactory->make($conversation->provider_type);
        $streamStatus = $this->streamManager->getStatus($conversation->uuid);

        return response()->json([
            'id' => $conversation->id,
            'uuid' => $conversation->uuid,
            'status' => $conversation->status,
            'stream_status' => $streamStatus,
            'is_processing' => $conversation->isProcessing(),
            'is_streaming' => $streamStatus === 'streaming',
            'total_input_tokens' => $conversation->total_input_tokens,
            'total_output_tokens' => $conversation->total_output_tokens,
            'context_window' => $provider->getContextWindow($conversation->model),
            'last_activity_at' => $conversation->last_activity_at,
        ]);
    }

    /**
     * Archive a conversation.
     */
    public function archive(Conversation $conversation): JsonResponse
    {
        $conversation->archive();

        return response()->json([
            'success' => true,
            'status' => $conversation->status,
        ]);
    }

    /**
     * Unarchive a conversation.
     */
    public function unarchive(Conversation $conversation): JsonResponse
    {
        $conversation->unarchive();

        return response()->json([
            'success' => true,
            'status' => $conversation->status,
        ]);
    }

    /**
     * Delete a conversation.
     */
    public function destroy(Conversation $conversation): JsonResponse
    {
        $conversation->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Get available providers and their status.
     */
    public function providers(): JsonResponse
    {
        $providers = [];

        foreach ($this->providerFactory->availableTypes() as $type) {
            $provider = $this->providerFactory->make($type);
            $providers[$type] = [
                'available' => $provider->isAvailable(),
                'models' => $provider->getModels(),
            ];
        }

        return response()->json([
            'default' => config('ai.default_provider', 'anthropic'),
            'providers' => $providers,
        ]);
    }
}
