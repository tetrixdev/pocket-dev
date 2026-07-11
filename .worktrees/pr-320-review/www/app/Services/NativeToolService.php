<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Centralized service for native tool configuration.
 *
 * Native tools are CLI tools built into providers like Claude Code and Codex.
 * This service merges static config (config/native_tools.php) with runtime
 * overrides (tool_settings table) to provide a single source of truth.
 *
 * Usage:
 *   $service = app(NativeToolService::class);
 *   $tools = $service->getToolsForProvider('claude_code');
 *   $enabledNames = $service->getEnabledToolNames('claude_code');
 *
 * @see config/native_tools.php for static tool definitions
 * @see database/migrations/2025_12_22_000001_create_tool_settings_table.php
 */
class NativeToolService
{
    /**
     * Cached config with overrides applied.
     */
    private ?array $configCache = null;

    /**
     * Get all native tools for a provider with their enabled status.
     *
     * @param string $provider 'claude_code' or 'codex'
     * @return array<array{name: string, description: string, enabled: bool}>
     */
    public function getToolsForProvider(string $provider): array
    {
        $config = $this->getAllConfig();

        if (!isset($config[$provider]['tools'])) {
            return [];
        }

        return array_map(function ($tool) {
            return [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'enabled' => $tool['enabled'] ?? true,
            ];
        }, $config[$provider]['tools']);
    }

    /**
     * Get only enabled tool names for a provider.
     * Used when sending tools to CLI.
     *
     * @param string $provider 'claude_code' or 'codex'
     * @return array<string>
     */
    public function getEnabledToolNames(string $provider): array
    {
        $tools = $this->getToolsForProvider($provider);

        return array_values(
            array_map(
                fn($tool) => $tool['name'],
                array_filter($tools, fn($tool) => $tool['enabled'])
            )
        );
    }

    /**
     * Check if a specific tool is enabled.
     *
     * @param string $provider 'claude_code' or 'codex'
     * @param string $toolName
     * @return bool
     */
    public function isToolEnabled(string $provider, string $toolName): bool
    {
        $tools = $this->getToolsForProvider($provider);

        foreach ($tools as $tool) {
            if ($tool['name'] === $toolName) {
                return $tool['enabled'];
            }
        }

        // Unknown tool - default to enabled
        return true;
    }

    /**
     * Get all native tools config with overrides applied.
     * Returns full config structure for all providers.
     *
     * @return array
     */
    public function getAllConfig(): array
    {
        if ($this->configCache !== null) {
            return $this->configCache;
        }

        $config = config('native_tools', []);

        // Load all overrides from database
        $overrides = DB::table('tool_settings')
            ->get()
            ->keyBy(fn ($row) => "{$row->provider}.{$row->tool_name}");

        // Apply overrides to each provider's tools
        foreach ($config as $provider => &$providerConfig) {
            if (!isset($providerConfig['tools'])) {
                continue;
            }

            foreach ($providerConfig['tools'] as &$tool) {
                $key = "{$provider}.{$tool['name']}";
                if ($overrides->has($key)) {
                    $tool['enabled'] = (bool) $overrides->get($key)->enabled;
                }
            }
        }

        $this->configCache = $config;

        return $config;
    }

    /**
     * Clear the config cache.
     * Call this after updating tool settings.
     */
    public function clearCache(): void
    {
        $this->configCache = null;
    }
}
