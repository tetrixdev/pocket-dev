<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessConversationStream;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Screen;
use App\Models\Session;
use App\Models\Workspace;
use App\Services\ConversationFactory;
use App\Services\ConversationStreamLogger;
use App\Services\NativeToolService;
use App\Services\ProviderFactory;
use App\Services\RequestFlowLogger;
use App\Services\StreamManager;
use App\Streaming\SseWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        private ConversationFactory $conversationFactory,
    ) {}

    /**
     * Create a new conversation.
     *
     * When agent_id is provided, the conversation is linked to the agent
     * and inherits its settings (provider, model, reasoning, etc.).
     *
     * Uses ConversationFactory to ensure all settings are properly copied.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'working_directory' => 'required|string|max:500',
            'workspace_id' => 'nullable|uuid|exists:workspaces,id',
            'agent_id' => 'nullable|uuid|exists:agents,id',
            // Legacy fields (used when no agent_id provided)
            'provider_type' => 'nullable|string|in:anthropic,openai,claude_code,codex,openai_compatible',
            'model' => 'nullable|string|max:100',
        ]);

        // If agent_id provided, use agent settings via factory
        if (!empty($validated['agent_id'])) {
            $agent = Agent::find($validated['agent_id']);

            if (!$agent || !$agent->enabled) {
                return response()->json([
                    'error' => 'Agent not found or disabled',
                ], 400);
            }

            // Use factory with session structure if workspace provided
            if (!empty($validated['workspace_id'])) {
                $workspace = Workspace::find($validated['workspace_id']);
                if (!$workspace) {
                    return response()->json(['error' => 'Workspace not found'], 404);
                }
                $result = $this->conversationFactory->createWithSessionStructure(
                    $agent,
                    $workspace,
                    $validated['title'] ?? null
                );
                $conversation = $result['conversation'];
            } else {
                // No workspace - create conversation only
                $conversation = $this->conversationFactory->createFromAgent(
                    $agent,
                    $validated['working_directory'],
                    null,
                    $validated['title'] ?? null
                );
            }

            $provider = $this->providerFactory->make($agent->provider);
        } else {
            // Legacy: use defaults via factory
            $conversation = $this->conversationFactory->createWithDefaults(
                $validated['working_directory'],
                $validated['workspace_id'] ?? null,
                $validated['title'] ?? null,
                $validated['provider_type'] ?? null,
                $validated['model'] ?? null
            );

            // Create session and screen for this conversation if workspace provided
            if ($conversation->workspace_id) {
                DB::transaction(function () use ($conversation) {
                    $session = Session::create([
                        'workspace_id' => $conversation->workspace_id,
                        'name' => $conversation->title ?? 'New Session',
                        'screen_order' => [],
                    ]);

                    $screen = Screen::createChatScreen($session, $conversation);

                    // screen_order already set by createChatScreen, just set active screen
                    $session->update([
                        'last_active_screen_id' => $screen->id,
                    ]);

                    // Load the screen relationship for the response
                    $conversation->load('screen.session.screens.conversation');
                });
            }

            $provider = $this->providerFactory->make($conversation->provider_type);
        }

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
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->load(['messages.agent', 'agent', 'screen.session.screens.conversation']);

        return response()->json([
            'conversation' => $conversation,
            'context' => [
                'last_context_tokens' => $conversation->last_context_tokens,
                'context_window_size' => $conversation->context_window_size,
                'usage_percentage' => $conversation->getContextUsagePercentage(),
                'warning_level' => $conversation->getContextWarningLevel(),
            ],
        ]);
    }

    /**
     * List conversations.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Conversation::query()
            ->with(['agent:id,name'])
            ->when($request->input('workspace_id'), fn($q, $w) => $q->forWorkspace($w))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('provider_type'), fn($q, $t) => $q->where('provider_type', $t))
            ->when($request->input('working_directory'), fn($q, $d) => $q->where('working_directory', $d))
            ->orderByDesc('last_activity_at');

        // By default, exclude archived conversations unless include_archived=true
        if (!$request->boolean('include_archived') && !$request->input('status')) {
            $query->where('status', '!=', 'archived');
        }

        if ($request->boolean('include_messages')) {
            $query->with('messages.agent');
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Get the latest activity timestamp for polling.
     * Used by frontend to check if sidebar needs refreshing.
     */
    public function latestActivity(): JsonResponse
    {
        $latest = Conversation::max('last_activity_at');

        return response()->json([
            'latest_activity_at' => $latest,
        ]);
    }

    /**
     * Start streaming a message to the conversation.
     *
     * Dispatches a background job to handle the actual streaming.
     * Frontend should then connect to streamEvents() to receive updates.
     */
    public function stream(Request $request, Conversation $conversation): JsonResponse
    {
        RequestFlowLogger::startRequest($conversation->uuid, 'stream');
        RequestFlowLogger::log('controller.stream.entry', 'Stream request received', [
            'conversation_uuid' => $conversation->uuid,
            'provider_type' => $conversation->provider_type,
            'model' => $conversation->model,
        ]);

        try {
            $validated = $request->validate([
            'prompt' => 'required|string',
            'model' => 'nullable|string|max:100',
            // Provider-specific reasoning settings
            'anthropic_thinking_budget' => 'nullable|integer|min:0|max:128000',
            'openai_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
            'openai_compatible_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
            'claude_code_thinking_tokens' => 'nullable|integer|min:0|max:128000',
            'response_level' => 'nullable|integer|min:0|max:5',
            // Legacy support - will be converted to provider-specific
            'thinking_level' => 'nullable|integer|min:0|max:4',
        ]);

        RequestFlowLogger::log('controller.stream.validated', 'Request validated', [
            'prompt_length' => strlen($validated['prompt']),
            'model' => $validated['model'] ?? null,
            'response_level' => $validated['response_level'] ?? null,
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
            RequestFlowLogger::log('controller.stream.updates', 'Applying conversation updates', [
                'updates' => $updates,
            ]);
            $conversation->update($updates);

            // If model changed, update cached context window size
            if (isset($updates['model'])) {
                $provider = $this->providerFactory->make($conversation->provider_type);
                try {
                    $contextWindow = $provider->getContextWindow($updates['model']);
                    $conversation->updateContextWindowSize($contextWindow);
                    RequestFlowLogger::log('controller.stream.context_window_updated', 'Context window updated for new model', [
                        'model' => $updates['model'],
                        'context_window' => $contextWindow,
                    ]);
                } catch (\Exception $e) {
                    // Model not found or config error - will be updated when we get actual token data
                    RequestFlowLogger::logError('controller.stream.context_window_error', 'Failed to update context window', $e);
                    \Log::debug('ConversationController: Failed to update context window on model change', [
                        'conversation' => $conversation->uuid,
                        'model' => $updates['model'],
                        'provider' => $conversation->provider_type,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $provider = $this->providerFactory->make($conversation->provider_type);
        $providerAvailable = $provider->isAvailable();
        RequestFlowLogger::logDecision('controller.stream.provider_check', 'Provider available', $providerAvailable, [
            'provider_type' => $conversation->provider_type,
        ]);

        if (!$providerAvailable) {
            RequestFlowLogger::log('controller.stream.provider_unavailable', 'Provider not available - returning 400');
            RequestFlowLogger::endRequest('error_provider_unavailable');
            return response()->json([
                'success' => false,
                'error' => "Provider '{$conversation->provider_type}' is not available. Check API key configuration.",
            ], 400);
        }

        // Check if already streaming
        $isAlreadyStreaming = $this->streamManager->isStreaming($conversation->uuid);
        RequestFlowLogger::logDecision('controller.stream.already_streaming_check', 'Already streaming', $isAlreadyStreaming);

        if ($isAlreadyStreaming) {
            RequestFlowLogger::log('controller.stream.already_streaming', 'Already streaming - returning 409');
            RequestFlowLogger::endRequest('error_already_streaming');
            return response()->json([
                'success' => false,
                'error' => 'Conversation is already streaming',
            ], 409);
        }

        // Initialize stream state BEFORE cleanup to prevent race condition
        // This ensures clients won't see 'not_found' after cleanup and before job starts
        RequestFlowLogger::log('controller.stream.initializing', 'Initializing stream state in Redis');
        $this->streamManager->startStream($conversation->uuid, [
            'model' => $conversation->model,
            'provider' => $conversation->provider_type,
        ]);
        // Note: startStream() already clears old events inside its MULTI/EXEC transaction
        RequestFlowLogger::log('controller.stream.redis_initialized', 'Stream state initialized');

        // Dispatch background job - reasoning settings are now stored on conversation
        RequestFlowLogger::log('controller.stream.dispatching_job', 'Dispatching ProcessConversationStream job');
        ProcessConversationStream::dispatch(
            $conversation->uuid,
            $validated['prompt'],
            [] // Options no longer needed for reasoning - stored on conversation
        );
        RequestFlowLogger::log('controller.stream.job_dispatched', 'Job dispatched to queue');

        RequestFlowLogger::log('controller.stream.success', 'Returning success response');
        RequestFlowLogger::endRequest('success');
        return response()->json([
            'success' => true,
            'conversation_uuid' => $conversation->uuid,
            'started_at' => now()->toIso8601String(),
            'message' => 'Streaming started. Connect to /stream-events to receive updates.',
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            RequestFlowLogger::log('controller.stream.validation_failed', 'Validation failed', [
                'errors' => $e->errors(),
            ]);
            RequestFlowLogger::endRequest('validation_error');
            throw $e; // Let Laravel handle the validation response
        } catch (\Throwable $e) {
            RequestFlowLogger::logError('controller.stream.exception', 'Stream request failed', $e);
            RequestFlowLogger::endRequest('error');
            throw $e;
        }
    }

    /**
     * Get stream status for a conversation.
     *
     * Returns stream status along with event count and last event ID
     * for verifying reconnection continuity.
     */
    public function streamStatus(Conversation $conversation): JsonResponse
    {
        $status = $this->streamManager->getStatus($conversation->uuid);
        $metadata = $this->streamManager->getMetadata($conversation->uuid);
        $eventCount = $this->streamManager->getEventCount($conversation->uuid);
        $lastEvent = $this->streamManager->getLastEvent($conversation->uuid);

        return response()->json([
            'conversation_uuid' => $conversation->uuid,
            'stream_status' => $status,  // 'streaming', 'completed', 'failed', or null
            'is_streaming' => $status === 'streaming',
            'event_count' => $eventCount,
            'last_event_id' => $lastEvent['event_id'] ?? null,  // For reconnection verification
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
        RequestFlowLogger::startRequest($conversation->uuid, 'sse');
        RequestFlowLogger::log('controller.sse.entry', 'SSE connection started', [
            'from_index' => $fromIndex,
        ]);

        return response()->stream(function () use ($conversation, $fromIndex) {
            try {
                $sse = new SseWriter();

                // Send all buffered events first
                $events = $this->streamManager->getEvents($conversation->uuid, $fromIndex);
                $currentIndex = $fromIndex;
                $bufferedCount = count($events);

                RequestFlowLogger::log('controller.sse.buffered_events', 'Retrieved buffered events', [
                    'count' => $bufferedCount,
                    'from_index' => $fromIndex,
                ]);

                foreach ($events as $event) {
                    $sse->writeRaw(json_encode(array_merge($event, [
                        'index' => $currentIndex,
                        'conversation_uuid' => $conversation->uuid,
                    ])));
                    $currentIndex++;
                }

                // Check if stream is still active
                $status = $this->streamManager->getStatus($conversation->uuid);
                RequestFlowLogger::logDecision('controller.sse.initial_status_check', 'Stream still active', $status === 'streaming', [
                    'status' => $status,
                ]);

                if ($status !== 'streaming') {
                    // Re-fetch any tail events that arrived between getEvents() and getStatus()
                    // This closes the race window where the job writes final events AND completes
                    // between our two Redis calls.
                    $tailEvents = $this->streamManager->getEvents($conversation->uuid, $currentIndex);
                    foreach ($tailEvents as $event) {
                        $sse->writeRaw(json_encode(array_merge($event, [
                            'index' => $currentIndex,
                            'conversation_uuid' => $conversation->uuid,
                        ])));
                        $currentIndex++;
                    }

                    // Stream is done, send final status
                    RequestFlowLogger::log('controller.sse.stream_already_done', 'Stream already completed, sending final status', [
                        'status' => $status,
                        'tail_events' => count($tailEvents),
                    ]);
                    $sse->writeRaw(json_encode([
                        'type' => 'stream_status',
                        'status' => $status ?? 'not_found',
                        'final_index' => $currentIndex - 1,
                        'conversation_uuid' => $conversation->uuid,
                    ]));
                    RequestFlowLogger::endRequest('stream_already_done');
                    return;
                }

                // Subscribe to live updates
                // Use polling instead of blocking pub/sub for better compatibility
                $lastActivity = microtime(true);
                $lastKeepalive = microtime(true);
                $timeout = 300; // 5 minute timeout for SSE connection (tool calls can be slow)
                $keepaliveInterval = 30; // Send keepalive every 30 seconds to prevent proxy timeouts
                $pollCount = 0;

                RequestFlowLogger::log('controller.sse.entering_poll_loop', 'Entering live polling loop', [
                    'timeout_seconds' => $timeout,
                    'keepalive_interval' => $keepaliveInterval,
                ]);

                while (true) {
                    $pollCount++;

                    // Exit if client disconnected
                    if (connection_aborted()) {
                        RequestFlowLogger::log('controller.sse.client_disconnected', 'Client disconnected', [
                            'poll_count' => $pollCount,
                            'current_index' => $currentIndex,
                        ]);
                        RequestFlowLogger::endRequest('client_disconnected');
                        break;
                    }

                    // Check for new events
                    $newEvents = $this->streamManager->getEvents($conversation->uuid, $currentIndex);
                    $newEventCount = count($newEvents);

                    // Only log when there are new events (to avoid log spam)
                    if ($newEventCount > 0) {
                        RequestFlowLogger::log('controller.sse.new_events', 'New events received', [
                            'count' => $newEventCount,
                            'current_index' => $currentIndex,
                        ]);
                    }

                    foreach ($newEvents as $event) {
                        $sse->writeRaw(json_encode(array_merge($event, [
                            'index' => $currentIndex,
                            'conversation_uuid' => $conversation->uuid,
                        ])));
                        $currentIndex++;
                        $lastActivity = microtime(true); // Update activity time when data is sent
                    }

                    // Check stream status
                    $status = $this->streamManager->getStatus($conversation->uuid);
                    if ($status !== 'streaming') {
                        // Re-fetch any tail events that arrived between getEvents() and getStatus()
                        // This closes the race window where the job writes final events AND completes
                        // between our two Redis calls.
                        $tailEvents = $this->streamManager->getEvents($conversation->uuid, $currentIndex);
                        foreach ($tailEvents as $event) {
                            $sse->writeRaw(json_encode(array_merge($event, [
                                'index' => $currentIndex,
                                'conversation_uuid' => $conversation->uuid,
                            ])));
                            $currentIndex++;
                        }

                        RequestFlowLogger::log('controller.sse.stream_completed', 'Stream completed, sending final status', [
                            'status' => $status,
                            'final_index' => $currentIndex - 1,
                            'poll_count' => $pollCount,
                            'tail_events' => count($tailEvents),
                        ]);
                        $sse->writeRaw(json_encode([
                            'type' => 'stream_status',
                            'status' => $status,
                            'final_index' => $currentIndex - 1,
                            'conversation_uuid' => $conversation->uuid,
                        ]));
                        RequestFlowLogger::endRequest('stream_completed');
                        break;
                    }

                    // Send keepalive to prevent proxy/idle timeouts
                    $timeSinceKeepalive = microtime(true) - $lastKeepalive;
                    if ($timeSinceKeepalive > $keepaliveInterval) {
                        $sse->writeKeepalive();
                        $lastKeepalive = microtime(true);
                    }

                    // Check timeout
                    $timeSinceActivity = microtime(true) - $lastActivity;
                    if ($timeSinceActivity > $timeout) {
                        RequestFlowLogger::log('controller.sse.timeout', 'SSE connection timeout', [
                            'timeout_seconds' => $timeout,
                            'time_since_activity' => round($timeSinceActivity, 2),
                            'poll_count' => $pollCount,
                        ]);
                        $sse->writeRaw(json_encode([
                            'type' => 'timeout',
                            'message' => 'Connection timeout, please reconnect',
                            'last_index' => $currentIndex - 1,
                            'conversation_uuid' => $conversation->uuid,
                        ]));
                        RequestFlowLogger::endRequest('timeout');
                        break;
                    }

                    // Small delay to prevent CPU spinning
                    usleep(100000); // 100ms
                }
            } catch (\Throwable $e) {
                RequestFlowLogger::logError('controller.sse.exception', 'SSE stream exception', $e);
                RequestFlowLogger::endRequest('error');
                throw $e;
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
     * Update the conversation title and/or tab label.
     */
    public function updateTitle(Request $request, Conversation $conversation): JsonResponse
    {
        // Trim before validation to handle whitespace-only input
        $request->merge([
            'title' => is_string($request->input('title')) ? trim($request->input('title')) : $request->input('title'),
            'tab_label' => is_string($request->input('tab_label')) ? trim($request->input('tab_label')) : $request->input('tab_label'),
        ]);

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:50',
                function (string $_attribute, mixed $value, \Closure $fail) {
                    if ((string) $value === '') {
                        $fail('Title cannot be empty.');
                    }
                },
            ],
            'tab_label' => 'nullable|string|max:6',
        ]);

        $updates = ['title' => $validated['title']];

        // Only update tab_label if provided in the request (allow setting to null/empty)
        if ($request->has('tab_label')) {
            $updates['tab_label'] = $validated['tab_label'] ?: null;
        }

        $conversation->update($updates);

        return response()->json([
            'success' => true,
            'title' => $conversation->title,
            'tab_label' => $conversation->tab_label,
        ]);
    }

    /**
     * Abort an active stream.
     *
     * Sets the abort flag which the job will check and terminate gracefully.
     */
    public function abort(Request $request, Conversation $conversation): JsonResponse
    {
        RequestFlowLogger::startRequest($conversation->uuid, 'abort');
        RequestFlowLogger::log('controller.abort.entry', 'Abort request received');

        // Only allow aborting if actually streaming
        $isStreaming = $this->streamManager->isStreaming($conversation->uuid);
        RequestFlowLogger::logDecision('controller.abort.streaming_check', 'Is streaming', $isStreaming);

        if (!$isStreaming) {
            RequestFlowLogger::log('controller.abort.not_streaming', 'Not streaming - returning 400');
            RequestFlowLogger::endRequest('error_not_streaming');
            return response()->json([
                'success' => false,
                'message' => 'Conversation is not currently streaming',
            ], 400);
        }

        // skipSync=true means the abort happened after tool execution completed,
        // so CLI providers already have complete data in their session file.
        // We should not sync partial data that might create duplicates.
        $skipSync = (bool) $request->input('skipSync', false);

        // Set abort flag - the job will pick this up
        RequestFlowLogger::log('controller.abort.setting_flag', 'Setting abort flag', [
            'skip_sync' => $skipSync,
        ]);
        $this->streamManager->setAbortFlag($conversation->uuid, $skipSync);

        RequestFlowLogger::log('controller.abort.success', 'Abort flag set successfully');
        RequestFlowLogger::endRequest('success');
        return response()->json([
            'success' => true,
            'message' => 'Abort signal sent',
        ]);
    }

    /**
     * Switch the agent for a conversation mid-conversation.
     *
     * Validates that the new agent uses the same provider as the current conversation.
     * Optionally syncs agent settings (model, reasoning config) to the conversation.
     */
    public function switchAgent(Request $request, Conversation $conversation): JsonResponse
    {
        \Log::info('[switchAgent] Called', [
            'conversation_uuid' => $conversation->uuid,
            'current_agent_id' => $conversation->agent_id,
            'request_data' => $request->all(),
        ]);

        $validated = $request->validate([
            'agent_id' => 'required|uuid|exists:agents,id',
            'sync_settings' => 'nullable|boolean',
        ]);

        // Block if currently streaming
        if ($this->streamManager->isStreaming($conversation->uuid)) {
            return response()->json([
                'error' => 'Cannot switch agent while conversation is streaming',
            ], 409);
        }

        $newAgent = Agent::find($validated['agent_id']);

        if (!$newAgent) {
            return response()->json([
                'error' => 'Agent not found',
            ], 404);
        }

        if (!$newAgent->enabled) {
            return response()->json([
                'error' => 'Agent is disabled',
            ], 400);
        }

        // Enforce same provider
        if ($newAgent->provider !== $conversation->provider_type) {
            return response()->json([
                'error' => 'Cannot switch to agent with different provider',
                'current_provider' => $conversation->provider_type,
                'agent_provider' => $newAgent->provider,
            ], 400);
        }

        $oldAgentId = $conversation->agent_id;
        $oldAgent = $oldAgentId ? Agent::find($oldAgentId) : null;

        // Update conversation
        $updates = ['agent_id' => $newAgent->id];

        // Optionally sync settings from new agent
        if ($request->boolean('sync_settings', false)) {
            $updates['model'] = $newAgent->model;
            $updates['response_level'] = $newAgent->response_level;
            $updates['anthropic_thinking_budget'] = $newAgent->anthropic_thinking_budget;
            $updates['openai_reasoning_effort'] = $newAgent->openai_reasoning_effort;
            $updates['openai_compatible_reasoning_effort'] = $newAgent->openai_compatible_reasoning_effort;
            $updates['claude_code_thinking_tokens'] = $newAgent->claude_code_thinking_tokens;
        }

        $conversation->update($updates);

        \Log::info('[switchAgent] Updated', [
            'conversation_uuid' => $conversation->uuid,
            'old_agent_id' => $oldAgentId,
            'new_agent_id' => $newAgent->id,
            'updates' => $updates,
        ]);

        return response()->json([
            'success' => true,
            'old_agent' => $oldAgent ? [
                'id' => $oldAgent->id,
                'name' => $oldAgent->name,
            ] : null,
            'new_agent' => [
                'id' => $newAgent->id,
                'name' => $newAgent->name,
            ],
            'settings_synced' => $request->boolean('sync_settings', false),
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

            // Include available tools for claude_code with enabled status
            if ($type === 'claude_code') {
                $nativeToolService = app(NativeToolService::class);
                $providerData['available_tools'] = $nativeToolService->getToolsForProvider('claude_code');
            }

            $providers[$type] = $providerData;
        }

        return response()->json([
            'default' => config('ai.default_provider', 'anthropic'),
            'providers' => $providers,
            'response_levels' => config('ai.response.levels'),
        ]);
    }

    /**
     * Get the stream log file path for a conversation.
     *
     * Returns the path to the per-conversation JSONL log file
     * that contains all Claude Code CLI stream data.
     */
    public function streamLogPath(Conversation $conversation): JsonResponse
    {
        $logger = app(ConversationStreamLogger::class);

        return response()->json([
            'path' => $logger->getLogPath($conversation->uuid),
            'exists' => $logger->exists($conversation->uuid),
            'size' => $logger->getSize($conversation->uuid),
        ]);
    }
}
