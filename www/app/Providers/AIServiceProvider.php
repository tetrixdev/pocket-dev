<?php

namespace App\Providers;

use App\Contracts\AIProviderInterface;
use App\Services\Providers\AnthropicProvider;
use App\Services\Providers\ClaudeCodeProvider;
use App\Services\Providers\OpenAICompatibleProvider;
use App\Services\Providers\OpenAIProvider;
use App\Services\SystemPromptBuilder;
use App\Services\SystemPromptService;
use App\Services\ToolRegistry;
use App\Tools\BashTool;
use App\Tools\EditTool;
use App\Tools\GlobTool;
use App\Tools\GrepTool;
use App\Tools\ReadTool;
use App\Tools\WriteTool;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register ToolRegistry as singleton
        $this->app->singleton(ToolRegistry::class, function () {
            $registry = new ToolRegistry();

            // Register enabled tools
            $enabledTools = config('ai.tools.enabled', []);

            $toolClasses = [
                'Read' => ReadTool::class,
                'Edit' => EditTool::class,
                'Write' => WriteTool::class,
                'Bash' => BashTool::class,
                'Grep' => GrepTool::class,
                'Glob' => GlobTool::class,
            ];

            foreach ($enabledTools as $toolName) {
                if (isset($toolClasses[$toolName])) {
                    $registry->register(new $toolClasses[$toolName]());
                }
            }

            return $registry;
        });

        // Register SystemPromptService and SystemPromptBuilder
        $this->app->singleton(SystemPromptService::class);
        $this->app->singleton(SystemPromptBuilder::class);

        // Register providers as singletons
        $this->app->singleton(AnthropicProvider::class);
        $this->app->singleton(OpenAIProvider::class);
        $this->app->singleton(ClaudeCodeProvider::class);
        $this->app->singleton(OpenAICompatibleProvider::class);

        // Register default provider based on config
        $this->app->bind(AIProviderInterface::class, function ($app) {
            $defaultProvider = config('ai.default_provider', 'anthropic');

            return match ($defaultProvider) {
                'anthropic' => $app->make(AnthropicProvider::class),
                'openai' => $app->make(OpenAIProvider::class),
                'claude_code' => $app->make(ClaudeCodeProvider::class),
                'openai_compatible' => $app->make(OpenAICompatibleProvider::class),
                default => $app->make(AnthropicProvider::class),
            };
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Ensure streams directory exists
        $streamPath = config('ai.streaming.temp_path');
        if ($streamPath && !is_dir($streamPath)) {
            @mkdir($streamPath, 0755, true);
        }
    }
}
