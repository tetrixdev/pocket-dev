<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\ConversationStreamHandler;
use App\Services\ProviderFactory;
use App\Streaming\SseWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for the new multi-provider conversation system.
 * Uses database-backed conversations with direct API streaming.
 */
class ConversationController extends Controller
{
    public function __construct(
        private ConversationStreamHandler $streamHandler,
        private ProviderFactory $providerFactory,
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
     * Stream a message to the conversation.
     *
     * Uses SseWriter to separate frontend SSE output from provider streaming logic.
     */
    public function stream(Request $request, Conversation $conversation): StreamedResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string',
            'thinking_level' => 'nullable|integer|min:0|max:4',
            'response_level' => 'nullable|integer|min:0|max:3',
        ]);

        $provider = $this->providerFactory->make($conversation->provider_type);

        if (!$provider->isAvailable()) {
            return response()->stream(function () use ($conversation) {
                $sse = new SseWriter();
                $sse->writeError("Provider '{$conversation->provider_type}' is not available. Check API key configuration.");
            }, 200, SseWriter::headers());
        }

        $options = [
            'thinking_level' => $validated['thinking_level'] ?? 0,
            'response_level' => $validated['response_level'] ?? config('ai.response.default_level', 1),
        ];

        return response()->stream(function () use ($conversation, $validated, $provider, $options) {
            $sse = new SseWriter();

            try {
                // Stream handler yields provider-agnostic StreamEvent objects
                // SseWriter handles the SSE output to the browser
                foreach ($this->streamHandler->stream($conversation, $validated['prompt'], $provider, $options) as $event) {
                    $sse->write($event);
                }
            } catch (\Throwable $e) {
                $sse->writeError($e->getMessage());
            }
        }, 200, SseWriter::headers());
    }

    /**
     * Get conversation status.
     */
    public function status(Conversation $conversation): JsonResponse
    {
        $provider = $this->providerFactory->make($conversation->provider_type);

        return response()->json([
            'id' => $conversation->id,
            'uuid' => $conversation->uuid,
            'status' => $conversation->status,
            'is_processing' => $conversation->isProcessing(),
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
