<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessConversationStream;
use App\Models\Agent;
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
     *
     * When agent_id is provided, the conversation is linked to the agent
     * and inherits its settings (provider, model, reasoning, etc.).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'working_directory' => 'required|string|max:500',
            'agent_id' => 'nullable|uuid|exists:agents,id',
            // Legacy fields (used when no agent_id provided)
            'provider_type' => 'nullable|string|in:anthropic,openai,claude_code,openai_compatible',
            'model' => 'nullable|string|max:100',
            'anthropic_thinking_budget' => 'nullable|integer|min:0|max:128000',
            'openai_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
            'openai_compatible_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
            'claude_code_thinking_tokens' => 'nullable|integer|min:0|max:128000',
            'response_level' => 'nullable|integer|min:0|max:5',
        ]);

        // If agent_id provided, use agent settings
        if (!empty($validated['agent_id'])) {
            $agent = Agent::find($validated['agent_id']);

            if (!$agent || !$agent->enabled) {
                return response()->json([
                    'error' => 'Agent not found or disabled',
                ], 400);
            }

            $conversationData = [
                'agent_id' => $agent->id,
                'provider_type' => $agent->provider,
                'model' => $agent->model,
                'title' => $validated['title'] ?? null,
                'working_directory' => $validated['working_directory'],
                'response_level' => $agent->response_level,
                'anthropic_thinking_budget' => $agent->anthropic_thinking_budget,
                'openai_reasoning_effort' => $agent->openai_reasoning_effort,
                'openai_compatible_reasoning_effort' => $agent->openai_compatible_reasoning_effort ?? 'none',
                'claude_code_thinking_tokens' => $agent->claude_code_thinking_tokens,
            ];

            $provider = $this->providerFactory->make($agent->provider);
        } else {
            // Legacy: use individual settings
            $providerType = $validated['provider_type'] ?? config('ai.default_provider', 'anthropic');
            if (!in_array($providerType, $this->providerFactory->availableTypes(), true)) {
                $providerType = 'anthropic';
            }
            $provider = $this->providerFactory->make($providerType);

            $conversationData = [
                'provider_type' => $providerType,
                'model' => $validated['model'] ?? config("ai.providers.{$providerType}.default_model"),
                'title' => $validated['title'] ?? null,
                'working_directory' => $validated['working_directory'],
                'response_level' => $validated['response_level'] ?? config('ai.response.default_level', 1),
            ];

            // Add provider-specific reasoning settings
            if ($providerType === 'anthropic' && isset($validated['anthropic_thinking_budget'])) {
                $conversationData['anthropic_thinking_budget'] = $validated['anthropic_thinking_budget'];
            }
            if ($providerType === 'openai' && isset($validated['openai_reasoning_effort'])) {
                $conversationData['openai_reasoning_effort'] = $validated['openai_reasoning_effort'];
            }
            if ($providerType === 'openai_compatible' && isset($validated['openai_compatible_reasoning_effort'])) {
                $conversationData['openai_compatible_reasoning_effort'] = $validated['openai_compatible_reasoning_effort'];
            }
            if ($providerType === 'claude_code' && isset($validated['claude_code_thinking_tokens'])) {
                $conversationData['claude_code_thinking_tokens'] = $validated['claude_code_thinking_tokens'];
            }
        }

        $conversation = Conversation::create($conversationData);

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
        $conversation->load(['messages', 'agent']);

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
            'model' => 'nullable|string|max:100',
            // Provider-specific reasoning settings
            'anthropic_thinking_budget' => 'nullable|integer|min:0|max:128000',
            'openai_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
            'openai_compatible_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
            'claude_code_thinking_tokens' => 'nullable|integer|min:0|max:128000',
            'response_level' => 'nullable|integer|min:0|max:3',
            // Legacy support - will be converted to provider-specific
            'thinking_level' => 'nullable|integer|min:0|max:4',
        ]);

        // Build update array for conversation settings
        $updates = [];

        // Update model if provided (allows switching mid-conversation)
        if (!empty($validated['model']) && $validated['model'] !== $conversation->model) {
            $updates['model'] = $validated['model'];
        }

        // Update response level if provided
        if (isset($validated['response_level'])) {
            $updates['response_level'] = $validated['response_level'];
        }

        // Handle provider-specific reasoning settings
        if ($conversation->provider_type === 'anthropic') {
            // Anthropic: use budget_tokens directly or convert from legacy thinking_level
            if (isset($validated['anthropic_thinking_budget'])) {
                $updates['anthropic_thinking_budget'] = $validated['anthropic_thinking_budget'];
            } elseif (isset($validated['thinking_level'])) {
                // Legacy support: convert thinking_level to budget_tokens
                $reasoningLevels = config('ai.reasoning.anthropic.levels');
                $thinkingConfig = $reasoningLevels[$validated['thinking_level']] ?? null;
                if ($thinkingConfig) {
                    $updates['anthropic_thinking_budget'] = $thinkingConfig['budget_tokens'] ?? 0;
                }
            }
        } elseif ($conversation->provider_type === 'openai') {
            // OpenAI: use native effort setting
            if (isset($validated['openai_reasoning_effort'])) {
                $updates['openai_reasoning_effort'] = $validated['openai_reasoning_effort'];
            }
        } elseif ($conversation->provider_type === 'openai_compatible') {
            // OpenAI Compatible: use native effort setting (may be ignored by some servers)
            if (isset($validated['openai_compatible_reasoning_effort'])) {
                $updates['openai_compatible_reasoning_effort'] = $validated['openai_compatible_reasoning_effort'];
            }
        } elseif ($conversation->provider_type === 'claude_code') {
            // Claude Code: use thinking_tokens (via MAX_THINKING_TOKENS env var)
            if (isset($validated['claude_code_thinking_tokens'])) {
                $updates['claude_code_thinking_tokens'] = $validated['claude_code_thinking_tokens'];
            } elseif (isset($validated['thinking_level'])) {
                // Legacy support: convert thinking_level to thinking_tokens
                $reasoningLevels = config('ai.reasoning.claude_code.levels');
                $thinkingConfig = $reasoningLevels[$validated['thinking_level']] ?? null;
                if ($thinkingConfig) {
                    $updates['claude_code_thinking_tokens'] = $thinkingConfig['thinking_tokens'] ?? 0;
                }
            }
        }

        // Apply updates to conversation
        if (!empty($updates)) {
            $conversation->update($updates);
        }

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

        // Initialize stream state BEFORE cleanup to prevent race condition
        // This ensures clients won't see 'not_found' after cleanup and before job starts
        $this->streamManager->startStream($conversation->uuid, [
            'model' => $conversation->model,
            'provider' => $conversation->provider_type,
        ]);

        // Clear any old stream events (but keep the status we just set)
        $key = 'stream:' . $conversation->uuid;
        \Illuminate\Support\Facades\Redis::del("{$key}:events");

        // Dispatch background job - reasoning settings are now stored on conversation
        ProcessConversationStream::dispatch(
            $conversation->uuid,
            $validated['prompt'],
            [] // Options no longer needed for reasoning - stored on conversation
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
        $fromIndex = max(0, (int) $request->query('from_index', 0));

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
            $lastActivity = microtime(true);
            $timeout = 60; // 60 second timeout for SSE connection

            while (true) {
                // Exit if client disconnected
                if (connection_aborted()) {
                    break;
                }

                // Check for new events
                $newEvents = $this->streamManager->getEvents($conversation->uuid, $currentIndex);

                foreach ($newEvents as $event) {
                    $sse->writeRaw(json_encode(array_merge($event, ['index' => $currentIndex])));
                    $currentIndex++;
                    $lastActivity = microtime(true); // Update activity time when data is sent
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
                if ((microtime(true) - $lastActivity) > $timeout) {
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
        // Clean up Redis stream data before deletion
        $this->streamManager->cleanup($conversation->uuid);

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
            $providerData = [
                'available' => $provider->isAvailable(),
                'models' => $provider->getModels(),
            ];

            // Include provider-specific reasoning configuration
            $reasoningConfig = config("ai.reasoning.{$type}");
            if ($reasoningConfig) {
                $providerData['reasoning_config'] = $reasoningConfig;
            }

            // Include available tools for claude_code
            if ($type === 'claude_code') {
                $providerData['available_tools'] = config('ai.providers.claude_code.available_tools', []);
            }

            $providers[$type] = $providerData;
        }

        return response()->json([
            'default' => config('ai.default_provider', 'anthropic'),
            'providers' => $providers,
            'response_levels' => config('ai.response.levels'),
        ]);
    }
}
