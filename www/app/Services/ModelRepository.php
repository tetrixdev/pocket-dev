<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Repository for managing AI models across providers.
 * Single source of truth for model availability, capabilities, and pricing.
 *
 * Models are defined in config/ai.php - no database or caching required.
 */
class ModelRepository
{
    /**
     * Get all active models from all providers.
     *
     * @return Collection<int, array>
     */
    public function all(): Collection
    {
        $models = collect();

        foreach (config('ai.models', []) as $provider => $providerModels) {
            foreach ($providerModels as $model) {
                $models->push(array_merge($model, ['provider' => $provider]));
            }
        }

        return $models;
    }

    /**
     * Get all models for a specific provider.
     *
     * @return Collection<int, array>
     */
    public function forProvider(string $provider): Collection
    {
        $providerModels = config("ai.models.{$provider}", []);

        return collect($providerModels)->map(fn (array $model) => array_merge($model, ['provider' => $provider]));
    }

    /**
     * Get a specific model by provider and model_id.
     */
    public function find(string $provider, string $modelId): ?array
    {
        return $this->forProvider($provider)->firstWhere('model_id', $modelId);
    }

    /**
     * Get a model by model_id (searches all providers).
     */
    public function findByModelId(string $modelId): ?array
    {
        return $this->all()->firstWhere('model_id', $modelId);
    }

    /**
     * Get models as array format for API/frontend.
     * Keyed by model_id for easy lookup.
     *
     * @return array<string, array{name: string, context_window: int, max_output_tokens: int}>
     */
    public function getModelsArray(string $provider): array
    {
        return $this->forProvider($provider)
            ->mapWithKeys(fn (array $model) => [
                $model['model_id'] => [
                    'name' => $model['display_name'],
                    'context_window' => $model['context_window'],
                    'max_output_tokens' => $model['max_output_tokens'],
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
            ->map(fn (Collection $models) => $models->map(fn (array $m) => $this->toApiArray($m))->values())
            ->toArray();
    }

    /**
     * Convert a model to API array format.
     */
    private function toApiArray(array $model): array
    {
        return [
            'model_id' => $model['model_id'],
            'display_name' => $model['display_name'],
            'provider' => $model['provider'],
            'context_window' => $model['context_window'],
            'max_output_tokens' => $model['max_output_tokens'],
            'input_price_per_million' => $model['input_price_per_million'],
            'output_price_per_million' => $model['output_price_per_million'],
            'cache_write_price_per_million' => $model['cache_write_price_per_million'],
            'cache_read_price_per_million' => $model['cache_read_price_per_million'],
        ];
    }

    /**
     * Get the context window for a model.
     */
    public function getContextWindow(string $modelId): int
    {
        $model = $this->findByModelId($modelId);

        if (!$model) {
            throw new \InvalidArgumentException("Model not found: {$modelId}");
        }

        return $model['context_window'];
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

        if (!$model) {
            return null;
        }

        $inputCost = ($inputTokens / 1_000_000) * $model['input_price_per_million'];
        $outputCost = ($outputTokens / 1_000_000) * $model['output_price_per_million'];

        $cacheWriteCost = 0;
        if ($cacheCreationTokens && $model['cache_write_price_per_million']) {
            $cacheWriteCost = ($cacheCreationTokens / 1_000_000) * $model['cache_write_price_per_million'];
        }

        $cacheReadCost = 0;
        if ($cacheReadTokens && $model['cache_read_price_per_million']) {
            $cacheReadCost = ($cacheReadTokens / 1_000_000) * $model['cache_read_price_per_million'];
        }

        return $inputCost + $outputCost + $cacheWriteCost + $cacheReadCost;
    }

    /**
     * Get the default model for a provider (first in list).
     */
    public function getDefaultModel(string $provider): ?array
    {
        return $this->forProvider($provider)->first();
    }

    /**
     * Check if a model exists.
     */
    public function isValidModel(string $modelId): bool
    {
        return $this->findByModelId($modelId) !== null;
    }

    /**
     * Get all available providers.
     *
     * @return array<string>
     */
    public function getProviders(): array
    {
        return array_keys(config('ai.models', []));
    }

    /**
     * Get pricing data for a model.
     */
    public function getPricing(string $modelId): ?array
    {
        $model = $this->findByModelId($modelId);

        if (!$model) {
            return null;
        }

        return [
            'name' => $model['display_name'],
            'input' => (float) $model['input_price_per_million'],
            'output' => (float) $model['output_price_per_million'],
            'cacheWrite' => (float) ($model['cache_write_price_per_million'] ?? 0),
            'cacheRead' => (float) ($model['cache_read_price_per_million'] ?? 0),
        ];
    }

    /**
     * Get all pricing data grouped by provider.
     */
    public function getAllPricing(): array
    {
        return $this->all()
            ->groupBy('provider')
            ->map(function (Collection $models) {
                return $models->mapWithKeys(fn (array $model) => [
                    $model['model_id'] => [
                        'name' => $model['display_name'],
                        'input' => (float) $model['input_price_per_million'],
                        'output' => (float) $model['output_price_per_million'],
                        'cacheWrite' => (float) ($model['cache_write_price_per_million'] ?? 0),
                        'cacheRead' => (float) ($model['cache_read_price_per_million'] ?? 0),
                    ],
                ]);
            })
            ->toArray();
    }
}
