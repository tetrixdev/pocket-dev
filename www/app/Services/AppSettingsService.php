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
}
