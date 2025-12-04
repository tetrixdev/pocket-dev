<?php

namespace App\Contracts;

use App\Models\Conversation;
use Generator;

interface AIProviderInterface
{
    /**
     * Get the provider identifier (e.g., 'anthropic', 'openai', 'claude_code').
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
     * - Making the API call with streaming enabled
     * - Converting provider-specific events to StreamEvent
     * - Yielding events as they arrive
     *
     * @param Conversation $conversation The conversation context
     * @param string $prompt The user's message
     * @param array $options Additional options (tools, thinking level, etc.)
     * @return Generator<StreamEvent>
     */
    public function streamMessage(
        Conversation $conversation,
        string $prompt,
        array $options = []
    ): Generator;

    /**
     * Build the messages array for the API request.
     * Reads from the conversation's stored messages.
     *
     * @return array Provider-specific message format
     */
    public function buildMessagesFromConversation(Conversation $conversation): array;
}
