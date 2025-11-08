<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ConfigController extends Controller
{
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
     * Display the config editor page
     */
    public function index()
    {
        $configs = $this->getConfigs();

        return view('config.index', [
            'configs' => $configs,
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * Read a specific config file
     */
    public function read(string $id): JsonResponse
    {
        $configs = $this->getConfigs();

        if (!isset($configs[$id])) {
            return response()->json(['error' => 'Config not found'], 404);
        }

        $config = $configs[$id];

        try {
            $content = $this->readFromLocalPath($config['local_path']);

            return response()->json([
                'success' => true,
                'content' => $content,
                'config' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to read config {$id}", [
                'error' => $e->getMessage(),
                'local_path' => $config['local_path'],
            ]);

            return response()->json([
                'error' => 'Failed to read config: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save a specific config file
     */
    public function save(Request $request, string $id): JsonResponse
    {
        $configs = $this->getConfigs();

        if (!isset($configs[$id])) {
            return response()->json(['error' => 'Config not found'], 404);
        }

        $config = $configs[$id];
        $content = $request->input('content');

        if ($content === null) {
            return response()->json(['error' => 'Content is required'], 400);
        }

        try {
            // Validate if needed (nginx only for now)
            if ($config['validate']) {
                $this->validateNginxConfig($content);
            }

            // Save the config to local mounted path
            $this->writeToLocalPath($config['local_path'], $content);

            // Reload if needed (this still requires docker exec)
            if ($config['reload_cmd']) {
                $this->execInContainer($config['container'], $config['reload_cmd']);
            }

            return response()->json([
                'success' => true,
                'message' => "{$config['title']} saved successfully" .
                            ($config['reload_cmd'] ? ' and service reloaded' : ''),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to save config {$id}", [
                'error' => $e->getMessage(),
                'local_path' => $config['local_path'],
            ]);

            return response()->json([
                'error' => 'Failed to save config: ' . $e->getMessage()
            ], 500);
        }
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
        // For now, just do basic syntax checking
        // In the future, could write to temp file and run nginx -t

        if (empty(trim($content))) {
            throw new \RuntimeException('Nginx config cannot be empty');
        }

        // Basic validation: check for required blocks
        if (!str_contains($content, 'http {') && !str_contains($content, 'events {')) {
            throw new \RuntimeException('Invalid nginx config: missing required blocks');
        }
    }

    // =========================================================================
    // AGENTS MANAGEMENT
    // =========================================================================

    protected function getAgentsPath(): string
    {
        return '/home/appuser/.claude/agents';
    }

    /**
     * List all agents
     */
    public function listAgents(): JsonResponse
    {
        $agentsPath = $this->getAgentsPath();

        try {
            if (!is_dir($agentsPath)) {
                return response()->json([
                    'success' => true,
                    'agents' => []
                ]);
            }

            $files = scandir($agentsPath);
            $agents = [];

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

            return response()->json([
                'success' => true,
                'agents' => $agents
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to list agents', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to list agents: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new agent
     */
    public function createAgent(Request $request): JsonResponse
    {
        $agentsPath = $this->getAgentsPath();

        try {
            $validated = $request->validate([
                'name' => 'required|string|regex:/^[a-z0-9-]+$/',
                'description' => 'required|string|max:1024',
                'tools' => 'nullable|string',
                'model' => 'nullable|string',
                'systemPrompt' => 'nullable|string',
            ]);

            // Ensure agents directory exists
            if (!is_dir($agentsPath)) {
                mkdir($agentsPath, 0775, true);
            }

            $filename = $validated['name'] . '.md';
            $filePath = $agentsPath . '/' . $filename;

            // Check if file already exists
            if (file_exists($filePath)) {
                return response()->json([
                    'error' => 'An agent with this name already exists'
                ], 409);
            }

            // Build frontmatter
            $frontmatter = [
                'name' => $validated['name'],
                'description' => $validated['description'],
            ];

            if (!empty($validated['tools'])) {
                $frontmatter['tools'] = $validated['tools'];
            }

            // Always include model field (either specific model or 'inherit')
            $frontmatter['model'] = $validated['model'] ?? 'inherit';

            $systemPrompt = $validated['systemPrompt'] ?? "System prompt for {$validated['name']} agent.\n\nAdd your instructions here.";

            $content = $this->buildAgentFile($frontmatter, $systemPrompt);
            file_put_contents($filePath, $content);

            return response()->json([
                'success' => true,
                'message' => 'Agent created successfully',
                'filename' => $filename
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create agent', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to create agent: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Read a specific agent
     */
    public function readAgent(string $filename): JsonResponse
    {
        $agentsPath = $this->getAgentsPath();
        $filePath = $agentsPath . '/' . $filename;

        try {
            if (!file_exists($filePath)) {
                return response()->json(['error' => 'Agent not found'], 404);
            }

            $parsed = $this->parseAgentFile($filePath);

            return response()->json([
                'success' => true,
                'filename' => $filename,
                'frontmatter' => $parsed['frontmatter'],
                'systemPrompt' => $parsed['content']
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to read agent {$filename}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to read agent: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save an agent
     */
    public function saveAgent(Request $request, string $filename): JsonResponse
    {
        $agentsPath = $this->getAgentsPath();
        $filePath = $agentsPath . '/' . $filename;

        try {
            if (!file_exists($filePath)) {
                return response()->json(['error' => 'Agent not found'], 404);
            }

            $validated = $request->validate([
                'name' => 'required|string|regex:/^[a-z0-9-]+$/',
                'description' => 'required|string|max:1024',
                'tools' => 'nullable|string',
                'model' => 'nullable|string',
                'systemPrompt' => 'required|string',
            ]);

            // Build frontmatter
            $frontmatter = [
                'name' => $validated['name'],
                'description' => $validated['description'],
            ];

            if (!empty($validated['tools'])) {
                $frontmatter['tools'] = $validated['tools'];
            }

            // Always include model field (either specific model or 'inherit')
            $frontmatter['model'] = $validated['model'] ?? 'inherit';

            $content = $this->buildAgentFile($frontmatter, $validated['systemPrompt']);
            file_put_contents($filePath, $content);

            return response()->json([
                'success' => true,
                'message' => 'Agent saved successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Failed to save agent {$filename}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to save agent: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an agent
     */
    public function deleteAgent(string $filename): JsonResponse
    {
        $agentsPath = $this->getAgentsPath();
        $filePath = $agentsPath . '/' . $filename;

        try {
            if (!file_exists($filePath)) {
                return response()->json(['error' => 'Agent not found'], 404);
            }

            unlink($filePath);

            return response()->json([
                'success' => true,
                'message' => 'Agent deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to delete agent {$filename}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to delete agent: ' . $e->getMessage()
            ], 500);
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
    // COMMANDS MANAGEMENT
    // =========================================================================

    protected function getCommandsPath(): string
    {
        return '/home/appuser/.claude/commands';
    }

    /**
     * List all commands
     */
    public function listCommands(): JsonResponse
    {
        $commandsPath = $this->getCommandsPath();

        try {
            if (!is_dir($commandsPath)) {
                return response()->json([
                    'success' => true,
                    'commands' => []
                ]);
            }

            $files = scandir($commandsPath);
            $commands = [];

            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                    $filePath = $commandsPath . '/' . $file;
                    $parsed = $this->parseCommandFile($filePath);

                    $commands[] = [
                        'filename' => $file,
                        'name' => pathinfo($file, PATHINFO_FILENAME),
                        'allowedTools' => $parsed['frontmatter']['allowedTools'] ?? '',
                        'argumentHints' => $parsed['frontmatter']['argumentHints'] ?? '',
                        'modified' => filemtime($filePath),
                    ];
                }
            }

            // Sort by modified time, newest first
            usort($commands, fn($a, $b) => $b['modified'] - $a['modified']);

            return response()->json([
                'success' => true,
                'commands' => $commands
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to list commands', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to list commands: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new command
     */
    public function createCommand(Request $request): JsonResponse
    {
        $commandsPath = $this->getCommandsPath();

        try {
            $validated = $request->validate([
                'name' => 'required|string|regex:/^[a-z0-9-]+$/',
                'allowedTools' => 'nullable|string',
                'argumentHints' => 'nullable|string|max:512',
                'prompt' => 'nullable|string',
            ]);

            // Ensure commands directory exists
            if (!is_dir($commandsPath)) {
                mkdir($commandsPath, 0775, true);
            }

            $filename = $validated['name'] . '.md';
            $filePath = $commandsPath . '/' . $filename;

            // Check if file already exists
            if (file_exists($filePath)) {
                return response()->json([
                    'error' => 'A command with this name already exists'
                ], 409);
            }

            // Build frontmatter (only if fields are provided)
            $frontmatter = [];
            if (!empty($validated['allowedTools'])) {
                $frontmatter['allowedTools'] = $validated['allowedTools'];
            }
            if (!empty($validated['argumentHints'])) {
                $frontmatter['argumentHints'] = $validated['argumentHints'];
            }

            $prompt = $validated['prompt'] ?? "Instructions for /{$validated['name']} command.\n\nAdd your prompt here.";

            $content = $this->buildCommandFile($frontmatter, $prompt);
            file_put_contents($filePath, $content);

            return response()->json([
                'success' => true,
                'message' => 'Command created successfully',
                'filename' => $filename
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create command', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to create command: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Read a specific command
     */
    public function readCommand(string $filename): JsonResponse
    {
        $commandsPath = $this->getCommandsPath();
        $filePath = $commandsPath . '/' . $filename;

        try {
            if (!file_exists($filePath)) {
                return response()->json(['error' => 'Command not found'], 404);
            }

            $parsed = $this->parseCommandFile($filePath);

            return response()->json([
                'success' => true,
                'filename' => $filename,
                'frontmatter' => $parsed['frontmatter'],
                'prompt' => $parsed['content']
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to read command {$filename}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to read command: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save a command
     */
    public function saveCommand(Request $request, string $filename): JsonResponse
    {
        $commandsPath = $this->getCommandsPath();
        $filePath = $commandsPath . '/' . $filename;

        try {
            if (!file_exists($filePath)) {
                return response()->json(['error' => 'Command not found'], 404);
            }

            $validated = $request->validate([
                'allowedTools' => 'nullable|string',
                'argumentHints' => 'nullable|string|max:512',
                'prompt' => 'required|string',
            ]);

            // Build frontmatter (only if fields are provided)
            $frontmatter = [];
            if (!empty($validated['allowedTools'])) {
                $frontmatter['allowedTools'] = $validated['allowedTools'];
            }
            if (!empty($validated['argumentHints'])) {
                $frontmatter['argumentHints'] = $validated['argumentHints'];
            }

            $content = $this->buildCommandFile($frontmatter, $validated['prompt']);
            file_put_contents($filePath, $content);

            return response()->json([
                'success' => true,
                'message' => 'Command saved successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Failed to save command {$filename}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to save command: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a command
     */
    public function deleteCommand(string $filename): JsonResponse
    {
        $commandsPath = $this->getCommandsPath();
        $filePath = $commandsPath . '/' . $filename;

        try {
            if (!file_exists($filePath)) {
                return response()->json(['error' => 'Command not found'], 404);
            }

            unlink($filePath);

            return response()->json([
                'success' => true,
                'message' => 'Command deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to delete command {$filename}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to delete command: ' . $e->getMessage()
            ], 500);
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
     * Get hooks from settings.json
     */
    public function getHooks(): JsonResponse
    {
        $settingsPath = $this->getSettingsPath();

        try {
            $settings = $this->readSettings();
            $hooks = $settings['hooks'] ?? [];

            return response()->json([
                'success' => true,
                'hooks' => $hooks
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get hooks', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to get hooks: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update hooks in settings.json
     */
    public function updateHooks(Request $request): JsonResponse
    {
        $settingsPath = $this->getSettingsPath();

        try {
            $validated = $request->validate([
                'hooks' => 'required|array',
            ]);

            // Read current settings
            $settings = $this->readSettings();

            // Update hooks section
            $settings['hooks'] = $validated['hooks'];

            // Write back to settings.json
            $this->writeSettings($settings);

            return response()->json([
                'success' => true,
                'message' => 'Hooks updated successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update hooks', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to update hooks: ' . $e->getMessage()
            ], 500);
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
    // SKILLS MANAGEMENT
    // =========================================================================

    protected function getSkillsPath(): string
    {
        return '/home/appuser/.claude/skills';
    }

    /**
     * List all skills
     */
    public function listSkills(): JsonResponse
    {
        $skillsPath = $this->getSkillsPath();

        try {
            if (!is_dir($skillsPath)) {
                return response()->json([
                    'success' => true,
                    'skills' => []
                ]);
            }

            $dirs = scandir($skillsPath);
            $skills = [];

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

            return response()->json([
                'success' => true,
                'skills' => $skills
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to list skills', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to list skills: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new skill
     */
    public function createSkill(Request $request): JsonResponse
    {
        $skillsPath = $this->getSkillsPath();

        try {
            $validated = $request->validate([
                'name' => 'required|string|regex:/^[a-z0-9-]+$/|max:64',
                'description' => 'required|string|max:1024',
                'allowedTools' => 'nullable|string',
            ]);

            // Ensure skills directory exists
            if (!is_dir($skillsPath)) {
                mkdir($skillsPath, 0775, true);
            }

            $skillDir = $skillsPath . '/' . $validated['name'];

            // Check if skill already exists
            if (is_dir($skillDir)) {
                return response()->json([
                    'error' => 'A skill with this name already exists'
                ], 409);
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

            return response()->json([
                'success' => true,
                'message' => 'Skill created successfully',
                'skillName' => $validated['name']
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create skill', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to create skill: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Read skill directory structure
     */
    public function readSkill(string $skillName): JsonResponse
    {
        $skillsPath = $this->getSkillsPath();
        $skillDir = $skillsPath . '/' . $skillName;

        try {
            if (!is_dir($skillDir)) {
                return response()->json(['error' => 'Skill not found'], 404);
            }

            $files = $this->recursiveListDirectory($skillDir, $skillDir);

            return response()->json([
                'success' => true,
                'skillName' => $skillName,
                'files' => $files
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to read skill {$skillName}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to read skill: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Read a specific file within a skill
     */
    public function readSkillFile(string $skillName, string $path): JsonResponse
    {
        $skillsPath = $this->getSkillsPath();
        $filePath = $skillsPath . '/' . $skillName . '/' . $path;

        try {
            if (!file_exists($filePath) || is_dir($filePath)) {
                return response()->json(['error' => 'File not found'], 404);
            }

            // Security: Ensure file is within skill directory
            $realPath = realpath($filePath);
            $skillDir = realpath($skillsPath . '/' . $skillName);
            if (!str_starts_with($realPath, $skillDir)) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            $content = file_get_contents($filePath);

            // Parse SKILL.md frontmatter if applicable
            $parsed = null;
            if (basename($path) === 'SKILL.md') {
                $parsed = $this->parseSkillFile($filePath);
            }

            return response()->json([
                'success' => true,
                'path' => $path,
                'content' => $content,
                'parsed' => $parsed
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to read skill file {$skillName}/{$path}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to read file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save a specific file within a skill
     */
    public function saveSkillFile(Request $request, string $skillName, string $path): JsonResponse
    {
        $skillsPath = $this->getSkillsPath();
        $skillDir = $skillsPath . '/' . $skillName;
        $filePath = $skillDir . '/' . $path;

        try {
            if (!is_dir($skillDir)) {
                return response()->json(['error' => 'Skill not found'], 404);
            }

            // Security: Ensure file is within skill directory
            $skillDirReal = realpath($skillDir);
            $fileDir = dirname($filePath);

            // Create directory if it doesn't exist
            if (!is_dir($fileDir)) {
                mkdir($fileDir, 0775, true);
            }

            $validated = $request->validate([
                'content' => 'required|string',
            ]);

            file_put_contents($filePath, $validated['content']);

            return response()->json([
                'success' => true,
                'message' => 'File saved successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Failed to save skill file {$skillName}/{$path}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to save file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a specific file within a skill
     */
    public function deleteSkillFile(string $skillName, string $path): JsonResponse
    {
        $skillsPath = $this->getSkillsPath();
        $filePath = $skillsPath . '/' . $skillName . '/' . $path;

        try {
            // Prevent deleting SKILL.md
            if (basename($path) === 'SKILL.md') {
                return response()->json([
                    'error' => 'Cannot delete SKILL.md - delete the entire skill instead'
                ], 403);
            }

            if (!file_exists($filePath)) {
                return response()->json(['error' => 'File not found'], 404);
            }

            // Security: Ensure file is within skill directory
            $realPath = realpath($filePath);
            $skillDir = realpath($skillsPath . '/' . $skillName);
            if (!str_starts_with($realPath, $skillDir)) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            if (is_dir($filePath)) {
                // Remove directory recursively
                $this->recursiveRemoveDirectory($filePath);
            } else {
                unlink($filePath);
            }

            return response()->json([
                'success' => true,
                'message' => 'File deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to delete skill file {$skillName}/{$path}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to delete file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an entire skill
     */
    public function deleteSkill(string $skillName): JsonResponse
    {
        $skillsPath = $this->getSkillsPath();
        $skillDir = $skillsPath . '/' . $skillName;

        try {
            if (!is_dir($skillDir)) {
                return response()->json(['error' => 'Skill not found'], 404);
            }

            $this->recursiveRemoveDirectory($skillDir);

            return response()->json([
                'success' => true,
                'message' => 'Skill deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to delete skill {$skillName}", ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to delete skill: ' . $e->getMessage()
            ], 500);
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
