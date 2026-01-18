<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\AppSettingsService;
use App\Services\ModelRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CredentialsController extends Controller
{
    public function __construct(
        protected AppSettingsService $settings,
        protected ModelRepository $models
    ) {}

    /**
     * Show credentials management page
     */
    public function show(Request $request)
    {
        $request->session()->put('config_last_section', 'credentials');

        return view('config.credentials', [
            'hasAnthropicKey' => $this->settings->hasAnthropicApiKey(),
            'hasOpenAiKey' => $this->settings->hasOpenAiApiKey(),
            'hasClaudeCode' => $this->settings->isClaudeCodeAuthenticated(),
            'hasCodex' => $this->settings->isCodexAuthenticated(),
            'hasOpenAiCompatible' => $this->settings->hasOpenAiCompatibleBaseUrl(),
            'openAiCompatibleBaseUrl' => $this->settings->getOpenAiCompatibleBaseUrl(),
            'openAiCompatibleModel' => $this->settings->getOpenAiCompatibleModel(),
            'openAiCompatibleContextWindow' => $this->settings->getOpenAiCompatibleContextWindow(),
        ]);
    }

    /**
     * Save API credentials
     */
    public function saveApiKeys(Request $request)
    {
        try {
            $validated = $request->validate([
                'anthropic_api_key' => 'nullable|string',
                'openai_api_key' => 'nullable|string',
                'openai_compatible_base_url' => 'nullable|url',
                'openai_compatible_api_key' => 'nullable|string',
                'openai_compatible_model' => 'nullable|string',
                'openai_compatible_context_window' => 'nullable|integer|min:1024|max:2000000',
            ]);

            if (!empty($validated['anthropic_api_key'])) {
                $this->settings->setAnthropicApiKey($validated['anthropic_api_key']);
            }

            if (!empty($validated['openai_api_key'])) {
                $this->settings->setOpenAiApiKey($validated['openai_api_key']);
            }

            // Handle OpenAI Compatible settings
            if (!empty($validated['openai_compatible_base_url'])) {
                $this->settings->setOpenAiCompatibleBaseUrl($validated['openai_compatible_base_url']);

                if (!empty($validated['openai_compatible_api_key'])) {
                    $this->settings->setOpenAiCompatibleApiKey($validated['openai_compatible_api_key']);
                }

                if (!empty($validated['openai_compatible_model'])) {
                    $this->settings->setOpenAiCompatibleModel($validated['openai_compatible_model']);
                }

                if (!empty($validated['openai_compatible_context_window'])) {
                    $this->settings->setOpenAiCompatibleContextWindow((int) $validated['openai_compatible_context_window']);
                }
            }

            return redirect()->back()->with('success', 'API keys saved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to save API keys', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to save API keys. Please check your input and try again.');
        }
    }

    /**
     * Delete an API key
     */
    public function deleteApiKey(string $provider)
    {
        try {
            $displayName = match ($provider) {
                'anthropic' => 'Anthropic',
                'openai' => 'OpenAI',
                'openai_compatible' => 'OpenAI Compatible',
                default => ucfirst($provider),
            };

            match ($provider) {
                'anthropic' => $this->settings->deleteAnthropicApiKey(),
                'openai' => $this->settings->deleteOpenAiApiKey(),
                'openai_compatible' => $this->settings->deleteOpenAiCompatibleSettings(),
                default => throw new \InvalidArgumentException("Unknown provider: {$provider}"),
            };

            return redirect()->back()->with('success', $displayName . ' configuration deleted');
        } catch (\Exception $e) {
            Log::error("Failed to delete {$provider} API key", ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to delete configuration. Please try again.');
        }
    }

    /**
     * Show setup wizard (first-run)
     */
    public function showSetup()
    {
        // If setup is already complete, redirect to home
        if ($this->settings->isSetupComplete()) {
            return redirect('/');
        }

        return view('setup.wizard', [
            'hasAnthropicKey' => $this->settings->hasAnthropicApiKey(),
            'hasOpenAiKey' => $this->settings->hasOpenAiApiKey(),
            'hasClaudeCode' => $this->settings->isClaudeCodeAuthenticated(),
            'hasCodex' => $this->settings->isCodexAuthenticated(),
            'hasOpenAiCompatible' => $this->settings->hasOpenAiCompatibleBaseUrl(),
        ]);
    }

    /**
     * Process setup wizard
     */
    public function processSetup(Request $request)
    {
        try {
            $validated = $request->validate([
                'provider' => 'required|in:claude_code,codex,anthropic,openai,openai_compatible',
                'anthropic_api_key' => 'required_if:provider,anthropic|nullable|string',
                'openai_api_key' => 'required_if:provider,openai|nullable|string',
                'openai_compatible_base_url' => 'required_if:provider,openai_compatible|nullable|url',
                'openai_compatible_api_key' => 'nullable|string',
                'openai_compatible_model' => 'nullable|string',
                'openai_compatible_context_window' => 'nullable|integer|min:1024|max:2000000',
            ]);

            // Handle provider-specific setup
            if ($validated['provider'] === 'claude_code') {
                // Verify Claude Code authentication
                if (!$this->settings->isClaudeCodeAuthenticated()) {
                    return redirect()->back()
                        ->withInput($request->except(['anthropic_api_key', 'openai_api_key', 'openai_compatible_api_key', 'git_token']))
                        ->with('error', 'Claude Code is not authenticated. Please run the command shown above in your terminal, complete the OAuth flow, then click "Verify & Continue".');
                }

                // Fix permissions on Claude config files so PHP-FPM can read/write them
                // Claude CLI creates these with 600, we need 660 for group write access
                $exitCode = 0;
                exec('docker exec pocket-dev-queue chmod 660 /home/appuser/.claude.json /home/appuser/.claude.json.backup 2>/dev/null', $output, $exitCode);
                if ($exitCode !== 0) {
                    Log::warning('Claude config chmod failed', ['files' => '.claude.json', 'exit_code' => $exitCode]);
                }
                exec('docker exec pocket-dev-queue chmod 664 /home/appuser/.claude/settings.json 2>/dev/null', $output, $exitCode);
                if ($exitCode !== 0) {
                    Log::warning('Claude config chmod failed', ['file' => 'settings.json', 'exit_code' => $exitCode]);
                }
            } elseif ($validated['provider'] === 'codex') {
                // Verify Codex authentication
                if (!$this->settings->isCodexAuthenticated()) {
                    return redirect()->back()
                        ->withInput($request->except(['anthropic_api_key', 'openai_api_key', 'openai_compatible_api_key', 'git_token']))
                        ->with('error', 'Codex is not authenticated. Please run the command shown above in your terminal, complete the device auth flow, then click "Verify & Continue".');
                }
            } elseif ($validated['provider'] === 'anthropic' && !empty($validated['anthropic_api_key'])) {
                $this->settings->setAnthropicApiKey($validated['anthropic_api_key']);
            } elseif ($validated['provider'] === 'openai' && !empty($validated['openai_api_key'])) {
                $this->settings->setOpenAiApiKey($validated['openai_api_key']);
            } elseif ($validated['provider'] === 'openai_compatible' && !empty($validated['openai_compatible_base_url'])) {
                $this->settings->setOpenAiCompatibleBaseUrl($validated['openai_compatible_base_url']);

                if (!empty($validated['openai_compatible_api_key'])) {
                    $this->settings->setOpenAiCompatibleApiKey($validated['openai_compatible_api_key']);
                }

                if (!empty($validated['openai_compatible_model'])) {
                    $this->settings->setOpenAiCompatibleModel($validated['openai_compatible_model']);
                }

                if (!empty($validated['openai_compatible_context_window'])) {
                    $this->settings->setOpenAiCompatibleContextWindow((int) $validated['openai_compatible_context_window']);
                }
            }

            // Create default agent for the selected provider (if none exists)
            $this->createDefaultAgentForProvider($validated['provider']);

            // Mark setup as complete
            $this->settings->markSetupComplete();

            return redirect('/')->with('success', 'Setup complete! Welcome to PocketDev.');
        } catch (\Exception $e) {
            Log::error('Setup wizard failed', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput($request->except(['anthropic_api_key', 'openai_api_key', 'openai_compatible_api_key', 'git_token']))
                ->with('error', 'Setup failed. Please check your input and try again.');
        }
    }

    /**
     * Create a default agent for the given provider.
     */
    protected function createDefaultAgentForProvider(string $provider): void
    {
        // Skip if a default agent already exists for this provider
        if (Agent::enabled()->defaultFor($provider)->exists()) {
            return;
        }

        // Get model ID for the provider
        // Special case: openai_compatible stores model in AppSettingsService, not config
        if ($provider === 'openai_compatible') {
            $modelId = $this->settings->getOpenAiCompatibleModel();
            if (!$modelId) {
                Log::warning("Cannot create default agent for {$provider}: no model configured");
                return;
            }
        } else {
            // Get default model from ModelRepository (first = most capable)
            $defaultModel = $this->models->getDefaultModel($provider);
            if (!$defaultModel) {
                Log::warning("Cannot create default agent for {$provider}: no models configured");
                return;
            }
            $modelId = $defaultModel['model_id'];
        }

        $defaultNames = [
            Agent::PROVIDER_ANTHROPIC => 'Claude Assistant',
            Agent::PROVIDER_OPENAI => 'GPT Assistant',
            Agent::PROVIDER_CLAUDE_CODE => 'Claude Code',
            'codex' => 'Codex',
            'openai_compatible' => 'Custom AI Assistant',
        ];

        $defaultDescriptions = [
            Agent::PROVIDER_ANTHROPIC => 'Default Anthropic Claude agent for general conversations.',
            Agent::PROVIDER_OPENAI => 'Default OpenAI GPT agent for general conversations.',
            Agent::PROVIDER_CLAUDE_CODE => 'Claude Code agent with full tool access for development tasks.',
            'codex' => 'Codex agent with full tool access for development tasks.',
            'openai_compatible' => 'Default agent using OpenAI-compatible API endpoint.',
        ];

        Agent::create([
            'name' => $defaultNames[$provider] ?? 'Default Agent',
            'description' => $defaultDescriptions[$provider] ?? 'Default agent for this provider.',
            'provider' => $provider,
            'model' => $modelId,
            'is_default' => true,
            'enabled' => true,
            'response_level' => 1,
            'allowed_tools' => null, // All tools allowed
        ]);
    }
}
