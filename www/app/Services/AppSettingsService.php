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
     * Get OpenAI Compatible context window
     */
    public function getOpenAiCompatibleContextWindow(): ?int
    {
        $value = $this->get('openai_compatible_context_window');
        return $value !== null ? (int) $value : null;
    }

    /**
     * Set OpenAI Compatible context window
     */
    public function setOpenAiCompatibleContextWindow(?int $contextWindow): AppSetting
    {
        Log::info('OpenAI Compatible context window updated', ['context_window' => $contextWindow]);
        return $this->set('openai_compatible_context_window', $contextWindow);
    }

    /**
     * Delete all OpenAI Compatible settings
     */
    public function deleteOpenAiCompatibleSettings(): void
    {
        $this->deleteOpenAiCompatibleBaseUrl();
        $this->deleteOpenAiCompatibleApiKey();
        $this->delete('openai_compatible_model');
        $this->delete('openai_compatible_context_window');
        Log::info('OpenAI Compatible settings deleted');
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
            || $this->isCodexAuthenticated()
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

    /**
     * Check if Codex CLI is authenticated
     */
    public function isCodexAuthenticated(): bool
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $credentialsFile = $home . '/.codex/auth.json';
        return file_exists($credentialsFile);
    }

    // =========================================================================
    // Memory System Settings
    // =========================================================================

    /**
     * Get memory snapshot retention days
     */
    public function getMemorySnapshotRetentionDays(): int
    {
        return (int) $this->get('memory_snapshot_retention_days', config('memory.snapshot_retention_days', 30));
    }

    /**
     * Set memory snapshot retention days
     */
    public function setMemorySnapshotRetentionDays(int $days): AppSetting
    {
        $days = max(1, min(365, $days));
        Log::info('Memory snapshot retention updated', ['days' => $days]);
        return $this->set('memory_snapshot_retention_days', $days);
    }
}
