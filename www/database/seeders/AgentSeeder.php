<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Services\AppSettingsService;
use App\Services\ModelRepository;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    /**
     * Seed default agents based on configured credentials.
     */
    public function run(): void
    {
        $settings = app(AppSettingsService::class);
        $models = app(ModelRepository::class);

        // Create agents for each configured provider (using first model as default - most capable)
        $providers = [
            [
                'key' => Agent::PROVIDER_CLAUDE_CODE,
                'check' => fn() => $settings->isClaudeCodeAuthenticated(),
                'name' => 'Claude Code',
                'description' => 'Claude Code agent with full tool access for development tasks.',
            ],
            [
                'key' => Agent::PROVIDER_ANTHROPIC,
                'check' => fn() => $settings->hasAnthropicApiKey(),
                'name' => 'Claude Assistant',
                'description' => 'Default Anthropic Claude agent for general conversations.',
            ],
            [
                'key' => Agent::PROVIDER_OPENAI,
                'check' => fn() => $settings->hasOpenAiApiKey(),
                'name' => 'GPT Assistant',
                'description' => 'Default OpenAI GPT agent for general conversations.',
            ],
        ];

        foreach ($providers as $provider) {
            // Skip if credentials not configured
            if (!$provider['check']()) {
                $this->command->info("Skipping {$provider['name']}: credentials not configured");
                continue;
            }

            // Skip if agent already exists for this provider
            if (Agent::forProvider($provider['key'])->exists()) {
                $this->command->info("Skipping {$provider['name']}: agent already exists");
                continue;
            }

            // Get default model from ModelRepository (first = most capable)
            $defaultModel = $models->getDefaultModel($provider['key']);
            if (!$defaultModel) {
                $this->command->error("Skipping {$provider['name']}: no models configured");
                continue;
            }

            Agent::create([
                'name' => $provider['name'],
                'description' => $provider['description'],
                'provider' => $provider['key'],
                'model' => $defaultModel['model_id'],
                'is_default' => true,
                'enabled' => true,
                'response_level' => 1,
                'allowed_tools' => null, // All tools allowed
            ]);

            $this->command->info("Created default agent: {$provider['name']}");
        }
    }
}
