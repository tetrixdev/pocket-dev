<?php

namespace App\Services;

use App\Contracts\AIProviderInterface;
use App\Services\Providers\AnthropicProvider;
use App\Services\Providers\OpenAIProvider;
use InvalidArgumentException;

/**
 * Factory for creating AI provider instances.
 */
class ProviderFactory
{
    /** @var array<string, class-string<AIProviderInterface>> */
    private array $providers = [
        'anthropic' => AnthropicProvider::class,
        'openai' => OpenAIProvider::class,
    ];

    /**
     * Get a provider instance by type.
     */
    public function make(string $type): AIProviderInterface
    {
        if (!isset($this->providers[$type])) {
            throw new InvalidArgumentException("Unknown provider type: {$type}");
        }

        return app($this->providers[$type]);
    }

    /**
     * Get the default provider.
     */
    public function default(): AIProviderInterface
    {
        $type = config('ai.default_provider', 'anthropic');

        return $this->make($type);
    }

    /**
     * Get all available provider types.
     *
     * @return array<string>
     */
    public function availableTypes(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Get all available providers (only those that are configured).
     *
     * @return array<string, AIProviderInterface>
     */
    public function available(): array
    {
        $available = [];

        foreach ($this->providers as $type => $class) {
            $provider = $this->make($type);
            if ($provider->isAvailable()) {
                $available[$type] = $provider;
            }
        }

        return $available;
    }

    /**
     * Check if a provider type is supported.
     */
    public function supports(string $type): bool
    {
        return isset($this->providers[$type]);
    }
}
