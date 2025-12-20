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
            'hasGitCredentials' => $this->settings->hasGitCredentials(),
            'gitCredentials' => $this->settings->getGitCredentials(),
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
            ]);

            if (!empty($validated['anthropic_api_key'])) {
                $this->settings->setAnthropicApiKey($validated['anthropic_api_key']);
            }

            if (!empty($validated['openai_api_key'])) {
                $this->settings->setOpenAiApiKey($validated['openai_api_key']);
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
            match ($provider) {
                'anthropic' => $this->settings->deleteAnthropicApiKey(),
                'openai' => $this->settings->deleteOpenAiApiKey(),
                default => throw new \InvalidArgumentException("Unknown provider: {$provider}"),
            };

            return redirect()->back()->with('success', ucfirst($provider) . ' API key deleted');
        } catch (\Exception $e) {
            Log::error("Failed to delete {$provider} API key", ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to delete API key. Please try again.');
        }
    }

    /**
     * Save Git credentials
     */
    public function saveGitCredentials(Request $request)
    {
        try {
            $validated = $request->validate([
                'git_token' => 'required|string',
                'git_user_name' => 'required|string',
                'git_user_email' => 'required|email',
            ]);

            $this->settings->setGitCredentials(
                $validated['git_token'],
                $validated['git_user_name'],
                $validated['git_user_email']
            );

            return redirect()->back()->with('success', 'Git credentials saved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to save Git credentials', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to save Git credentials. Please check your input and try again.');
        }
    }

    /**
     * Delete Git credentials
     */
    public function deleteGitCredentials()
    {
        try {
            $this->settings->deleteGitCredentials();
            return redirect()->back()->with('success', 'Git credentials deleted');
        } catch (\Exception $e) {
            Log::error('Failed to delete Git credentials', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to delete Git credentials. Please try again.');
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
        ]);
    }

    /**
     * Process setup wizard
     */
    public function processSetup(Request $request)
    {
        try {
            $validated = $request->validate([
                'provider' => 'required|in:claude_code,anthropic,openai',
                'anthropic_api_key' => 'required_if:provider,anthropic|nullable|string',
                'openai_api_key' => 'required_if:provider,openai|nullable|string',
                'git_token' => 'nullable|string',
                'git_user_name' => 'nullable|string',
                'git_user_email' => 'nullable|email',
            ]);

            // Handle provider-specific setup
            if ($validated['provider'] === 'claude_code') {
                // Verify Claude Code authentication
                if (!$this->settings->isClaudeCodeAuthenticated()) {
                    return redirect()->back()
                        ->withInput($request->except(['anthropic_api_key', 'openai_api_key', 'git_token']))
                        ->with('error', 'Claude Code is not authenticated. Please run the command shown above in your terminal, complete the OAuth flow, then click "Verify & Continue".');
                }
            } elseif ($validated['provider'] === 'anthropic' && !empty($validated['anthropic_api_key'])) {
                $this->settings->setAnthropicApiKey($validated['anthropic_api_key']);
            } elseif ($validated['provider'] === 'openai' && !empty($validated['openai_api_key'])) {
                $this->settings->setOpenAiApiKey($validated['openai_api_key']);
            }

            // Save Git credentials if provided
            if (!empty($validated['git_token']) && !empty($validated['git_user_name']) && !empty($validated['git_user_email'])) {
                $this->settings->setGitCredentials(
                    $validated['git_token'],
                    $validated['git_user_name'],
                    $validated['git_user_email']
                );
            }

            // Create default agent for the selected provider (if none exists)
            $this->createDefaultAgentForProvider($validated['provider']);

            // Mark setup as complete
            $this->settings->markSetupComplete();

            return redirect('/')->with('success', 'Setup complete! Welcome to PocketDev.');
        } catch (\Exception $e) {
            Log::error('Setup wizard failed', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput($request->except(['anthropic_api_key', 'openai_api_key', 'git_token']))
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

        // Get default model from ModelRepository (first = most capable)
        $defaultModel = $this->models->getDefaultModel($provider);
        if (!$defaultModel) {
            Log::warning("Cannot create default agent for {$provider}: no models configured");
            return;
        }

        $defaultNames = [
            Agent::PROVIDER_ANTHROPIC => 'Claude Assistant',
            Agent::PROVIDER_OPENAI => 'GPT Assistant',
            Agent::PROVIDER_CLAUDE_CODE => 'Claude Code',
        ];

        $defaultDescriptions = [
            Agent::PROVIDER_ANTHROPIC => 'Default Anthropic Claude agent for general conversations.',
            Agent::PROVIDER_OPENAI => 'Default OpenAI GPT agent for general conversations.',
            Agent::PROVIDER_CLAUDE_CODE => 'Claude Code agent with full tool access for development tasks.',
        ];

        Agent::create([
            'name' => $defaultNames[$provider] ?? 'Default Agent',
            'description' => $defaultDescriptions[$provider] ?? 'Default agent for this provider.',
            'provider' => $provider,
            'model' => $defaultModel['model_id'],
            'is_default' => true,
            'enabled' => true,
            'response_level' => 1,
            'allowed_tools' => null, // All tools allowed
        ]);
    }
}
