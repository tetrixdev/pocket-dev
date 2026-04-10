<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\PocketTool;
use App\Models\Workspace;
use App\Tools\AgentTool;
use App\Tools\ExecutionContext;
use App\Tools\Tool;
use App\Tools\ToolResult;
use App\Tools\UserTool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registry for available tools.
 * Manages tool registration, lookup, and execution.
 *
 * Built-in tools (from app/Tools) are cached in the singleton.
 * User tools (from database) are always fetched fresh to ensure
 * newly created tools are available without restarting the queue worker.
 *
 * Agent tools (agents with expose_as_tool = true) are always fetched fresh
 * and injected per-caller based on the caller agent's can_call_subagents
 * and allowed_subagents settings.
 *
 * Tool filtering follows a three-tier hierarchy:
 * 1. Global enablement (PocketTool.enabled) - tool exists and is globally enabled
 * 2. Workspace enablement (WorkspaceTool) - tool is enabled for the workspace (no entry = enabled)
 * 3. Agent allowed tools - agent's allowed_tools list (optional per-agent filtering)
 */
class ToolRegistry
{
    /** @var array<string, Tool> Built-in tools (cached) */
    private array $builtInTools = [];

    /**
     * Register a built-in tool.
     */
    public function register(Tool $tool): void
    {
        $this->builtInTools[$tool->name] = $tool;
    }

    /**
     * Get fresh user tools from the database.
     *
     * @return array<string, Tool>
     */
    private function getUserTools(): array
    {
        $userTools = [];

        try {
            $pocketTools = PocketTool::user()->enabled()->get();

            foreach ($pocketTools as $pocketTool) {
                $userTool = new UserTool($pocketTool);
                $userTools[$userTool->name] = $userTool;
            }
        } catch (\Throwable $e) {
            // Database might not be available during some scenarios
            Log::debug('ToolRegistry: Could not load user tools', [
                'error' => $e->getMessage(),
            ]);
        }

        return $userTools;
    }

    /**
     * Get AgentTools for agents that are opted-in (expose_as_tool = true)
     * and permitted for the given caller.
     *
     * Rules:
     * - If no caller: inject all opted-in agents across all workspaces (e.g. CLI context)
     * - If caller has can_call_subagents = false: inject nothing
     * - If caller has allowed_subagents (non-null array): inject only those agent IDs
     * - Otherwise: inject all opted-in agents in the same workspace as the caller
     *
     * @return array<string, AgentTool>
     */
    private function getAgentTools(?Agent $callerAgent = null): array
    {
        $agentTools = [];

        try {
            // Caller has explicitly disabled sub-agent calling
            if ($callerAgent !== null && ! $callerAgent->can_call_subagents) {
                return [];
            }

            $query = Agent::query()
                ->where('enabled', true)
                ->where('expose_as_tool', true);

            if ($callerAgent !== null) {
                // Scope to caller's workspace
                $query->where('workspace_id', $callerAgent->workspace_id);

                // Apply allowlist if set
                $allowlist = $callerAgent->allowed_subagents;
                if (!empty($allowlist)) {
                    $query->whereIn('id', $allowlist);
                }

                // Never inject the caller itself as its own sub-agent tool
                $query->where('id', '!=', $callerAgent->id);
            }

            $agents = $query->get();

            foreach ($agents as $agent) {
                $tool = new AgentTool($agent);
                $agentTools[$tool->name] = $tool;
            }
        } catch (\Throwable $e) {
            Log::debug('ToolRegistry: Could not load agent tools', [
                'error' => $e->getMessage(),
            ]);
        }

        return $agentTools;
    }

    /**
     * Get a tool by name.
     * Checks built-in tools first, then user tools.
     * Does not include agent tools (use execute() which handles agent_* prefix).
     */
    public function get(string $name): ?Tool
    {
        // Check built-in tools first (cached)
        if (isset($this->builtInTools[$name])) {
            return $this->builtInTools[$name];
        }

        // Check user tools (fresh from DB)
        $userTools = $this->getUserTools();
        return $userTools[$name] ?? null;
    }

    /**
     * Check if a tool exists.
     */
    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Get all registered tools (built-in + fresh user tools).
     * Does not include agent tools (context-dependent).
     *
     * @return array<string, Tool>
     */
    public function all(): array
    {
        return array_merge($this->builtInTools, $this->getUserTools());
    }

