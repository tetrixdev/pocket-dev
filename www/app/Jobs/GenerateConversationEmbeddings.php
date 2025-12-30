<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationTurnEmbedding;
use App\Models\Message;
use App\Services\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Async job to generate embeddings for conversation turns.
 * Runs after ProcessConversationStream completes (lock released).
 */
class GenerateConversationEmbeddings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes max
    public int $tries = 3;     // Retry on transient failures

    private const MAX_PARAM_LENGTH = 250;
    private const PREV_ASSISTANT_LIMIT = 150;
    private const ASSISTANT_PREVIEW_LIMIT = 350;
    private const MAX_EMBED_TEXT_LENGTH = 24000; // ~8K tokens, safe for embedding API

    public function __construct(
        public Conversation $conversation
    ) {}

    public function handle(EmbeddingService $embeddingService): void
    {
        // Skip if conversation is being processed (race condition guard)
        if ($this->conversation->fresh()->status === Conversation::STATUS_PROCESSING) {
            Log::channel('embeddings')->info('GenerateConversationEmbeddings: Skipping - conversation still processing', [
                'conversation_id' => $this->conversation->id,
            ]);
            return;
        }

        // Skip if embedding service is not configured
        if (!$embeddingService->isAvailable()) {
            Log::channel('embeddings')->warning('GenerateConversationEmbeddings: Embedding service not available');
            return;
        }

        $maxTurnNumber = $this->conversation->messages()->max('turn_number') ?? -1;

        // Skip if already up to date
        if ($maxTurnNumber <= $this->conversation->last_embedded_turn_number) {
            Log::channel('embeddings')->debug('GenerateConversationEmbeddings: Already up to date', [
                'conversation_id' => $this->conversation->id,
                'max_turn' => $maxTurnNumber,
                'last_embedded' => $this->conversation->last_embedded_turn_number,
            ]);
            return;
        }

        // Get ALL turns (need previous turn for context), grouped by turn_number
        $allTurns = $this->conversation->messages()
            ->whereNotNull('turn_number')
            ->orderBy('sequence')
            ->get()
            ->groupBy('turn_number');

        // Filter to only new turns that need embedding
        $turnsToEmbed = $allTurns->filter(fn($msgs, $turnNum) => $turnNum > $this->conversation->last_embedded_turn_number);

        Log::channel('embeddings')->info('GenerateConversationEmbeddings: Processing turns', [
            'conversation_id' => $this->conversation->id,
            'turn_count' => $turnsToEmbed->count(),
        ]);

        foreach ($turnsToEmbed as $turnNumber => $messages) {
            // Get previous turn's messages for context
            $prevTurnMessages = $allTurns->get($turnNumber - 1);
            $this->embedTurn($turnNumber, $messages, $prevTurnMessages, $embeddingService);
        }

        $this->conversation->update(['last_embedded_turn_number' => $maxTurnNumber]);

        Log::channel('embeddings')->info('GenerateConversationEmbeddings: Completed', [
            'conversation_id' => $this->conversation->id,
            'turns_processed' => $turnsToEmbed->count(),
        ]);
    }

    private function embedTurn(int $turnNumber, Collection $messages, ?Collection $prevTurnMessages, EmbeddingService $embeddingService): void
    {
        // Extract previous assistant response for context
        $prevAssistantText = $prevTurnMessages ? $this->extractAssistantText($prevTurnMessages->all()) : null;
        $prevContext = $prevAssistantText ? Str::limit($prevAssistantText, self::PREV_ASSISTANT_LIMIT) : null;

        // Build text for embedding (includes previous context)
        $text = $this->buildTurnText($messages->all(), $prevContext);

        // Truncate if too long for embedding API
        if (strlen($text) > self::MAX_EMBED_TEXT_LENGTH) {
            $text = Str::limit($text, self::MAX_EMBED_TEXT_LENGTH, '...');
            Log::channel('embeddings')->info('GenerateConversationEmbeddings: Truncated long text', [
                'conversation_id' => $this->conversation->id,
                'turn_number' => $turnNumber,
                'original_length' => strlen($text),
            ]);
        }

        $hash = hash('sha256', $text);

        // Check if already embedded with same content
        $existing = ConversationTurnEmbedding::where('conversation_id', $this->conversation->id)
            ->where('turn_number', $turnNumber)
            ->where('chunk_number', 0)
            ->first();

        if ($existing && $existing->content_hash === $hash) {
            return; // Already up to date
        }

        // Delete old embeddings for this turn (in case content changed)
        ConversationTurnEmbedding::where('conversation_id', $this->conversation->id)
            ->where('turn_number', $turnNumber)
            ->delete();

        // Generate embedding
        $embedding = $embeddingService->embed($text);

        if ($embedding === null) {
            Log::channel('embeddings')->warning('GenerateConversationEmbeddings: Failed to generate embedding', [
                'conversation_id' => $this->conversation->id,
                'turn_number' => $turnNumber,
                'text_length' => strlen($text),
            ]);
            return;
        }

        // Build structured preview
        $preview = $this->buildPreview($messages->all(), $prevContext);

        // Store embedding using raw DB statement (PostgreSQL vector type)
        $vectorString = $this->formatEmbeddingForPostgres($embedding);

        DB::statement("
            INSERT INTO conversation_turn_embeddings
            (id, conversation_id, turn_number, chunk_number, embedding, content_preview, content_hash, created_at)
            VALUES (gen_random_uuid(), ?, ?, 0, ?, ?, ?, NOW())
        ", [
            $this->conversation->id,
            $turnNumber,
            $vectorString,
            $preview,
            $hash,
        ]);
    }

    /**
     * Build embeddable text from turn messages.
     * Includes: previous context, user text, assistant text, tool calls (truncated params)
     * Excludes: thinking, tool results
     */
    private function buildTurnText(array $messages, ?string $prevContext = null): string
    {
        $parts = [];

        // Include previous assistant context for semantic search
        if ($prevContext) {
            $parts[] = "[Previous context] " . $prevContext;
        }

        foreach ($messages as $message) {
            $content = $message->content;

            if (is_string($content)) {
                // Simple string content (e.g., user message)
                $parts[] = $content;
                continue;
            }

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                $type = $block['type'] ?? '';

                if ($type === 'text') {
                    $parts[] = $block['text'] ?? '';
                } elseif ($type === 'tool_use') {
                    $parts[] = $this->formatToolCall($block);
                }
                // Skip: thinking, tool_result, interrupted
            }
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Extract assistant text from messages (for previous turn context).
     */
    private function extractAssistantText(array $messages): ?string
    {
        $parts = [];

        foreach ($messages as $message) {
            if ($message->role !== 'assistant') {
                continue;
            }

            $content = $message->content;

            if (is_string($content)) {
                $parts[] = $content;
                continue;
            }

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $parts[] = $block['text'] ?? '';
                }
            }
        }

        $text = implode("\n", array_filter($parts));
        return $text ?: null;
    }

    /**
     * Build structured preview for display.
     * Format: [Previous: ...] User: ... Assistant: ...
     */
    private function buildPreview(array $messages, ?string $prevContext): string
    {
        $parts = [];

        // Previous context
        if ($prevContext) {
            $parts[] = "[Previous] ..." . $prevContext;
        }

        // Extract user and assistant text
        $userText = null;
        $assistantText = null;

        foreach ($messages as $message) {
            $content = $message->content;

            if ($message->role === 'user') {
                if (is_string($content)) {
                    $userText = $content;
                }
            } elseif ($message->role === 'assistant') {
                if (is_string($content)) {
                    $assistantText = $content;
                } elseif (is_array($content)) {
                    $textParts = [];
                    foreach ($content as $block) {
                        if (($block['type'] ?? '') === 'text') {
                            $textParts[] = $block['text'] ?? '';
                        }
                    }
                    $assistantText = implode("\n", array_filter($textParts));
                }
            }
        }

        if ($userText) {
            $parts[] = "[User] " . $userText;
        }

        if ($assistantText) {
            $parts[] = "[Assistant] " . Str::limit($assistantText, self::ASSISTANT_PREVIEW_LIMIT);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Format tool call for embedding.
     * Includes tool name and truncated parameters.
     */
    private function formatToolCall(array $block): string
    {
        $name = $block['name'] ?? 'unknown';
        $input = $block['input'] ?? [];

        if ($input instanceof \stdClass) {
            $input = (array) $input;
        }

        $params = [];
        foreach ($input as $key => $value) {
            $stringValue = is_string($value) ? $value : json_encode($value);
            $truncated = Str::limit($stringValue, self::MAX_PARAM_LENGTH, '...');
            $params[] = "{$key}:{$truncated}";
        }

        return $name . ' ' . implode(' ', $params);
    }

    /**
     * Format embedding array for PostgreSQL vector type.
     */
    private function formatEmbeddingForPostgres(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }
}
