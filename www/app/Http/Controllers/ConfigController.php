<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\PocketTool;
use App\Models\Workspace;
use App\Services\NativeToolService;
use App\Services\ToolSelector;
use App\Tools\Tool;
use App\Tools\UserTool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Main index - redirect to last visited section or default to system-prompt
     */
    public function index(Request $request)
    {
        $lastSection = $request->session()->get('config_last_section', 'system-prompt');
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
            return redirect()->route('config.system-prompt')
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
            return redirect()->route('config.system-prompt')
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
            return redirect()->route('config.system-prompt')
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
    // AGENTS CRUD (Database-backed)
    // =========================================================================

    /**
     * Get available models for each provider.
     * Models are ordered from smartest/most capable to fastest/cheapest.
     *
     * No hardcoded fallbacks - if config is missing, we return an empty array
     * to surface the configuration issue rather than silently hiding it.
     * Models should always be defined in config/ai.php.
     */
    protected function getModelsForProvider(string $provider): array
    {
        return collect(config("ai.models.{$provider}", []))
            ->pluck('model_id')
            ->toArray();
    }

    /**
     * List all agents grouped by workspace
     */
    public function listAgents(Request $request)
    {
        $request->session()->put('config_last_section', 'agents');

        try {
            $workspaces = Workspace::with(['agents' => function ($query) {
                $query->orderBy('is_default', 'desc')
                    ->orderBy('name');
            }])
                ->orderBy('name')
                ->get();

            return view('config.agents.index', [
                'workspaces' => $workspaces,
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

        $viewData = [
            'providers' => Agent::getProviders(),
            'modelsPerProvider' => [
                Agent::PROVIDER_ANTHROPIC => $this->getModelsForProvider(Agent::PROVIDER_ANTHROPIC),
                Agent::PROVIDER_OPENAI => $this->getModelsForProvider(Agent::PROVIDER_OPENAI),
                Agent::PROVIDER_CLAUDE_CODE => $this->getModelsForProvider(Agent::PROVIDER_CLAUDE_CODE),
                Agent::PROVIDER_CODEX => $this->getModelsForProvider(Agent::PROVIDER_CODEX),
            ],
            'workspaces' => Workspace::orderBy('name')->get(),
            'selectedWorkspaceId' => $request->query('workspace_id'),
            'sourceAgent' => null,
            'cloneWarnings' => null,
        ];

        // Handle "Create from" (cloning an existing agent)
        if ($request->has('from')) {
            $sourceAgent = Agent::with('memoryDatabases')->find($request->query('from'));
            if ($sourceAgent) {
                $viewData['sourceAgent'] = $sourceAgent;

                // Calculate missing resources if cloning to a different workspace
                // Only warn if agent has specific tools/schemas selected (not inheriting from workspace)
                $targetWorkspaceId = $request->query('workspace_id');
                if ($targetWorkspaceId && $targetWorkspaceId !== $sourceAgent->workspace_id) {
                    $targetWorkspace = Workspace::find($targetWorkspaceId);
                    if ($targetWorkspace) {
                        $missingTools = [];
                        $missingSchemas = [];

                        // Check tools only if agent has specific tools selected (not inheriting)
                        // If inheriting, the cloned agent will just inherit from the new workspace
                        if (!$sourceAgent->inheritsWorkspaceTools() && $sourceAgent->allowed_tools !== null && is_array($sourceAgent->allowed_tools)) {
                            $disabledToolSlugs = $targetWorkspace->getDisabledToolSlugs();
                            foreach ($sourceAgent->allowed_tools as $toolSlug) {
                                if (in_array($toolSlug, $disabledToolSlugs)) {
                                    $missingTools[] = $toolSlug;
                                }
                            }
                        }

                        // Check memory schemas only if agent has specific schemas selected (not inheriting)
                        if (!$sourceAgent->inheritsWorkspaceSchemas()) {
                            $enabledSchemaIds = $targetWorkspace->enabledMemoryDatabases()
                                ->pluck('memory_databases.id')
                                ->toArray();

                            foreach ($sourceAgent->memoryDatabases as $schema) {
                                if (!in_array($schema->id, $enabledSchemaIds)) {
                                    $missingSchemas[] = [
                                        'name' => $schema->name,
                                        'schema_name' => $schema->schema_name,
                                    ];
                                }
                            }
                        }

                        if (!empty($missingTools) || !empty($missingSchemas)) {
                            $viewData['cloneWarnings'] = [
                                'missing_tools' => $missingTools,
                                'missing_schemas' => $missingSchemas,
                                'source_workspace' => $sourceAgent->workspace?->name ?? 'Unknown',
                            ];
                        }
                    }
                }
            }
        }

        return view('config.agents.form', $viewData);
    }

    /**
     * Store new agent
     */
    public function storeAgent(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1024',
                'workspace_id' => 'required|uuid|exists:workspaces,id',
                'provider' => 'required|string|in:' . implode(',', Agent::getProviders()),
                'model' => 'required|string|max:100',
                'anthropic_thinking_budget' => 'nullable|integer|min:0',
                'openai_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
                'claude_code_thinking_tokens' => 'nullable|integer|min:0',
                'codex_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
                'response_level' => 'nullable|integer|min:1|max:5',
                'inherit_workspace_tools' => 'nullable|in:0,1',
                'inherit_workspace_schemas' => 'nullable|in:0,1',
                'allowed_tools' => 'nullable|array',
                'allowed_tools.*' => 'string',
                'memory_schemas' => 'nullable|array',
                'memory_schemas.*' => 'uuid|exists:memory_databases,id',
                'system_prompt' => 'nullable|string',
                'is_default' => 'nullable|boolean',
                'enabled' => 'nullable|boolean',
            ]);

            // Check for duplicate slug
            $slug = \Illuminate\Support\Str::slug($validated['name']);
            if (Agent::where('slug', $slug)->exists()) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'An agent with this name already exists');
            }

            // Parse inherit settings
            $inheritWorkspaceTools = ($validated['inherit_workspace_tools'] ?? '1') === '1';
            $inheritWorkspaceSchemas = ($validated['inherit_workspace_schemas'] ?? '0') === '1';

            // Normalize allowed_tools to lowercase for consistent matching (only if not inheriting)
            // When inheriting: null means "inherit from workspace"
            // When not inheriting: null means "all tools" (legacy), empty array means "no tools"
            $allowedTools = null;
            if (!$inheritWorkspaceTools) {
                $allowedTools = isset($validated['allowed_tools'])
                    ? array_map('mb_strtolower', $validated['allowed_tools'])
                    : []; // Empty array = no tools selected
            }

            // Use transaction to ensure agent and schema associations are created atomically
            DB::transaction(function () use ($validated, $inheritWorkspaceTools, $inheritWorkspaceSchemas, $allowedTools) {
                $agent = Agent::create([
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'workspace_id' => $validated['workspace_id'],
                    'provider' => $validated['provider'],
                    'model' => $validated['model'],
                    'anthropic_thinking_budget' => $validated['anthropic_thinking_budget'] ?? null,
                    'openai_reasoning_effort' => $validated['openai_reasoning_effort'] ?? null,
                    'claude_code_thinking_tokens' => $validated['claude_code_thinking_tokens'] ?? null,
                    'codex_reasoning_effort' => $validated['codex_reasoning_effort'] ?? null,
                    'response_level' => $validated['response_level'] ?? 1,
                    'inherit_workspace_tools' => $inheritWorkspaceTools,
                    'inherit_workspace_schemas' => $inheritWorkspaceSchemas,
                    'allowed_tools' => $allowedTools,
                    'system_prompt' => $validated['system_prompt'] ?? null,
                    'is_default' => $validated['is_default'] ?? false,
                    'enabled' => $validated['enabled'] ?? true,
                ]);

                // Sync memory schemas (only if not inheriting)
                if (!$inheritWorkspaceSchemas) {
                    $memorySchemaIds = $validated['memory_schemas'] ?? [];
                    $agent->memoryDatabases()->sync($memorySchemaIds);
                }
            });

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
    public function editAgentForm(Request $request, Agent $agent)
    {
        $request->session()->put('config_last_section', 'agents');

        return view('config.agents.form', [
            'agent' => $agent,
            'providers' => Agent::getProviders(),
            'modelsPerProvider' => [
                Agent::PROVIDER_ANTHROPIC => $this->getModelsForProvider(Agent::PROVIDER_ANTHROPIC),
                Agent::PROVIDER_OPENAI => $this->getModelsForProvider(Agent::PROVIDER_OPENAI),
                Agent::PROVIDER_CLAUDE_CODE => $this->getModelsForProvider(Agent::PROVIDER_CLAUDE_CODE),
                Agent::PROVIDER_CODEX => $this->getModelsForProvider(Agent::PROVIDER_CODEX),
            ],
        ]);
    }

    /**
     * Update agent
     */
    public function updateAgent(Request $request, Agent $agent)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1024',
                'provider' => 'required|string|in:' . implode(',', Agent::getProviders()),
                'model' => 'required|string|max:100',
                'anthropic_thinking_budget' => 'nullable|integer|min:0',
                'openai_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
                'claude_code_thinking_tokens' => 'nullable|integer|min:0',
                'codex_reasoning_effort' => 'nullable|string|in:none,low,medium,high',
                'response_level' => 'nullable|integer|min:1|max:5',
                'inherit_workspace_tools' => 'nullable|in:0,1',
                'inherit_workspace_schemas' => 'nullable|in:0,1',
                'allowed_tools' => 'nullable|array',
                'allowed_tools.*' => 'string',
                'memory_schemas' => 'nullable|array',
                'memory_schemas.*' => 'uuid|exists:memory_databases,id',
                'system_prompt' => 'nullable|string',
                'is_default' => 'nullable|boolean',
                'enabled' => 'nullable|boolean',
            ]);

            // Check for duplicate slug (excluding current agent)
            $slug = \Illuminate\Support\Str::slug($validated['name']);
            if (Agent::where('slug', $slug)->where('id', '!=', $agent->id)->exists()) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'An agent with this name already exists');
            }

            // Parse inherit settings
            $inheritWorkspaceTools = ($validated['inherit_workspace_tools'] ?? '1') === '1';
            $inheritWorkspaceSchemas = ($validated['inherit_workspace_schemas'] ?? '0') === '1';

            // Normalize allowed_tools to lowercase for consistent matching (only if not inheriting)
            // When inheriting: null means "inherit from workspace"
            // When not inheriting: null means "all tools" (legacy), empty array means "no tools"
            $allowedTools = null;
            if (!$inheritWorkspaceTools) {
                $allowedTools = isset($validated['allowed_tools'])
                    ? array_map('mb_strtolower', $validated['allowed_tools'])
                    : []; // Empty array = no tools selected
            }

            // Use transaction to ensure agent update and schema associations are updated atomically
            DB::transaction(function () use ($agent, $validated, $slug, $inheritWorkspaceTools, $inheritWorkspaceSchemas, $allowedTools) {
                $agent->update([
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'description' => $validated['description'] ?? null,
                    'provider' => $validated['provider'],
                    'model' => $validated['model'],
                    'anthropic_thinking_budget' => $validated['anthropic_thinking_budget'] ?? null,
                    'openai_reasoning_effort' => $validated['openai_reasoning_effort'] ?? null,
                    'claude_code_thinking_tokens' => $validated['claude_code_thinking_tokens'] ?? null,
                    'codex_reasoning_effort' => $validated['codex_reasoning_effort'] ?? null,
                    'response_level' => $validated['response_level'] ?? 1,
                    'inherit_workspace_tools' => $inheritWorkspaceTools,
                    'inherit_workspace_schemas' => $inheritWorkspaceSchemas,
                    'allowed_tools' => $allowedTools,
                    'system_prompt' => $validated['system_prompt'] ?? null,
                    'is_default' => $validated['is_default'] ?? false,
                    'enabled' => $validated['enabled'] ?? true,
                ]);

                // Sync memory schemas (only if not inheriting)
                if (!$inheritWorkspaceSchemas) {
                    $memorySchemaIds = $validated['memory_schemas'] ?? [];
                    $agent->memoryDatabases()->sync($memorySchemaIds);
                } else {
                    // Clear specific schema assignments when inheriting
                    $agent->memoryDatabases()->detach();
                }
            });

            return redirect()->route('config.agents')
                ->with('success', 'Agent saved successfully');
        } catch (\Exception $e) {
            Log::error("Failed to save agent {$agent->id}", ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to save agent: ' . $e->getMessage());
        }
    }

    /**
     * Delete agent
     */
    public function deleteAgent(Agent $agent)
    {
        try {
            $agent->delete();

            return redirect()->route('config.agents')
                ->with('success', 'Agent deleted successfully');
        } catch (\Exception $e) {
            Log::error("Failed to delete agent {$agent->id}", ['error' => $e->getMessage()]);
            return redirect()->route('config.agents')
                ->with('error', 'Failed to delete agent: ' . $e->getMessage());
        }
    }

    /**
     * Toggle agent default status
     */
    public function toggleAgentDefault(Agent $agent)
    {
        try {
            $agent->update(['is_default' => !$agent->is_default]);

            return redirect()->route('config.agents')
                ->with('success', $agent->is_default
                    ? "'{$agent->name}' is now the default for {$agent->getProviderDisplayName()}"
                    : "'{$agent->name}' is no longer a default agent");
        } catch (\Exception $e) {
            Log::error("Failed to toggle default for agent {$agent->id}", ['error' => $e->getMessage()]);
            return redirect()->route('config.agents')
                ->with('error', 'Failed to update agent: ' . $e->getMessage());
        }
    }

    /**
     * Toggle agent enabled status
     */
    public function toggleAgentEnabled(Agent $agent)
    {
        try {
            $agent->update(['enabled' => !$agent->enabled]);

            return redirect()->route('config.agents')
                ->with('success', $agent->enabled
                    ? "'{$agent->name}' is now enabled"
                    : "'{$agent->name}' is now disabled");
        } catch (\Exception $e) {
            Log::error("Failed to toggle enabled for agent {$agent->id}", ['error' => $e->getMessage()]);
            return redirect()->route('config.agents')
                ->with('error', 'Failed to update agent: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // HOOKS MANAGEMENT
    // =========================================================================

    protected function getSettingsPath(): string
    {
        return '/home/appuser/.claude/settings.json';
    }

    /**
     * Show hooks editor (~/.claude/settings.json)
     */
    public function showHooks(Request $request)
    {
        $request->session()->put('config_last_section', 'hooks');

        try {
            $path = $this->getSettingsPath();

            if (file_exists($path)) {
                $content = file_get_contents($path);
                // Re-encode to ensure pretty formatting
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $content = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }
            } else {
                // Default content with .env protection
                $content = json_encode(
                    ['permissions' => ['deny' => ['Read(**/.env)']]],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                );
            }

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
     * Save hooks (~/.claude/settings.json)
     */
    public function saveHooks(Request $request)
    {
        try {
            $validated = $request->validate([
                'content' => 'required|json',
            ]);

            $path = $this->getSettingsPath();

            // Validate it's valid JSON (object or array)
            $decoded = json_decode($validated['content'], true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Content must be valid JSON');
            }

            // Write with pretty formatting
            $result = file_put_contents(
                $path,
                json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            if ($result === false) {
                throw new \RuntimeException('Failed to write settings file');
            }

            return redirect()->back()->with('success', 'Hooks saved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to save hooks', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to save hooks: ' . $e->getMessage());
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

    // =========================================================================
    // TOOLS MANAGEMENT
    // =========================================================================

    /**
     * Read native tools configuration with database overrides.
     *
     * The static config defines all available tools with their default enabled state.
     * Database records in tool_settings override these defaults at runtime.
     *
     * @see \App\Services\NativeToolService for the centralized implementation
     */
    protected function getNativeToolsConfig(): array
    {
        return app(NativeToolService::class)->getAllConfig();
    }

    /**
     * Update native tool enabled/disabled state in the database.
     */
    protected function updateNativeToolsConfig(string $provider, string $toolName, bool $enabled): void
    {
        // Validate provider and tool exist in static config
        $config = config('native_tools', []);

        if (!isset($config[$provider]['tools'])) {
            throw new \RuntimeException("Provider '{$provider}' not found in native tools config");
        }

        $found = false;
        foreach ($config[$provider]['tools'] as $tool) {
            if ($tool['name'] === $toolName) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new \RuntimeException("Tool '{$toolName}' not found for provider '{$provider}'");
        }

        // Upsert the setting in the database
        // Use closure pattern to set created_at only on insert
        DB::table('tool_settings')->updateOrInsert(
            ['provider' => $provider, 'tool_name' => $toolName],
            fn ($exists) => $exists
                ? ['enabled' => $enabled, 'updated_at' => now()]
                : ['enabled' => $enabled, 'created_at' => now(), 'updated_at' => now()]
        );

        // Clear the NativeToolService cache so changes take effect immediately
        app(NativeToolService::class)->clearCache();
    }

    /**
     * Get PocketDev tools from the ToolSelector/ToolRegistry.
     */
    protected function getPocketdevTools(): array
    {
        $toolSelector = app(ToolSelector::class);

        // Get all tools grouped by category
        $fileOps = $toolSelector->getFileOperationTools();
        $memory = $toolSelector->getMemoryTools();
        $toolMgmt = $toolSelector->getToolManagementTools();

        return [
            'file_ops' => $fileOps,
            'memory' => $memory,
            'tools' => $toolMgmt,
        ];
    }

    /**
     * List all tools (native, pocketdev, custom)
     */
    public function listTools(Request $request)
    {
        $request->session()->put('config_last_section', 'tools');

        try {
            // Get native tools from config (Claude Code, Codex)
            $nativeConfig = $this->getNativeToolsConfig();

            // Get PocketDev tools from ToolRegistry via ToolSelector
            $pocketdevTools = $this->getPocketdevTools();

            // File ops tools are the "native equivalent" tools
            $nativePocketdevTools = $pocketdevTools['file_ops']->map(fn(Tool $tool) => [
                'slug' => $tool->getSlug(),
                'name' => $tool->name,
                'description' => $tool->description,
                'category' => $tool->category,
            ]);

            // Memory and tool management tools are the "unique" tools
            $uniquePocketdevTools = collect()
                ->merge($pocketdevTools['memory']->map(fn(Tool $tool) => [
                    'slug' => $tool->getSlug(),
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'category' => $tool->category,
                ]))
                ->merge($pocketdevTools['tools']->map(fn(Tool $tool) => [
                    'slug' => $tool->getSlug(),
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'category' => $tool->category,
                ]));

            // Group unique pocketdev tools by category
            $pocketdevByCategory = $uniquePocketdevTools->groupBy('category');

            // Get custom/user tools from ToolSelector
            // Use objects for view compatibility (the blade uses $tool->name syntax)
            $toolSelector = app(ToolSelector::class);
            $customTools = $toolSelector->getUserTools()->map(fn(Tool $tool) => (object) [
                'slug' => $tool->getSlug(),
                'name' => $tool->name,
                'description' => $tool->description,
                'category' => $tool->category,
            ]);

            // Group custom tools by category
            $customByCategory = $customTools->groupBy('category');

            return view('config.tools.index', [
                'nativeConfig' => $nativeConfig,
                'nativePocketdevTools' => $nativePocketdevTools,
                'pocketdevByCategory' => $pocketdevByCategory,
                'customByCategory' => $customByCategory,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list tools', ['error' => $e->getMessage()]);
            return redirect()->route('config.index')
                ->with('error', 'Failed to list tools: ' . $e->getMessage());
        }
    }

    /**
     * Show tool details
     */
    public function showTool(Request $request, string $slug)
    {
        $request->session()->put('config_last_section', 'tools');

        try {
            // First check if it's a PocketDev tool from the registry
            $tool = $this->findPocketdevToolFromRegistry($slug);

            // If not found in registry, check database for user tools
            if (!$tool) {
                $tool = PocketTool::where('slug', $slug)->firstOrFail();
            }

            return view('config.tools.show', [
                'tool' => $tool,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to show tool {$slug}", ['error' => $e->getMessage()]);
            return redirect()->route('config.tools')
                ->with('error', 'Tool not found');
        }
    }

    /**
     * Find a PocketDev tool from the ToolRegistry by slug.
     * Returns a PocketTool-like object for view compatibility.
     */
    protected function findPocketdevToolFromRegistry(string $slug): ?object
    {
        $toolSelector = app(ToolSelector::class);

        foreach ($toolSelector->getAllTools() as $tool) {
            if ($tool->getSlug() === $slug && !($tool instanceof UserTool)) {
                // Create an anonymous class with methods for view compatibility
                return new class($tool) {
                    public string $slug;
                    public string $name;
                    public string $description;
                    public ?string $category;
                    public string $source;
                    public ?string $system_prompt;
                    public ?array $input_schema;
                    public ?string $script = null;
                    public bool $enabled = true;
                    private ?string $artisan_command;

                    public function __construct(Tool $tool)
                    {
                        $this->slug = $tool->getSlug();
                        $this->name = $tool->name;
                        $this->description = $tool->description;
                        $this->category = $tool->category;
                        $this->source = PocketTool::SOURCE_POCKETDEV;
                        $this->system_prompt = $tool->instructions;
                        $this->input_schema = $tool->inputSchema;
                        $this->artisan_command = $tool->getArtisanCommand();
                    }

                    public function isPocketdev(): bool
                    {
                        return true;
                    }

                    public function isUserTool(): bool
                    {
                        return false;
                    }

                    public function getArtisanCommand(): ?string
                    {
                        return $this->artisan_command;
                    }
                };
            }
        }

        return null;
    }

    /**
     * Show create custom tool form
     */
    public function createToolForm(Request $request)
    {
        $request->session()->put('config_last_section', 'tools');

        return view('config.tools.form', [
            'categories' => PocketTool::getCategories(),
        ]);
    }

    /**
     * Store new custom tool
     */
    public function storeTool(Request $request)
    {
        try {
            $validated = $request->validate([
                'slug' => 'required|string|regex:/^[a-z0-9-]+$/|max:64|unique:pocket_tools,slug',
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:1024',
                'category' => 'nullable|string|max:64',
                'input_schema' => 'nullable|json',
            ]);

            PocketTool::create([
                'slug' => $validated['slug'],
                'name' => $validated['name'],
                'description' => $validated['description'],
                'source' => PocketTool::SOURCE_USER,
                'category' => $validated['category'] ?: PocketTool::CATEGORY_CUSTOM,
                'input_schema' => $validated['input_schema'] ? json_decode($validated['input_schema'], true) : null,
                'enabled' => true,
            ]);

            return redirect()->route('config.tools')
                ->with('success', 'Tool created successfully');
        } catch (\Exception $e) {
            Log::error('Failed to create tool', ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to create tool: ' . $e->getMessage());
        }
    }

    /**
     * Show edit custom tool form
     */
    public function editToolForm(Request $request, string $slug)
    {
        $request->session()->put('config_last_section', 'tools');

        try {
            $tool = PocketTool::where('slug', $slug)->firstOrFail();

            // Only allow editing user tools
            if (!$tool->isUserTool()) {
                return redirect()->route('config.tools.show', $slug)
                    ->with('error', 'Only custom tools can be edited');
            }

            return view('config.tools.form', [
                'tool' => $tool,
                'categories' => PocketTool::getCategories(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to load tool {$slug}", ['error' => $e->getMessage()]);
            return redirect()->route('config.tools')
                ->with('error', 'Tool not found');
        }
    }

    /**
     * Update custom tool (metadata only - script changes via AI)
     */
    public function updateTool(Request $request, string $slug)
    {
        try {
            $tool = PocketTool::where('slug', $slug)->firstOrFail();

            // Only allow editing user tools
            if (!$tool->isUserTool()) {
                return redirect()->route('config.tools')
                    ->with('error', 'Only custom tools can be edited');
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:1024',
                'category' => 'nullable|string|max:64',
                'input_schema' => 'nullable|json',
            ]);

            $tool->update([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'category' => $validated['category'] ?: PocketTool::CATEGORY_CUSTOM,
                'input_schema' => $validated['input_schema'] ? json_decode($validated['input_schema'], true) : null,
            ]);

            return redirect()->route('config.tools')
                ->with('success', 'Tool updated successfully');
        } catch (\Exception $e) {
            Log::error("Failed to update tool {$slug}", ['error' => $e->getMessage()]);
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to update tool: ' . $e->getMessage());
        }
    }

    /**
     * Delete custom tool
     */
    public function deleteTool(string $slug)
    {
        try {
            $tool = PocketTool::where('slug', $slug)->firstOrFail();

            // Only allow deleting user tools
            if (!$tool->isUserTool()) {
                return redirect()->route('config.tools')
                    ->with('error', 'Only custom tools can be deleted');
            }

            $tool->delete();

            return redirect()->route('config.tools')
                ->with('success', 'Tool deleted successfully');
        } catch (\Exception $e) {
            Log::error("Failed to delete tool {$slug}", ['error' => $e->getMessage()]);
            return redirect()->route('config.tools')
                ->with('error', 'Failed to delete tool: ' . $e->getMessage());
        }
    }

    /**
     * Toggle native tool enabled/disabled (AJAX)
     */
    public function toggleNativeTool(Request $request)
    {
        try {
            $validated = $request->validate([
                'provider' => 'required|string|in:claude_code,codex',
                'tool' => 'required|string|max:64',
                'enabled' => 'required|boolean',
            ]);

            $this->updateNativeToolsConfig(
                $validated['provider'],
                $validated['tool'],
                $validated['enabled']
            );

            return response()->json([
                'success' => true,
                'message' => $validated['enabled'] ? 'Tool enabled' : 'Tool disabled',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle native tool', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle tool: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // DEVELOPER TOOLS (Local Environment Only)
    // =========================================================================

    /**
     * Show developer tools page
     */
    public function showDeveloper(Request $request)
    {
        $request->session()->put('config_last_section', 'developer');

        // Check for any conversations currently processing
        $processingCount = \App\Models\Conversation::where('status', \App\Models\Conversation::STATUS_PROCESSING)->count();

        return view('config.developer', [
            'processingCount' => $processingCount,
        ]);
    }

    /**
     * Force recreate all Docker containers
     */
    public function forceRecreate(Request $request)
    {
        try {
            $hostProjectPath = env('HOST_PROJECT_PATH');

            if (empty($hostProjectPath)) {
                throw new \RuntimeException('HOST_PROJECT_PATH environment variable is not set');
            }

            // Spawn a helper container that survives container restarts
            $command = sprintf(
                'docker run --rm -d ' .
                '-v /var/run/docker.sock:/var/run/docker.sock ' .
                '-v "%s:%s" ' .
                '-w "%s" ' .
                'docker:27-cli ' .
                'docker compose up -d --force-recreate 2>&1',
                $hostProjectPath,
                $hostProjectPath,
                $hostProjectPath
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException('Failed to spawn helper container: ' . implode("\n", $output));
            }

            return redirect()->back()->with('success', 'Force recreate initiated. Containers will restart shortly.');
        } catch (\Exception $e) {
            Log::error('Failed to force recreate containers', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to force recreate: ' . $e->getMessage());
        }
    }

    /**
     * Rebuild all Docker containers (down + build + up)
     * Use this when Dockerfiles or entrypoint scripts have changed.
     */
    public function rebuildContainers(Request $request)
    {
        try {
            $hostProjectPath = env('HOST_PROJECT_PATH');

            if (empty($hostProjectPath)) {
                throw new \RuntimeException('HOST_PROJECT_PATH environment variable is not set');
            }

            // Spawn a helper container that survives container restarts
            // Uses sh -c to chain commands: down (without -v to preserve data) then up with build
            $command = sprintf(
                'docker run --rm -d ' .
                '-v /var/run/docker.sock:/var/run/docker.sock ' .
                '-v "%s:%s" ' .
                '-w "%s" ' .
                'docker:27-cli ' .
                'sh -c "docker compose down && docker compose build --no-cache && docker compose up -d --force-recreate" 2>&1',
                $hostProjectPath,
                $hostProjectPath,
                $hostProjectPath
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException('Failed to spawn helper container: ' . implode("\n", $output));
            }

            return redirect()->back()->with('success', 'Rebuild initiated. Containers will be rebuilt and restarted shortly (this takes longer than a normal restart).');
        } catch (\Exception $e) {
            Log::error('Failed to rebuild containers', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to rebuild: ' . $e->getMessage());
        }
    }
}
