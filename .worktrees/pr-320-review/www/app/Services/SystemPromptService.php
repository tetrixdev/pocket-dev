<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Manages the system prompt configuration.
 *
 * The system prompt consists of two parts:
 * 1. Core prompt - Base AI instructions (rarely modified)
 * 2. Additional prompt - Project-specific instructions (commonly customized)
 *
 * Final prompt = Core + Additional
 *
 * Storage:
 * - Core default: resources/defaults/system-prompt.md
 * - Core override: storage/pocketdev/system-prompt.md
 * - Additional default: From config ai.additional_system_prompt_file (optional)
 * - Additional override: storage/pocketdev/additional-system-prompt.md
 */
class SystemPromptService
{
    private string $coreDefaultPath;
    private string $coreOverridePath;
    private string $additionalOverridePath;

    public function __construct()
    {
        $this->coreDefaultPath = resource_path('defaults/system-prompt.md');
        $this->coreOverridePath = storage_path('pocketdev/system-prompt.md');
        $this->additionalOverridePath = storage_path('pocketdev/additional-system-prompt.md');
    }

    /**
     * Get the complete system prompt (core + additional).
     */
    public function get(): string
    {
        $core = $this->getCore();
        $additional = $this->getAdditional();

        if (empty($additional)) {
            return $core;
        }

        return $core . "\n\n" . $additional;
    }

    /**
     * Get the core system prompt.
     */
    public function getCore(): string
    {
        if ($this->isCoreOverridden()) {
            return File::get($this->coreOverridePath);
        }

        return $this->getCoreDefault();
    }

    /**
     * Get the default core system prompt.
     */
    public function getCoreDefault(): string
    {
        if (!File::exists($this->coreDefaultPath)) {
            throw new \RuntimeException("Default system prompt not found at: {$this->coreDefaultPath}");
        }

        return File::get($this->coreDefaultPath);
    }

    /**
     * Check if core prompt is overridden.
     */
    public function isCoreOverridden(): bool
    {
        return File::exists($this->coreOverridePath);
    }

    /**
     * Save a core prompt override.
     */
    public function saveCoreOverride(string $content): void
    {
        $this->ensureStorageDirectory();
        File::put($this->coreOverridePath, $content);
    }

    /**
     * Reset core prompt to default.
     */
    public function resetCoreToDefault(): void
    {
        if ($this->isCoreOverridden()) {
            File::delete($this->coreOverridePath);
        }
    }

    /**
     * Get the additional system prompt.
     */
    public function getAdditional(): string
    {
        if ($this->isAdditionalOverridden()) {
            return File::get($this->additionalOverridePath);
        }

        return $this->getAdditionalDefault();
    }

    /**
     * Get the default additional system prompt.
     * Returns content from config file path, or empty string if not set.
     */
    public function getAdditionalDefault(): string
    {
        $defaultFile = config('ai.additional_system_prompt_file');

        if (empty($defaultFile)) {
            return '';
        }

        if (!File::exists($defaultFile)) {
            return '';
        }

        return File::get($defaultFile);
    }

    /**
     * Check if additional prompt is overridden.
     */
    public function isAdditionalOverridden(): bool
    {
        return File::exists($this->additionalOverridePath);
    }

    /**
     * Check if additional prompt has any content (default or override).
     */
    public function hasAdditional(): bool
    {
        return !empty($this->getAdditional());
    }

    /**
     * Save an additional prompt override.
     */
    public function saveAdditionalOverride(string $content): void
    {
        $this->ensureStorageDirectory();
        File::put($this->additionalOverridePath, $content);
    }

    /**
     * Reset additional prompt to default.
     */
    public function resetAdditionalToDefault(): void
    {
        if ($this->isAdditionalOverridden()) {
            File::delete($this->additionalOverridePath);
        }
    }

    /**
     * Ensure the storage directory exists.
     */
    private function ensureStorageDirectory(): void
    {
        $directory = storage_path('pocketdev');

        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    // =========================================================================
    // Legacy methods for backwards compatibility
    // =========================================================================

    /**
     * @deprecated Use isCoreOverridden() or isAdditionalOverridden()
     */
    public function isOverridden(): bool
    {
        return $this->isCoreOverridden() || $this->isAdditionalOverridden();
    }

    /**
     * @deprecated Use getCore()
     */
    public function getDefault(): string
    {
        return $this->getCoreDefault();
    }

    /**
     * @deprecated Use saveCoreOverride()
     */
    public function saveOverride(string $content): void
    {
        $this->saveCoreOverride($content);
    }

    /**
     * @deprecated Use resetCoreToDefault()
     */
    public function resetToDefault(): void
    {
        $this->resetCoreToDefault();
    }
}
