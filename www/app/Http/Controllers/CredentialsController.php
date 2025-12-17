<?php

namespace App\Http\Controllers;

use App\Services\AppSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CredentialsController extends Controller
{
    public function __construct(
        protected AppSettingsService $settings
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

            // Save API key based on provider
            if ($validated['provider'] === 'anthropic' && !empty($validated['anthropic_api_key'])) {
                $this->settings->setAnthropicApiKey($validated['anthropic_api_key']);
            } elseif ($validated['provider'] === 'openai' && !empty($validated['openai_api_key'])) {
                $this->settings->setOpenAiApiKey($validated['openai_api_key']);
            }
            // For claude_code, authentication happens via CLI

            // Save Git credentials if provided
            if (!empty($validated['git_token']) && !empty($validated['git_user_name']) && !empty($validated['git_user_email'])) {
                $this->settings->setGitCredentials(
                    $validated['git_token'],
                    $validated['git_user_name'],
                    $validated['git_user_email']
                );
            }

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
     * Skip setup wizard
     */
    public function skipSetup()
    {
        $this->settings->markSetupComplete();
        return redirect('/')->with('info', 'Setup skipped. You can configure credentials later in Settings > Credentials.');
    }
}
