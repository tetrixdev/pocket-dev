<?php

namespace App\Services\Providers;

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ModelRepository;
use App\Services\Providers\Traits\InjectsInterruptionReminder;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for API-based providers (Anthropic, OpenAI, OpenAI Compatible).
 *
 * Handles:
 * - Common isAvailable() pattern (check for API key)
 * - HTTP/SSE streaming lifecycle
 * - InjectsInterruptionReminder trait
 * - executesToolsInternally() = false
 * - getSystemPromptType() = 'api'
 */
abstract class AbstractApiProvider implements AIProviderInterface
{
    use InjectsInterruptionReminder;

    protected ModelRepository $models;

    public function __construct(ModelRepository $models)
    {
        $this->models = $models;
    }

    public function executesToolsInternally(): bool
    {
        return false;
    }

    public function getSystemPromptType(): string
    {
        return 'api';
    }

    public function getModels(): array
    {
        return $this->models->getModelsArray($this->getProviderType());
    }

    public function getContextWindow(string $model): int
    {
        return $this->models->getContextWindow($model);
    }

    /**
     * API providers don't have native sessions, so sync is a no-op.
     */
    public function syncAbortedMessage(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage
    ): bool {
        return true;
    }
}
