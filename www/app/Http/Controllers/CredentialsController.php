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
     * Import Claude Code configuration from exported archive
     */
    public function importConfig(Request $request)
    {
        try {
            $request->validate([
                'config_archive' => 'required|file|mimes:gz,gzip,tar|max:10240', // 10MB max
            ]);

            $file = $request->file('config_archive');
            $tempDir = sys_get_temp_dir() . '/claude-config-import-' . uniqid();
            $extractDir = $tempDir . '/extracted';

            // Create temp directories
            if (!mkdir($tempDir, 0755, true) || !mkdir($extractDir, 0755, true)) {
                throw new \RuntimeException('Failed to create temporary directories');
            }

            try {
                // Save uploaded file
                $archivePath = $tempDir . '/archive.tar.gz';
                $file->move($tempDir, 'archive.tar.gz');

                // Extract archive
                $output = [];
                $returnCode = 0;
                exec("tar --no-absolute-names --no-same-owner -xzf " . escapeshellarg($archivePath) . " -C " . escapeshellarg($extractDir) . " 2>&1", $output, $returnCode);

                if ($returnCode !== 0) {
                    throw new \RuntimeException('Failed to extract archive: ' . implode("\n", $output));
                }

                // Find the export directory (should be claude-config-export-*)
                $exportDirs = glob($extractDir . '/claude-config-export-*');
                if (empty($exportDirs)) {
                    throw new \RuntimeException('Invalid archive: expected claude-config-export-* directory');
                }
                $sourceDir = $exportDirs[0];

                // Validate manifest exists
                if (!file_exists($sourceDir . '/manifest.json')) {
                    throw new \RuntimeException('Invalid archive: manifest.json not found');
                }

                $manifest = json_decode(file_get_contents($sourceDir . '/manifest.json'), true);
                if (!$manifest || !isset($manifest['version'])) {
                    throw new \RuntimeException('Invalid manifest.json');
                }

                // Target directory
                $claudeDir = '/home/appuser/.claude';
                if (!is_dir($claudeDir)) {
                    mkdir($claudeDir, 0755, true);
                }

                $imported = [];

                // Import settings.json
                if (file_exists($sourceDir . '/settings.json')) {
                    copy($sourceDir . '/settings.json', $claudeDir . '/settings.json');
                    $imported[] = 'settings.json';
                }

                // Import CLAUDE.md
                if (file_exists($sourceDir . '/CLAUDE.md')) {
                    copy($sourceDir . '/CLAUDE.md', $claudeDir . '/CLAUDE.md');
                    $imported[] = 'CLAUDE.md';
                }

                // Import directories
                foreach (['agents', 'commands', 'rules'] as $dir) {
                    if (is_dir($sourceDir . '/' . $dir)) {
                        $targetDir = $claudeDir . '/' . $dir;
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }

                        // Copy files recursively
                        $this->copyDirectory($sourceDir . '/' . $dir, $targetDir);
                        $count = iterator_count(new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($targetDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                        ));
                        if ($count > 0) {
                            $imported[] = "{$dir}/ ({$count} files)";
                        }
                    }
                }

                // Import MCP servers into claude.json
                if (file_exists($sourceDir . '/mcp-servers.json')) {
                    $mcpServers = json_decode(file_get_contents($sourceDir . '/mcp-servers.json'), true);
                    if ($mcpServers && !empty($mcpServers)) {
                        $claudeJsonPath = '/home/appuser/.claude.json';
                        $claudeJson = [];
                        if (file_exists($claudeJsonPath)) {
                            $claudeJson = json_decode(file_get_contents($claudeJsonPath), true) ?? [];
                        }
                        $claudeJson['mcpServers'] = $mcpServers;
                        file_put_contents($claudeJsonPath, json_encode($claudeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        $imported[] = 'mcp-servers.json';
                    }
                }

                // Set proper ownership (www-data)
                $this->setOwnership($claudeDir);

                // Clean up
                $this->removeDirectory($tempDir);

                if (empty($imported)) {
                    return redirect()->back()->with('warning', 'Archive was valid but contained no configuration files.');
                }

                return redirect()->back()->with('success', 'Configuration imported: ' . implode(', ', $imported));
            } catch (\Exception $e) {
                // Clean up on error
                if (is_dir($tempDir)) {
                    $this->removeDirectory($tempDir);
                }
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->with('error', 'Please upload a valid .tar.gz archive.');
        } catch (\Exception $e) {
            Log::error('Config import failed', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    /**
     * Recursively copy a directory
     */
    protected function copyDirectory(string $source, string $dest): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            // Skip symlinks for security
            if ($item->isLink()) {
                continue;
            }

            $targetPath = $dest . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    /**
     * Set ownership of files to www-data
     */
    protected function setOwnership(string $path): void
    {
        // Get www-data uid/gid
        $wwwDataInfo = posix_getpwnam('www-data');
        if (!$wwwDataInfo) {
            return; // Can't set ownership if www-data doesn't exist
        }

        $uid = $wwwDataInfo['uid'];
        $gid = $wwwDataInfo['gid'];

        // Set ownership recursively
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        chown($path, $uid);
        chgrp($path, $gid);

        foreach ($iterator as $item) {
            @chown($item->getPathname(), $uid);
            @chgrp($item->getPathname(), $gid);
        }
    }

    /**
     * Recursively remove a directory
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
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
