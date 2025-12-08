<?php

namespace Database\Seeders;

use App\Models\AiModel;
use Illuminate\Database\Seeder;

class AiModelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $models = [
            // =====================
            // Anthropic Claude 4.5 (Latest Generation)
            // Source: https://platform.claude.com/docs/en/about-claude/models
            // =====================
            // Cache pricing: write = 1.25x input, read = 0.1x input
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-sonnet-4-5-20250929',
                'display_name' => 'Claude Sonnet 4.5',
                'context_window' => 200000,
                'max_output_tokens' => 64000,
                'input_price_per_million' => 3.00,
                'output_price_per_million' => 15.00,
                'cache_write_price_per_million' => 3.75,
                'cache_read_price_per_million' => 0.30,
                'is_active' => true,
                'supports_streaming' => true,
                'supports_tools' => true,
                'supports_vision' => true,
                'supports_extended_thinking' => true,
                'sort_order' => 10,
            ],
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-haiku-4-5-20251001',
                'display_name' => 'Claude Haiku 4.5',
                'context_window' => 200000,
                'max_output_tokens' => 64000,
                'input_price_per_million' => 1.00,
                'output_price_per_million' => 5.00,
                'cache_write_price_per_million' => 1.25,
                'cache_read_price_per_million' => 0.10,
                'is_active' => true,
                'supports_streaming' => true,
                'supports_tools' => true,
                'supports_vision' => true,
                'supports_extended_thinking' => true,
                'sort_order' => 20,
            ],
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-opus-4-5-20251101',
                'display_name' => 'Claude Opus 4.5',
                'context_window' => 200000,
                'max_output_tokens' => 64000,
                'input_price_per_million' => 5.00,
                'output_price_per_million' => 25.00,
                'cache_write_price_per_million' => 6.25,
                'cache_read_price_per_million' => 0.50,
                'is_active' => true,
                'supports_streaming' => true,
                'supports_tools' => true,
                'supports_vision' => true,
                'supports_extended_thinking' => true,
                'sort_order' => 30,
            ],

            // =====================
            // OpenAI GPT-5.1 Codex (Code-optimized models)
            // =====================
            [
                'provider' => 'openai',
                'model_id' => 'gpt-5.1-codex-mini',
                'display_name' => 'Codex 5.1 Mini',
                'context_window' => 200000,
                'max_output_tokens' => 32768,
                'input_price_per_million' => 0.25,
                'output_price_per_million' => 2.00,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => 0.025,
                'is_active' => true,
                'supports_streaming' => true,
                'supports_tools' => true,
                'supports_vision' => false,
                'supports_extended_thinking' => true,
                'sort_order' => 10,
            ],
            [
                'provider' => 'openai',
                'model_id' => 'gpt-5.1-codex-max',
                'display_name' => 'Codex 5.1 Max',
                'context_window' => 400000,
                'max_output_tokens' => 32768,
                'input_price_per_million' => 1.25,
                'output_price_per_million' => 10.00,
                'cache_write_price_per_million' => null,
                'cache_read_price_per_million' => 0.125,
                'is_active' => true,
                'supports_streaming' => true,
                'supports_tools' => true,
                'supports_vision' => false,
                'supports_extended_thinking' => true,
                'sort_order' => 20,
            ],
        ];

        foreach ($models as $model) {
            AiModel::updateOrCreate(
                ['provider' => $model['provider'], 'model_id' => $model['model_id']],
                $model
            );
        }
    }
}
