<?php

namespace App\Contracts;

use App\Models\Conversation;
use App\Models\Message;
use Generator;

interface AIProviderInterface
{
    /**
     * Get the provider identifier (e.g., 'anthropic', 'openai').
     */
    public function getProviderType(): string;

    /**
     * Check if the provider is configured and available.
     */
    public function isAvailable(): bool;

    /**
     * Get available models for this provider.
     *
     * @return array<string, array{name: string, context_window: int}>
     */
    public function getModels(): array;

    /**
     * Get context window size for a specific model.
     */
    public function getContextWindow(string $model): int;

    /**
     * Stream a message and yield StreamEvent objects.
     *
     * The provider is responsible for:
     * - Building messages from the conversation (all messages should already be saved)
     * - Making the API call with streaming enabled
     * - Converting provider-specific events to StreamEvent
     * - Yielding events as they arrive
     *
     * @param Conversation $conversation The conversation context (messages should be saved first)
     * @param array $options Additional options (tools, thinking level, etc.)
     * @return Generator<StreamEvent>
     */
    public function streamMessage(
        Conversation $conversation,
        array $options = []
    ): Generator;

    /**
     * Build the messages array for the API request.
     * Reads from the conversation's stored messages.
     *
     * @return array Provider-specific message format
     */
    public function buildMessagesFromConversation(Conversation $conversation): array;

    /**
     * Abort the current streaming operation.
     *
     * Called when the user requests to stop the stream.
     * Implementation should:
     * - Close any active HTTP streams
     * - Terminate any running CLI processes
     * - Clean up resources
     */
    public function abort(): void;

    /**
     * Sync an aborted message to the provider's native storage.
     *
     * For CLI providers (Claude Code, Codex), this writes the completed
     * message blocks to the session file so the next turn has full context.
     *
     * For API providers, this is a no-op since they don't have local storage.
     *
     * @param Conversation $conversation The conversation with session info
     * @param Message $userMessage The user message that triggered the response
     * @param Message $assistantMessage The assistant message with completed blocks
     * @return bool True if sync was successful (or not needed)
     */
    public function syncAbortedMessage(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage
    ): bool;
}
