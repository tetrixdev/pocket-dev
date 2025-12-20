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

    // =========================================================================
    // OpenAI Compatible (Local LLM) Settings
    // =========================================================================

    /**
     * Get OpenAI Compatible base URL
     */
    public function getOpenAiCompatibleBaseUrl(): ?string
    {
        return $this->get('openai_compatible_base_url');
    }

    /**
     * Set OpenAI Compatible base URL
     */
    public function setOpenAiCompatibleBaseUrl(string $url): AppSetting
    {
        Log::info('OpenAI Compatible base URL updated', ['url' => $url]);
        return $this->set('openai_compatible_base_url', $url);
    }

    /**
     * Check if OpenAI Compatible base URL is configured
     */
    public function hasOpenAiCompatibleBaseUrl(): bool
    {
        $url = $this->getOpenAiCompatibleBaseUrl();
        return !empty($url);
    }

    /**
     * Delete OpenAI Compatible base URL
     */
    public function deleteOpenAiCompatibleBaseUrl(): bool
    {
        Log::info('OpenAI Compatible base URL deleted');
        return $this->delete('openai_compatible_base_url');
    }

    /**
     * Get OpenAI Compatible API key (optional)
     */
    public function getOpenAiCompatibleApiKey(): ?string
    {
        return $this->get('openai_compatible_api_key');
    }

    /**
     * Set OpenAI Compatible API key
     */
    public function setOpenAiCompatibleApiKey(?string $apiKey): AppSetting
    {
        Log::info('OpenAI Compatible API key updated');
        return $this->set('openai_compatible_api_key', $apiKey);
    }

    /**
     * Delete OpenAI Compatible API key
     */
    public function deleteOpenAiCompatibleApiKey(): bool
    {
        Log::info('OpenAI Compatible API key deleted');
        return $this->delete('openai_compatible_api_key');
    }

    /**
     * Get OpenAI Compatible model name
     */
    public function getOpenAiCompatibleModel(): ?string
    {
        return $this->get('openai_compatible_model');
    }

    /**
     * Set OpenAI Compatible model name
     */
    public function setOpenAiCompatibleModel(?string $model): AppSetting
    {
        Log::info('OpenAI Compatible model updated', ['model' => $model]);
        return $this->set('openai_compatible_model', $model);
    }

    /**
     * Delete all OpenAI Compatible settings
     */
    public function deleteOpenAiCompatibleSettings(): void
    {
        $this->deleteOpenAiCompatibleBaseUrl();
        $this->deleteOpenAiCompatibleApiKey();
        $this->delete('openai_compatible_model');
        Log::info('OpenAI Compatible settings deleted');
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

        // Remove git configuration files
        $home = getenv('HOME') ?: '/home/appuser';
        $credentialsPath = $home . '/.git-credentials';
        if (file_exists($credentialsPath) && !unlink($credentialsPath)) {
            Log::warning('Failed to delete .git-credentials file', ['path' => $credentialsPath]);
        }

        // Unset git config settings
        exec("git config --global --unset user.name 2>&1");
        exec("git config --global --unset user.email 2>&1");
        exec("git config --global --unset credential.helper 2>&1");

        Log::info('Git credentials deleted');
    }

    /**
     * Write git configuration files
     *
     * @throws \RuntimeException if git commands fail
     */
    protected function writeGitConfig(string $token, string $name, string $email): void
    {
        $home = getenv('HOME') ?: '/home/appuser';

        // Configure git user
        $output = [];
        $returnCode = 0;

        exec("git config --global user.name " . escapeshellarg($name) . " 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            Log::error('Failed to set git user.name', ['output' => implode("\n", $output)]);
            throw new \RuntimeException('Failed to configure git user name');
        }

        exec("git config --global user.email " . escapeshellarg($email) . " 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            Log::error('Failed to set git user.email', ['output' => implode("\n", $output)]);
            throw new \RuntimeException('Failed to configure git user email');
        }

        exec("git config --global credential.helper store 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            Log::error('Failed to set git credential.helper', ['output' => implode("\n", $output)]);
            throw new \RuntimeException('Failed to configure git credential helper');
        }

        // Write credentials file
        $credentialsContent = "https://token:{$token}@github.com\n";
        $credentialsPath = $home . '/.git-credentials';

        if (file_put_contents($credentialsPath, $credentialsContent) === false) {
            Log::error('Failed to write git credentials file', ['path' => $credentialsPath]);
            throw new \RuntimeException('Failed to write git credentials file');
        }

        if (!chmod($credentialsPath, 0600)) {
            Log::warning('Failed to set permissions on git credentials file', ['path' => $credentialsPath]);
        }
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
        return $this->hasAnthropicApiKey()
            || $this->hasOpenAiApiKey()
            || $this->isClaudeCodeAuthenticated()
            || $this->hasOpenAiCompatibleBaseUrl();
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
