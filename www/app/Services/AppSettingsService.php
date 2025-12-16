<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Log;

class AppSettingsService
{
    /**
     * Get a setting value by key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $setting = AppSetting::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value by key
     *
     * @param string $key
     * @param mixed $value
     * @return AppSetting
     */
    public function set(string $key, $value): AppSetting
    {
        return AppSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Check if a setting exists
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return AppSetting::where('key', $key)->exists();
    }

    /**
     * Delete a setting by key
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        return AppSetting::where('key', $key)->delete() > 0;
    }

    /**
     * Get OpenAI API key
     *
     * @return string|null
     */
    public function getOpenAiApiKey(): ?string
    {
        return $this->get('openai_api_key');
    }

    /**
     * Set OpenAI API key
     *
     * @param string $apiKey
     * @return AppSetting
     */
    public function setOpenAiApiKey(string $apiKey): AppSetting
    {
        Log::info('OpenAI API key updated');
        return $this->set('openai_api_key', $apiKey);
    }

    /**
     * Check if OpenAI API key is configured
     *
     * @return bool
     */
    public function hasOpenAiApiKey(): bool
    {
        $key = $this->getOpenAiApiKey();
        return !empty($key);
    }

    /**
     * Delete OpenAI API key
     *
     * @return bool
     */
    public function deleteOpenAiApiKey(): bool
    {
        Log::info('OpenAI API key deleted');
        return $this->delete('openai_api_key');
    }

    /**
     * Get Anthropic API key (for Claude Code CLI)
     */
    public function getAnthropicApiKey(): ?string
    {
        return $this->get('anthropic_api_key');
    }

    /**
     * Set Anthropic API key
     */
    public function setAnthropicApiKey(string $apiKey): AppSetting
    {
        Log::info('Anthropic API key updated');
        return $this->set('anthropic_api_key', $apiKey);
    }

    /**
     * Check if Anthropic API key is configured
     */
    public function hasAnthropicApiKey(): bool
    {
        $key = $this->getAnthropicApiKey();
        return !empty($key);
    }

    /**
     * Delete Anthropic API key
     */
    public function deleteAnthropicApiKey(): bool
    {
        Log::info('Anthropic API key deleted');
        return $this->delete('anthropic_api_key');
    }

    /**
     * Get Git credentials
     */
    public function getGitCredentials(): array
    {
        return [
            'token' => $this->get('git_token'),
            'name' => $this->get('git_user_name'),
            'email' => $this->get('git_user_email'),
        ];
    }

    /**
     * Set Git credentials and configure git
     */
    public function setGitCredentials(string $token, string $name, string $email): void
    {
        $this->set('git_token', $token);
        $this->set('git_user_name', $name);
        $this->set('git_user_email', $email);

        // Write to git configuration files
        $this->writeGitConfig($token, $name, $email);

        Log::info('Git credentials updated');
    }

    /**
     * Check if Git credentials are configured
     */
    public function hasGitCredentials(): bool
    {
        $creds = $this->getGitCredentials();
        return !empty($creds['token']) && !empty($creds['name']) && !empty($creds['email']);
    }

    /**
     * Delete Git credentials
     */
    public function deleteGitCredentials(): void
    {
        $this->delete('git_token');
        $this->delete('git_user_name');
        $this->delete('git_user_email');

        // Remove git configuration
        $home = getenv('HOME') ?: '/home/appuser';
        @unlink($home . '/.git-credentials');

        Log::info('Git credentials deleted');
    }

    /**
     * Write git configuration files
     */
    protected function writeGitConfig(string $token, string $name, string $email): void
    {
        $home = getenv('HOME') ?: '/home/appuser';

        // Configure git user
        exec("git config --global user.name " . escapeshellarg($name) . " 2>&1");
        exec("git config --global user.email " . escapeshellarg($email) . " 2>&1");
        exec("git config --global credential.helper store 2>&1");

        // Write credentials file
        $credentialsContent = "https://token:{$token}@github.com\n";
        $credentialsPath = $home . '/.git-credentials';
        file_put_contents($credentialsPath, $credentialsContent);
        chmod($credentialsPath, 0600);
    }

    /**
     * Check if setup wizard has been completed
     */
    public function isSetupComplete(): bool
    {
        return (bool) $this->get('setup_complete', false);
    }

    /**
     * Mark setup wizard as complete
     */
    public function markSetupComplete(): void
    {
        $this->set('setup_complete', true);
    }

    /**
     * Check if any AI provider is configured
     */
    public function hasAnyAiProvider(): bool
    {
        return $this->hasAnthropicApiKey() || $this->hasOpenAiApiKey() || $this->isClaudeCodeAuthenticated();
    }

    /**
     * Check if Claude Code CLI is authenticated
     */
    public function isClaudeCodeAuthenticated(): bool
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $credentialsFile = $home . '/.claude/.credentials.json';
        return file_exists($credentialsFile);
    }
}