    /**
     * Get all tools enabled for a workspace.
     *
     * Filtering:
     * 1. Tool must be globally enabled (PocketTool.enabled = true or built-in)
     * 2. Tool must be enabled in workspace (no WorkspaceTool entry = enabled, or entry with enabled = true)
     *
     * @param Workspace $workspace The workspace to filter by
     * @return array<string, Tool>
     */
    public function allForWorkspace(Workspace $workspace): array
    {
        $allTools = $this->all();
        $disabledSlugs = $workspace->getDisabledToolSlugs();

        return array_filter($allTools, function (Tool $tool) use ($disabledSlugs) {
            // Check workspace enablement (no entry = enabled by default)
            $slug = $tool->getSlug();
            if (in_array($slug, $disabledSlugs, true)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Get a tool by name, optionally filtered by workspace.
     * Returns null if tool doesn't exist or is disabled in the workspace.
     */
    public function getForWorkspace(string $name, Workspace $workspace): ?Tool
    {
        $tool = $this->get($name);

        if ($tool === null) {
            return null;
        }

        // Check workspace enablement
        if (!$workspace->isToolEnabled($tool->getSlug())) {
            return null;
        }

        return $tool;
    }

    /**
     * Get tool definitions for the API.
     *
     * Merges built-in tools, user tools, and per-caller agent tools.
     * Agent tools are filtered based on the caller's sub-agent settings.
     *
     * @param Agent|null $callerAgent The agent making the request (null = no per-agent filtering)
     * @return array<array{name: string, description: string, input_schema: array}>
     */
    public function getDefinitions(?Agent $callerAgent = null): array
    {
        $tools = array_merge($this->all(), $this->getAgentTools($callerAgent));

        return array_map(
            fn(Tool $tool) => $tool->toDefinition(),
            array_values($tools)
        );
    }

    /**
     * Get combined instructions from all tools.
     * Only includes tools that have instructions.
     *
     * @param array|null $allowedTools Tool slugs to allow (null = all, [] = none, [...] = specific)
     */
    public function getInstructions(?array $allowedTools = null): string
    {
        $instructions = [];

        foreach ($this->all() as $tool) {
            if ($tool->instructions === null) {
                continue;
            }

            // Filter by allowed tools if specified (case-insensitive)
            if (!ToolFilterHelper::isAllowed($tool->getSlug(), $allowedTools)) {
                continue;
            }

            $instructions[] = "## {$tool->name} Tool\n\n{$tool->instructions}";
        }

        return implode("\n\n", $instructions);
    }

    /**
     * Execute a tool by name.
     *
     * Handles the agent_* prefix for native per-agent tool calls.
     * Agent resolution is scoped to the context workspace for security.
     */
    public function execute(string $name, array $input, ExecutionContext $context): ToolResult
    {
        // Handle native agent tool calls (agent_<slug> prefix)
        if (Str::startsWith($name, 'agent_')) {
            return $this->executeAgentTool($name, $input, $context);
        }

        $tool = $this->get($name);

        if ($tool === null) {
            return ToolResult::error("Unknown tool: {$name}");
        }

        try {
            return $tool->execute($input, $context);
        } catch (\Throwable $e) {
            return ToolResult::error("Tool execution failed: {$e->getMessage()}");
        }
    }

    /**
     * Dispatch an agent_* tool call.
     *
     * Resolves the target agent by slug, verifies it has expose_as_tool = true,
     * and checks that the caller's sub-agent settings permit the call.
     */
    private function executeAgentTool(string $name, array $input, ExecutionContext $context): ToolResult
    {
        $slug = Str::after($name, 'agent_');
        $workspace = $context->getWorkspace();
        $callerAgent = $context->getAgent();

        // Security: caller must have sub-agent calling enabled
        if ($callerAgent !== null && ! $callerAgent->can_call_subagents) {
            return ToolResult::error("This agent is not permitted to call other agents.");
        }

        $query = Agent::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->where('expose_as_tool', true);

        if ($workspace) {
            $query->where('workspace_id', $workspace->id);
        }

        $agent = $query->first();

        if (!$agent) {
            return ToolResult::error("Agent tool '{$name}' not found or not available. The agent may not exist, be disabled, or not have expose_as_tool enabled.");
        }

        // Check allowlist
        if ($callerAgent !== null) {
            $allowlist = $callerAgent->allowed_subagents;
            if (!empty($allowlist) && !in_array($agent->id, $allowlist, true)) {
                return ToolResult::error("Agent '{$agent->name}' is not in this agent's allowed sub-agents list.");
            }

            // Prevent self-call
            if ($callerAgent->id === $agent->id) {
                return ToolResult::error("An agent cannot call itself as a sub-agent.");
            }
        }

        try {
            $agentTool = new AgentTool($agent);
            return $agentTool->execute($input, $context);
        } catch (\Throwable $e) {
            return ToolResult::error("Agent tool execution failed: {$e->getMessage()}");
        }
    }

    /**
     * Get the count of registered tools.
     */
    public function count(): int
    {
        return count($this->all());
    }
}
