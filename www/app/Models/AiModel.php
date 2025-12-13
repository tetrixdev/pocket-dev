<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents an AI model available in the system.
 *
 * @property int $id
 * @property string $provider
 * @property string $model_id
 * @property string $display_name
 * @property int $context_window
 * @property int|null $max_output_tokens
 * @property float $input_price_per_million
 * @property float $output_price_per_million
 * @property bool $is_active
 * @property bool $supports_streaming
 * @property bool $supports_tools
 * @property bool $supports_vision
 * @property bool $supports_extended_thinking
 * @property int $sort_order
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AiModel extends Model
{
    protected $fillable = [
        'provider',
        'model_id',
        'display_name',
        'context_window',
        'max_output_tokens',
        'input_price_per_million',
        'output_price_per_million',
        'cache_write_price_per_million',
        'cache_read_price_per_million',
        'is_active',
        'supports_streaming',
        'supports_tools',
        'supports_vision',
        'supports_extended_thinking',
        'sort_order',
    ];

    protected $casts = [
        'context_window' => 'integer',
        'max_output_tokens' => 'integer',
        'input_price_per_million' => 'decimal:4',
        'output_price_per_million' => 'decimal:4',
        'cache_write_price_per_million' => 'decimal:4',
        'cache_read_price_per_million' => 'decimal:4',
        'is_active' => 'boolean',
        'supports_streaming' => 'boolean',
        'supports_tools' => 'boolean',
        'supports_vision' => 'boolean',
        'supports_extended_thinking' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Scope to only active models.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by provider.
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope to order by display order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('display_name');
    }

    /**
     * Calculate cost for given token usage.
     */
    public function calculateCost(
        int $inputTokens,
        int $outputTokens,
        ?int $cacheCreationTokens = null,
        ?int $cacheReadTokens = null
    ): float {
        $inputCost = ($inputTokens / 1_000_000) * $this->input_price_per_million;
        $outputCost = ($outputTokens / 1_000_000) * $this->output_price_per_million;
        $cacheWriteCost = (($cacheCreationTokens ?? 0) / 1_000_000) * ($this->cache_write_price_per_million ?? 0);
        $cacheReadCost = (($cacheReadTokens ?? 0) / 1_000_000) * ($this->cache_read_price_per_million ?? 0);

        return $inputCost + $outputCost + $cacheWriteCost + $cacheReadCost;
    }

    /**
     * Get as array for API responses.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->model_id,
            'name' => $this->display_name,
            'provider' => $this->provider,
            'context_window' => $this->context_window,
            'max_output_tokens' => $this->max_output_tokens,
            'supports_streaming' => $this->supports_streaming,
            'supports_tools' => $this->supports_tools,
            'supports_vision' => $this->supports_vision,
            'supports_extended_thinking' => $this->supports_extended_thinking,
        ];
    }
}
