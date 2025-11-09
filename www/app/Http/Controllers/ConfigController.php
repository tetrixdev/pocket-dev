<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConfigController extends Controller
{
    // =========================================================================
    // CORE CONFIGURATION
    // =========================================================================

    /**
     * Configuration registry - defines all editable configs
     */
    protected function getConfigs(): array
    {
        return [
            'claude' => [
                'title' => 'CLAUDE.md',
                'local_path' => '/home/appuser/.claude/CLAUDE.md',
                'container' => 'pocket-dev-php',
                'container_path' => '/home/appuser/.claude/CLAUDE.md',
                'syntax' => 'markdown',
                'validate' => false,
                'reload_cmd' => null,
            ],
            'settings' => [
                'title' => 'Claude Settings',
                'local_path' => '/home/appuser/.claude/settings.json',
                'container' => 'pocket-dev-php',
                'container_path' => '/home/appuser/.claude/settings.json',
                'syntax' => 'json',
                'validate' => false,
                'reload_cmd' => null,
            ],
            'nginx' => [
                'title' => 'Nginx Proxy Config',
                'local_path' => '/etc/nginx-proxy-config/nginx.conf.template',
                'container' => 'pocket-dev-proxy',
                'container_path' => '/etc/nginx-proxy-config/nginx.conf.template',
                'syntax' => 'nginx',
                'validate' => true,
                'reload_cmd' => 'sh -c "envsubst \'\$IP_ALLOWED \$AUTH_ENABLED \$DEFAULT_SERVER \$DOMAIN_NAME\' < /etc/nginx-proxy-config/nginx.conf.template > /etc/nginx/nginx.conf && nginx -s reload"',
            ],
        ];
    }

    /**
     * Read file from local mounted path
     */
    protected function readFromLocalPath(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }

        return $content;
    }

    /**
     * Write file to local mounted path
     */
    protected function writeToLocalPath(string $path, string $content): void
    {
        $result = file_put_contents($path, $content);

        if ($result === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }

    /**
     * Execute command in docker container
     */
    protected function execInContainer(string $container, string $command): void
    {
        // Run docker directly - www-data user is in hostdocker group (GID 1001)
        // which grants access to the docker socket
        $fullCommand = "docker exec {$container} {$command} 2>&1";

        exec($fullCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Command failed: ' . implode("\n", $output));
        }
    }

    /**
     * Validate nginx configuration
     */
    protected function validateNginxConfig(string $content): void
    {
        if (empty(trim($content))) {
            throw new \RuntimeException('Nginx config cannot be empty');
        }

        // Basic validation: check for required blocks
        if (!str_contains($content, 'http {') && !str_contains($content, 'events {')) {
            throw new \RuntimeException('Invalid nginx config: missing required blocks');
        }
    }

    // =========================================================================
    // MAIN INDEX & NAVIGATION
    // =========================================================================

    /**
     * Main index - redirect to last visited section or default to claude
     */
    public function index(Request $request)
    {
        $lastSection = $request->session()->get('config_last_section', 'claude');
        return redirect()->route('config.' . $lastSection);
    }

    // =========================================================================
    // SIMPLE FILE EDITORS
    // =========================================================================

    /**
     * Show CLAUDE.md editor
     */
    public function showClaude(Request $request)
    {
        $request->session()->put('config_last_section', 'claude');

        try {
            $config = $this->getConfigs()['claude'];
            $content = $this->readFromLocalPath($config['local_path']);

            return view('config.claude', [
                'content' => $content,
                'config' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to load CLAUDE.md', ['error' => $e->getMessage()]);
            return redirect()->route('config.index')
                ->with('error', 'Failed to load CLAUDE.md: ' . $e->getMessage());
        }
    }

    /**
     * Save CLAUDE.md
     */
    public function saveClaude(Request $request)
    {
        try {
            $validated = $request->validate([
                'content' => 'required|string',
            ]);

            $config = $this->getConfigs()['claude'];
            $this->writeToLocalPath($config['local_path'], $validated['content']);

            return redirect()->back()->with('success', 'CLAUDE.md saved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to save CLAUDE.md', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to save CLAUDE.md: ' . $e->getMessage());
        }
    }

    /**
     * Show settings.json editor
     */
    public function showSettings(Request $request)
    {
        $request->session()->put('config_last_section', 'settings');

        try {
            $config = $this->getConfigs()['settings'];
            $content = $this->readFromLocalPath($config['local_path']);

            return view('config.settings', [
                'content' => $content,
                'config' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to load settings.json', ['error' => $e->getMessage()]);
            return redirect()->route('config.index')
                ->with('error', 'Failed to load settings.json: ' . $e->getMessage());
        }
    }

    /**
     * Save settings.json
     */
    public function saveSettings(Request $request)
    {
        try {
            $validated = $request->validate([
                'content' => 'required|string',
            ]);

            $config = $this->getConfigs()['settings'];
            $this->writeToLocalPath($config['local_path'], $validated['content']);

            return redirect()->back()->with('success', 'Settings saved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to save settings.json', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to save settings.json: ' . $e->getMessage());
        }
    }

    /**
     * Show nginx config editor
     */
    public function showNginx(Request $request)
    {
        $request->session()->put('config_last_section', 'nginx');

        try {
            $config = $this->getConfigs()['nginx'];
            $content = $this->readFromLocalPath($config['local_path']);

            return view('config.nginx', [
                'content' => $content,
                'config' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to load nginx config', ['error' => $e->getMessage()]);
            return redirect()->route('config.index')
                ->with('error', 'Failed to load nginx config: ' . $e->getMessage());
        }
    }

    /**
     * Save nginx config and reload
     */
    public function saveNginx(Request $request)
    {
        try {
            $validated = $request->validate([
                'content' => 'required|string',
            ]);

            $config = $this->getConfigs()['nginx'];

            // Validate nginx config
            $this->validateNginxConfig($validated['content']);

            // Save config
            $this->writeToLocalPath($config['local_path'], $validated['content']);

            // Reload nginx
            if ($config['reload_cmd']) {
                $this->execInContainer($config['container'], $config['reload_cmd']);
            }

            return redirect()->back()->with('success', 'Nginx config saved and reloaded successfully');
        } catch (\Exception $e) {
            Log::error('Failed to save nginx config', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to save nginx config: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // AGENTS CRUD
    // =========================================================================

    protected function getAgentsPath(): string
    {
        return '/home/appuser/.claude/agents';
    }

    /**
     * List all agents
     */
    public function listAgents(Request $request)
    {
        $request->session()->put('config_last_section', 'agents');

        try {
            $agentsPath = $this->getAgentsPath();
            $agents = [];

            if (is_dir($agentsPath)) {
                $files = scandir($agentsPath);

                foreach ($files as $file) {
                    if (pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                        $filePath = $agentsPath . '/' . $file;
                        $parsed = $this->parseAgentFile($filePath);

                        $agents[] = [
                            'filename' => $file,
                            'name' => $parsed['frontmatter']['name'] ?? pathinfo($file, PATHINFO_FILENAME),
                            'description' => $parsed['frontmatter']['description'] ?? '',
                            'tools' => $parsed['frontmatter']['tools'] ?? '',
                            'model' => $parsed['frontmatter']['model'] ?? 'inherit',
                            'modified' => filemtime($filePath),
                        ];
                    }
                }

                // Sort by modified time, newest first
                usort($agents, fn($a, $b) => $b['modified'] - $a['modified']);
            }

            return view('config.agents.index', [
                'agents' => $agents,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list agents', ['error' => $e->getMessage()]);
            return redirect()->route('config.index')
                ->with('error', 'Failed to list agents: ' . $e->getMessage());
        }
    }

    /**
     * Show create agent form
     */
    public function createAgentForm(Request $request)
    {
        $request->session()->put('config_last_section', 'agents');
        return view('config.agents.form');
    }

    /**
     * Store new agent
     */
    public function storeAgent(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|regex:/^[a-z0-9-]+$/',
                'description' => 'required|string|max:1024',
                'tools' => 'nullable|string',
                'model' => 'nullable|string',
                'systemPrompt' => 'nullable|string',
            ]);

            $agentsPath = $this->getAgentsPath();

            // Ensure agents directory exists
            if (!is_dir($agentsPath)) {
                mkdir($agentsPath, 0775, true);
            }

            $filename = $validated['name'] . '.md';
            $filePath = $agentsPath . '/' . $filename;

            // Check if file already exists
            if (file_exists($filePath)) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'An agent with this name already exists');
            }

            // Build frontmatter
            $frontmatter = [
                'name' => $validated['name'],
                'description' => $validated['description'],
            ];

            if (!empty($validated['tools'])) {
                $frontmatter['tools'] = $validated['tools'];
            }

            $frontmatter['model'] = $validated['model'] ?? 'inherit';

            $systemPrompt = $validated['systemPrompt'] ?? "System prompt for {$validated['name']} agent.\n\nAdd your instructions here.";

            $content = $this->buildAgentFile($frontmatter, $systemPrompt);
            file_put_contents($filePath, $content);

            return redirect()->route('config.agents')
                ->with('success', 'Agent created successfully');
        } catch (\Exception $e) {
            Log::error('Failed to create agent', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to create agent: ' . $e->getMessage());
        }
    }

    /**
     * Show edit agent form
     */
    public function editAgentForm(Request $request, string $filename)
    {
        $request->session()->put('config_last_section', 'agents');

        try {
            $agentsPath = $this->getAgentsPath();
            $filePath = $agentsPath . '/' . $filename;

            if (!file_exists($filePath)) {
                return redirect()->route('config.agents')
                    ->with('error', 'Agent not found');
            }

            $parsed = $this->parseAgentFile($filePath);

            // Combine into single agent array for view
            $agent = [
                'filename' => $filename,
                'name' => $parsed['frontmatter']['name'] ?? '',
                'description' => $parsed['frontmatter']['description'] ?? '',
                'tools' => $parsed['frontmatter']['tools'] ?? '',
                'model' => $parsed['frontmatter']['model'] ?? 'inherit',
                'systemPrompt' => $parsed['content'],
            ];

            return view('config.agents.form', [
                'agent' => $agent,
                'activeAgent' => $filename,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to load agent {$filename}", ['error' => $e->getMessage()]);
            return redirect()->route('config.agents')
                ->with('error', 'Failed to load agent: ' . $e->getMessage());
        }
    }

    /**
     * Update agent
     */
    public function updateAgent(Request $request, string $filename)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|regex:/^[a-z0-9-]+$/',
                'description' => 'required|string|max:1024',
                'tools' => 'nullable|string',
                'model' => 'nullable|string',
                'systemPrompt' => 'required|string',
            ]);

            $agentsPath = $this->getAgentsPath();
            $filePath = $agentsPath . '/' . $filename;

            if (!file_exists($filePath)) {
                return redirect()->route('config.agents')
                    ->with('error', 'Agent not found');
            }

            // Build frontmatter
            $frontmatter = [
                'name' => $validated['name'],
                'description' => $validated['description'],
            ];

            if (!empty($validated['tools'])) {
                $frontmatter['tools'] = $validated['tools'];
            }

            $frontmatter['model'] = $validated['model'] ?? 'inherit';

            $content = $this->buildAgentFile($frontmatter, $validated['systemPrompt']);
            file_put_contents($filePath, $content);

            return redirect()->route('config.agents')
                ->with('success', 'Agent saved successfully');
        } catch (\Exception $e) {
            Log::error("Failed to save agent {$filename}", ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to save agent: ' . $e->getMessage());
        }
    }

    /**
     * Delete agent
     */
    public function deleteAgent(string $filename)
    {
        try {
            $agentsPath = $this->getAgentsPath();
            $filePath = $agentsPath . '/' . $filename;

            if (!file_exists($filePath)) {
                return redirect()->route('config.agents')
                    ->with('error', 'Agent not found');
            }

            unlink($filePath);

            return redirect()->route('config.agents')
                ->with('success', 'Agent deleted successfully');
        } catch (\Exception $e) {
            Log::error("Failed to delete agent {$filename}", ['error' => $e->getMessage()]);
            return redirect()->route('config.agents')
                ->with('error', 'Failed to delete agent: ' . $e->getMessage());
        }
    }

    /**
     * Parse agent file (YAML frontmatter + markdown content)
     */
    protected function parseAgentFile(string $filePath): array
    {
        $content = file_get_contents($filePath);

        // Check for frontmatter
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            // No frontmatter, entire content is system prompt
            return [
                'frontmatter' => [],
                'content' => $content
            ];
        }

        $frontmatterYaml = $matches[1];
        $systemPrompt = $matches[2];

        // Parse YAML frontmatter
        $frontmatter = [];
        $lines = explode("\n", $frontmatterYaml);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $frontmatter[trim($key)] = trim($value);
            }
        }

        return [
            'frontmatter' => $frontmatter,
            'content' => trim($systemPrompt)
        ];
    }

    /**
     * Build agent file content (YAML frontmatter + markdown)
     */
    protected function buildAgentFile(array $frontmatter, string $systemPrompt): string
    {
        $yaml = "---\n";
        foreach ($frontmatter as $key => $value) {
            $yaml .= "{$key}: {$value}\n";
        }
        $yaml .= "---\n\n";

        return $yaml . $systemPrompt;
    }

    // =========================================================================
    // COMMANDS CRUD
    // =========================================================================

    protected function getCommandsPath(): string
    {
        return '/home/appuser/.claude/commands';
    }

    /**
     * List all commands
     */
    public function listCommands(Request $request)
    {
        $request->session()->put('config_last_section', 'commands');

        try {
            $commandsPath = $this->getCommandsPath();
            $commands = [];

            if (is_dir($commandsPath)) {
                $files = scandir($commandsPath);

                foreach ($files as $file) {
                    if (pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                        $filePath = $commandsPath . '/' . $file;
                        $parsed = $this->parseCommandFile($filePath);

                        $commands[] = [
                            'filename' => $file,
                            'name' => pathinfo($file, PATHINFO_FILENAME),
                            'description' => $parsed['frontmatter']['description'] ?? '',
                            'argumentHint' => $parsed['frontmatter']['argument-hint'] ?? '',
                            'modified' => filemtime($filePath),
                        ];
                    }
                }

                // Sort by modified time, newest first
                usort($commands, fn($a, $b) => $b['modified'] - $a['modified']);
            }

            return view('config.commands.index', [
                'commands' => $commands,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list commands', ['error' => $e->getMessage()]);
            return redirect()->route('config.index')
                ->with('error', 'Failed to list commands: ' . $e->getMessage());
        }
    }

    /**
     * Show create command form
     */
    public function createCommandForm(Request $request)
    {
        $request->session()->put('config_last_section', 'commands');
        return view('config.commands.form');
    }

    /**
     * Store new command
     */
    public function storeCommand(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|regex:/^[a-z0-9-]+$/',
                'description' => 'required|string|max:1024',
                'argumentHint' => 'nullable|string|max:512',
                'model' => 'nullable|string',
                'prompt' => 'nullable|string',
                'disableModelInvocation' => 'nullable|boolean',
                'allowedTools' => 'nullable|string',
            ]);

            $commandsPath = $this->getCommandsPath();

            // Ensure commands directory exists
            if (!is_dir($commandsPath)) {
                mkdir($commandsPath, 0775, true);
            }

            $filename = $validated['name'] . '.md';
            $filePath = $commandsPath . '/' . $filename;

            // Check if file already exists
            if (file_exists($filePath)) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'A command with this name already exists');
            }

            // Build frontmatter (using kebab-case for field names)
            $frontmatter = [];

            // Description is required
            $frontmatter['description'] = $validated['description'];

            // Optional fields
            if (!empty($validated['argumentHint'])) {
                $frontmatter['argument-hint'] = $validated['argumentHint'];
            }
            if (!empty($validated['model'])) {
                $frontmatter['model'] = $validated['model'];
            }
            if (!empty($validated['allowedTools'])) {
                $frontmatter['allowed-tools'] = $validated['allowedTools'];
            }
            if (isset($validated['disableModelInvocation']) && $validated['disableModelInvocation']) {
                $frontmatter['disable-model-invocation'] = 'true';
            }

            $prompt = $validated['prompt'] ?? "Instructions for /{$validated['name']} command.\n\nAdd your prompt here.";

            $content = $this->buildCommandFile($frontmatter, $prompt);
            file_put_contents($filePath, $content);

            return redirect()->route('config.commands')
                ->with('success', 'Command created successfully');
        } catch (\Exception $e) {
            Log::error('Failed to create command', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to create command: ' . $e->getMessage());
        }
    }

    /**
     * Show edit command form
     */
    public function editCommandForm(Request $request, string $filename)
    {
        $request->session()->put('config_last_section', 'commands');

        try {
            $commandsPath = $this->getCommandsPath();
            $filePath = $commandsPath . '/' . $filename;

            if (!file_exists($filePath)) {
                return redirect()->route('config.commands')
                    ->with('error', 'Command not found');
            }

            $parsed = $this->parseCommandFile($filePath);

            // Combine into single command array for view (map kebab-case to form fields)
            $command = [
                'filename' => $filename,
                'name' => pathinfo($filename, PATHINFO_FILENAME),
                'description' => $parsed['frontmatter']['description'] ?? '',
                'argumentHint' => $parsed['frontmatter']['argument-hint'] ?? '',
                'model' => $parsed['frontmatter']['model'] ?? '',
                'prompt' => $parsed['content'],
                'disableModelInvocation' => ($parsed['frontmatter']['disable-model-invocation'] ?? 'false') === 'true',
                'allowedTools' => $parsed['frontmatter']['allowed-tools'] ?? '',
            ];

            return view('config.commands.form', [
                'command' => $command,
                'activeCommand' => $filename,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to load command {$filename}", ['error' => $e->getMessage()]);
            return redirect()->route('config.commands')
                ->with('error', 'Failed to load command: ' . $e->getMessage());
        }
    }

    /**
     * Update command
     */
    public function updateCommand(Request $request, string $filename)
    {
        try {
            $validated = $request->validate([
                'description' => 'required|string|max:1024',
                'argumentHint' => 'nullable|string|max:512',
                'model' => 'nullable|string',
                'prompt' => 'required|string',
                'disableModelInvocation' => 'nullable|boolean',
                'allowedTools' => 'nullable|string',
            ]);

            $commandsPath = $this->getCommandsPath();
            $filePath = $commandsPath . '/' . $filename;

            if (!file_exists($filePath)) {
                return redirect()->route('config.commands')
                    ->with('error', 'Command not found');
            }

            // Build frontmatter (using kebab-case for field names)
            $frontmatter = [];

            // Description is required
            $frontmatter['description'] = $validated['description'];

            // Optional fields
            if (!empty($validated['argumentHint'])) {
                $frontmatter['argument-hint'] = $validated['argumentHint'];
            }
            if (!empty($validated['model'])) {
                $frontmatter['model'] = $validated['model'];
            }
            if (!empty($validated['allowedTools'])) {
                $frontmatter['allowed-tools'] = $validated['allowedTools'];
            }
            if (isset($validated['disableModelInvocation']) && $validated['disableModelInvocation']) {
                $frontmatter['disable-model-invocation'] = 'true';
            }

            $content = $this->buildCommandFile($frontmatter, $validated['prompt']);
            file_put_contents($filePath, $content);

            return redirect()->route('config.commands')
                ->with('success', 'Command saved successfully');
        } catch (\Exception $e) {
            Log::error("Failed to save command {$filename}", ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to save command: ' . $e->getMessage());
        }
    }

    /**
     * Delete command
     */
    public function deleteCommand(string $filename)
    {
        try {
            $commandsPath = $this->getCommandsPath();
            $filePath = $commandsPath . '/' . $filename;

            if (!file_exists($filePath)) {
                return redirect()->route('config.commands')
                    ->with('error', 'Command not found');
            }

            unlink($filePath);

            return redirect()->route('config.commands')
                ->with('success', 'Command deleted successfully');
        } catch (\Exception $e) {
            Log::error("Failed to delete command {$filename}", ['error' => $e->getMessage()]);
            return redirect()->route('config.commands')
                ->with('error', 'Failed to delete command: ' . $e->getMessage());
        }
    }

    /**
     * Parse command file (optional YAML frontmatter + markdown content)
     */
    protected function parseCommandFile(string $filePath): array
    {
        $content = file_get_contents($filePath);

        // Check for frontmatter
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            // No frontmatter, entire content is prompt
            return [
                'frontmatter' => [],
                'content' => $content
            ];
        }

        $frontmatterYaml = $matches[1];
        $prompt = $matches[2];

        // Parse YAML frontmatter
        $frontmatter = [];
        $lines = explode("\n", $frontmatterYaml);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $frontmatter[trim($key)] = trim($value);
            }
        }

        return [
            'frontmatter' => $frontmatter,
            'content' => trim($prompt)
        ];
    }

    /**
     * Build command file content (optional YAML frontmatter + markdown)
     */
    protected function buildCommandFile(array $frontmatter, string $prompt): string
    {
        // Only add frontmatter if there are fields
        if (empty($frontmatter)) {
            return $prompt;
        }

        $yaml = "---\n";
        foreach ($frontmatter as $key => $value) {
            $yaml .= "{$key}: {$value}\n";
        }
        $yaml .= "---\n\n";

        return $yaml . $prompt;
    }

    // =========================================================================
    // HOOKS MANAGEMENT
    // =========================================================================

    protected function getSettingsPath(): string
    {
        return '/home/appuser/.claude/settings.json';
    }

    /**
     * Show hooks editor
     */
    public function showHooks(Request $request)
    {
        $request->session()->put('config_last_section', 'hooks');

        try {
            $settings = $this->readSettings();
            $hooks = $settings['hooks'] ?? [];

            // Convert hooks to pretty JSON for editing
            $content = json_encode($hooks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return view('config.hooks', [
                'content' => $content,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to load hooks', ['error' => $e->getMessage()]);
            return redirect()->route('config.index')
                ->with('error', 'Failed to load hooks: ' . $e->getMessage());
        }
    }

    /**
     * Save hooks
     */
    public function saveHooks(Request $request)
    {
        try {
            $validated = $request->validate([
                'content' => 'required|json',
            ]);

            // Read current settings
            $settings = $this->readSettings();

            // Update hooks section
            $settings['hooks'] = json_decode($validated['content'], true);

            // Write back to settings.json
            $this->writeSettings($settings);

            return redirect()->back()->with('success', 'Hooks saved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to save hooks', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to save hooks: ' . $e->getMessage());
        }
    }

    /**
     * Read settings.json
     */
    protected function readSettings(): array
    {
        $settingsPath = $this->getSettingsPath();

        if (!file_exists($settingsPath)) {
            return [];
        }

        $content = file_get_contents($settingsPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read settings file");
        }

        $settings = json_decode($content, true);
        if ($settings === null) {
            throw new \RuntimeException("Failed to parse settings JSON");
        }

        return $settings;
    }

    /**
     * Write settings.json
     */
    protected function writeSettings(array $settings): void
    {
        $settingsPath = $this->getSettingsPath();

        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Failed to encode settings to JSON");
        }

        $result = file_put_contents($settingsPath, $json);
        if ($result === false) {
            throw new \RuntimeException("Failed to write settings file");
        }
    }

    // =========================================================================
    // SKILLS CRUD
    // =========================================================================

    protected function getSkillsPath(): string
    {
        return '/home/appuser/.claude/skills';
    }

    /**
     * List all skills
     */
    public function listSkills(Request $request)
    {
        $request->session()->put('config_last_section', 'skills');

        try {
            $skillsPath = $this->getSkillsPath();
            $skills = [];

            if (is_dir($skillsPath)) {
                $dirs = scandir($skillsPath);

                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') continue;

                    $skillDir = $skillsPath . '/' . $dir;
                    if (!is_dir($skillDir)) continue;

                    $skillMdPath = $skillDir . '/SKILL.md';
                    if (!file_exists($skillMdPath)) continue;

                    $parsed = $this->parseSkillFile($skillMdPath);

                    $skills[] = [
                        'name' => $dir,
                        'displayName' => $parsed['frontmatter']['name'] ?? $dir,
                        'description' => $parsed['frontmatter']['description'] ?? '',
                        'allowedTools' => $parsed['frontmatter']['allowed-tools'] ?? '',
                        'modified' => filemtime($skillMdPath),
                    ];
                }

                // Sort by modified time, newest first
                usort($skills, fn($a, $b) => $b['modified'] - $a['modified']);
            }

            return view('config.skills.index', [
                'skills' => $skills,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list skills', ['error' => $e->getMessage()]);
            return redirect()->route('config.index')
                ->with('error', 'Failed to list skills: ' . $e->getMessage());
        }
    }

    /**
     * Show create skill form
     */
    public function createSkillForm(Request $request)
    {
        $request->session()->put('config_last_section', 'skills');
        return view('config.skills.form');
    }

    /**
     * Store new skill
     */
    public function storeSkill(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|regex:/^[a-z0-9-]+$/|max:64',
                'description' => 'required|string|max:1024',
                'allowedTools' => 'nullable|string',
            ]);

            $skillsPath = $this->getSkillsPath();

            // Ensure skills directory exists
            if (!is_dir($skillsPath)) {
                mkdir($skillsPath, 0775, true);
            }

            $skillDir = $skillsPath . '/' . $validated['name'];

            // Check if skill already exists
            if (is_dir($skillDir)) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'A skill with this name already exists');
            }

            // Create skill directory
            mkdir($skillDir, 0775, true);

            // Build SKILL.md frontmatter
            $frontmatter = [
                'name' => $validated['name'],
                'description' => $validated['description'],
            ];
            if (!empty($validated['allowedTools'])) {
                $frontmatter['allowed-tools'] = $validated['allowedTools'];
            }

            $content = "Instructions for using this skill.\n\nAdd your skill implementation here.";
            $skillMdContent = $this->buildSkillFile($frontmatter, $content);

            file_put_contents($skillDir . '/SKILL.md', $skillMdContent);

            return redirect()->route('config.skills')
                ->with('success', 'Skill created successfully');
        } catch (\Exception $e) {
            Log::error('Failed to create skill', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to create skill: ' . $e->getMessage());
        }
    }

    /**
     * Show skill file browser
     */
    public function editSkillForm(Request $request, string $skillName)
    {
        $request->session()->put('config_last_section', 'skills');

        try {
            $skillsPath = $this->getSkillsPath();
            $skillDir = $skillsPath . '/' . $skillName;

            if (!is_dir($skillDir)) {
                return redirect()->route('config.skills')
                    ->with('error', 'Skill not found');
            }

            $files = $this->recursiveListDirectory($skillDir, $skillDir);

            // Try to read SKILL.md for metadata
            $skillMdPath = $skillDir . '/SKILL.md';
            $parsed = ['frontmatter' => [], 'content' => ''];
            if (file_exists($skillMdPath)) {
                $parsed = $this->parseSkillFile($skillMdPath);
            }

            // Combine into single skill array for view
            $skill = [
                'name' => $skillName,
                'filename' => $skillName,
                'description' => $parsed['frontmatter']['description'] ?? '',
                'allowedTools' => $parsed['frontmatter']['allowed-tools'] ?? '',
            ];

            return view('config.skills.edit', [
                'skill' => $skill,
                'files' => $files,
                'activeSkill' => $skillName,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to load skill {$skillName}", ['error' => $e->getMessage()]);
            return redirect()->route('config.skills')
                ->with('error', 'Failed to load skill: ' . $e->getMessage());
        }
    }

    /**
     * Delete skill
     */
    public function deleteSkill(string $skillName)
    {
        try {
            $skillsPath = $this->getSkillsPath();
            $skillDir = $skillsPath . '/' . $skillName;

            if (!is_dir($skillDir)) {
                return redirect()->route('config.skills')
                    ->with('error', 'Skill not found');
            }

            $this->recursiveRemoveDirectory($skillDir);

            return redirect()->route('config.skills')
                ->with('success', 'Skill deleted successfully');
        } catch (\Exception $e) {
            Log::error("Failed to delete skill {$skillName}", ['error' => $e->getMessage()]);
            return redirect()->route('config.skills')
                ->with('error', 'Failed to delete skill: ' . $e->getMessage());
        }
    }

    /**
     * Parse SKILL.md file (YAML frontmatter + markdown content)
     */
    protected function parseSkillFile(string $filePath): array
    {
        $content = file_get_contents($filePath);

        // Check for frontmatter
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            return [
                'frontmatter' => [],
                'content' => $content
            ];
        }

        $frontmatterYaml = $matches[1];
        $skillContent = $matches[2];

        // Parse YAML frontmatter
        $frontmatter = [];
        $lines = explode("\n", $frontmatterYaml);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $frontmatter[trim($key)] = trim($value);
            }
        }

        return [
            'frontmatter' => $frontmatter,
            'content' => trim($skillContent)
        ];
    }

    /**
     * Build SKILL.md file content (YAML frontmatter + markdown)
     */
    protected function buildSkillFile(array $frontmatter, string $content): string
    {
        $yaml = "---\n";
        foreach ($frontmatter as $key => $value) {
            $yaml .= "{$key}: {$value}\n";
        }
        $yaml .= "---\n\n";

        return $yaml . $content;
    }

    /**
     * Recursively list directory contents
     */
    protected function recursiveListDirectory(string $dir, string $baseDir): array
    {
        $files = [];
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = $dir . '/' . $item;
            $relativePath = substr($path, strlen($baseDir) + 1);

            if (is_dir($path)) {
                $files[] = [
                    'name' => $item,
                    'path' => $relativePath,
                    'type' => 'directory',
                    'children' => $this->recursiveListDirectory($path, $baseDir)
                ];
            } else {
                $files[] = [
                    'name' => $item,
                    'path' => $relativePath,
                    'type' => 'file',
                    'size' => filesize($path),
                    'modified' => filemtime($path)
                ];
            }
        }

        return $files;
    }

    /**
     * Recursively remove a directory
     */
    protected function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
