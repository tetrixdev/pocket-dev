<?php

namespace App\Providers;

use App\Contracts\AIProviderInterface;
use App\Models\PocketTool;
use App\Services\EmbeddingService;
use App\Services\Providers\AnthropicProvider;
use App\Services\Providers\ClaudeCodeProvider;
use App\Services\Providers\CodexProvider;
use App\Services\Providers\OpenAICompatibleProvider;
use App\Services\Providers\OpenAIProvider;
use App\Services\SystemPromptBuilder;
use App\Services\SystemPromptService;
use App\Services\ToolRegistry;
use App\Tools\Tool;
use App\Tools\UserTool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

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

            // Auto-discover and register all Tool classes
            $this->discoverAndRegisterTools($registry);

            // Also register user-created tools from the database
            $this->registerUserTools($registry);

            return $registry;
        });

        // Register SystemPromptService and SystemPromptBuilder
        $this->app->singleton(SystemPromptService::class);
        $this->app->singleton(SystemPromptBuilder::class);

        // Register EmbeddingService
        $this->app->singleton(EmbeddingService::class);

        // Register providers as singletons
        $this->app->singleton(AnthropicProvider::class);
        $this->app->singleton(OpenAIProvider::class);
        $this->app->singleton(ClaudeCodeProvider::class);
        $this->app->singleton(CodexProvider::class);
        $this->app->singleton(OpenAICompatibleProvider::class);

        // Register default provider based on config
        $this->app->bind(AIProviderInterface::class, function ($app) {
            $defaultProvider = config('ai.default_provider', 'anthropic');

            return match ($defaultProvider) {
                'anthropic' => $app->make(AnthropicProvider::class),
                'openai' => $app->make(OpenAIProvider::class),
                'claude_code' => $app->make(ClaudeCodeProvider::class),
                'codex' => $app->make(CodexProvider::class),
                'openai_compatible' => $app->make(OpenAICompatibleProvider::class),
                default => $app->make(AnthropicProvider::class),
            };
        });
    }

    /**
     * Auto-discover and register all Tool classes from app/Tools directory.
     */
    private function discoverAndRegisterTools(ToolRegistry $registry): void
    {
        $toolsPath = app_path('Tools');

        if (!is_dir($toolsPath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($toolsPath)->name('*Tool.php');

        foreach ($finder as $file) {
            $className = 'App\\Tools\\' . $file->getBasename('.php');

            // Skip if class doesn't exist
            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            // Skip abstract classes, interfaces, and the base Tool class
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            // Only register classes that extend Tool
            if (!$reflection->isSubclassOf(Tool::class)) {
                continue;
            }

            // Skip UserTool as it's a wrapper class
            if ($className === UserTool::class) {
                continue;
            }

            // Instantiate and register the tool
            try {
                $tool = $reflection->newInstance();
                $registry->register($tool);
            } catch (\Throwable $e) {
                // Log error but don't fail the entire boot
                Log::warning(
                    "Failed to register tool: {$className}",
                    ['error' => $e->getMessage()]
                );
            }
        }
    }

    /**
     * Register user-created tools from the database.
     */
    private function registerUserTools(ToolRegistry $registry): void
    {
        try {
            // Only load enabled user tools
            $userTools = PocketTool::user()->enabled()->get();

            foreach ($userTools as $pocketTool) {
                $userTool = new UserTool($pocketTool);
                $registry->register($userTool);
            }
        } catch (\Throwable $e) {
            // Database might not be available during some boot scenarios
            Log::debug(
                'Could not load user tools',
                ['error' => $e->getMessage()]
            );
        }
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
