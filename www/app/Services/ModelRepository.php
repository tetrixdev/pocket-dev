<?php

namespace App\Services;

use App\Models\AiModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Repository for managing AI models across providers.
 * Single source of truth for model availability, capabilities, and pricing.
 */
class ModelRepository
{
    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_KEY_ALL = 'ai_models:all';
    private const CACHE_KEY_PROVIDER = 'ai_models:provider:';

    /**
     * Get all active models.
     *
     * @return Collection<int, AiModel>
     */
    public function all(): Collection
    {
        return Cache::remember(self::CACHE_KEY_ALL, self::CACHE_TTL, function () {
            return AiModel::active()->ordered()->get();
        });
    }

    /**
     * Get all active models for a specific provider.
     *
     * @return Collection<int, AiModel>
     */
    public function forProvider(string $provider): Collection
    {
        return Cache::remember(self::CACHE_KEY_PROVIDER . $provider, self::CACHE_TTL, function () use ($provider) {
            return AiModel::active()->forProvider($provider)->ordered()->get();
        });
    }

    /**
     * Get a specific model by provider and model_id.
     */
    public function find(string $provider, string $modelId): ?AiModel
    {
        return $this->forProvider($provider)->firstWhere('model_id', $modelId);
    }

    /**
     * Get a model by model_id (searches all providers).
     */
    public function findByModelId(string $modelId): ?AiModel
    {
        return $this->all()->firstWhere('model_id', $modelId);
    }

    /**
     * Get models as array format for API/frontend.
     *
     * @return array<string, array{name: string, context_window: int}>
     */
    public function getModelsArray(string $provider): array
    {
        return $this->forProvider($provider)
            ->mapWithKeys(fn (AiModel $model) => [
                $model->model_id => [
                    'name' => $model->display_name,
                    'context_window' => $model->context_window,
                    'max_output_tokens' => $model->max_output_tokens,
                    'supports_vision' => $model->supports_vision,
                    'supports_tools' => $model->supports_tools,
                    'supports_extended_thinking' => $model->supports_extended_thinking,
                ],
            ])
            ->toArray();
    }

    /**
     * Get models grouped by provider for frontend display.
     *
     * @return array<string, array<int, array>>
     */
    public function getGroupedForDisplay(): array
    {
        return $this->all()
            ->groupBy('provider')
            ->map(fn (Collection $models) => $models->map(fn (AiModel $m) => $m->toApiArray())->values())
            ->toArray();
    }

    /**
     * Get the context window for a model.
     */
    public function getContextWindow(string $modelId): int
    {
        $model = $this->findByModelId($modelId);

        return $model?->context_window ?? config('ai.context_windows.default', 128000);
    }

    /**
     * Calculate cost for token usage on a model.
     */
    public function calculateCost(
        string $modelId,
        int $inputTokens,
        int $outputTokens,
        ?int $cacheCreationTokens = null,
        ?int $cacheReadTokens = null
    ): ?float {
        $model = $this->findByModelId($modelId);

        return $model?->calculateCost($inputTokens, $outputTokens, $cacheCreationTokens, $cacheReadTokens);
    }

    /**
     * Get the default model for a provider.
     */
    public function getDefaultModel(string $provider): ?AiModel
    {
        return $this->forProvider($provider)->first();
    }

    /**
     * Check if a model exists and is active.
     */
    public function isValidModel(string $modelId): bool
    {
        return $this->findByModelId($modelId) !== null;
    }

    /**
     * Check if a model supports a specific capability.
     */
    public function supports(string $modelId, string $capability): bool
    {
        $model = $this->findByModelId($modelId);

        if (!$model) {
            return false;
        }

        return match ($capability) {
            'streaming' => $model->supports_streaming,
            'tools' => $model->supports_tools,
            'vision' => $model->supports_vision,
            'extended_thinking' => $model->supports_extended_thinking,
            default => false,
        };
    }

    /**
     * Get all available providers.
     *
     * @return array<string>
     */
    public function getProviders(): array
    {
        return $this->all()->pluck('provider')->unique()->values()->toArray();
    }

    /**
     * Clear the model cache.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_ALL);

        // Clear provider-specific caches
        foreach (['anthropic', 'openai'] as $provider) {
            Cache::forget(self::CACHE_KEY_PROVIDER . $provider);
        }
    }
}
