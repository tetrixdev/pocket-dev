<?php

namespace App\Tools;

use App\Models\Agent;
use App\Services\SubAgentRunner;

/**
 * A per-agent tool wrapper that exposes a PocketDev agent as a native tool call.
 *
 * Only created for agents with expose_as_tool = true. Each instance wraps one
 * agent and generates a dedicated tool definition named `agent_<slug>`.
 *
 * The AI calls it like any other tool — no slug lookup, no generic SubAgent
 * dispatch. This follows Anthropic's recommended "agents as tools" pattern.
 */
class AgentTool extends Tool
{
    public string $category = 'agents';

    public function __construct(private readonly Agent $agent)
    {
        $this->name = 'agent_' . $agent->slug;

        $providerLabel = match ($agent->provider) {
            'claude_code' => 'Claude Code',
            'codex'       => 'Codex',
            'anthropic'   => 'Anthropic',
            'openai'      => 'OpenAI',
            default       => ucfirst($agent->provider),
        };

        $this->description = $agent->description
            ?? "Delegate to the {$agent->name} agent ({$providerLabel} / {$agent->model}).";

        $this->inputSchema = [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => "Task for {$agent->name}. Be self-contained — the agent has no context from your conversation.",
                ],
                'background' => [
                    'type' => 'boolean',
                    'description' => 'Run in background and return task_id immediately. Poll with SubAgentOutput. Default: false.',
                ],
                'conversation_id' => [
                    'type' => 'string',
                    'description' => 'Optional: UUID of a previous conversation with this agent to resume. When provided, prompt is appended as the next user message.',
                ],
            ],
            'required' => ['prompt'],
        ];
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $prompt = trim($input['prompt'] ?? '');
        $isBackground = (bool) ($input['background'] ?? false);
        $conversationId = isset($input['conversation_id']) ? trim($input['conversation_id']) : null;

        if (empty($prompt)) {
            return ToolResult::error('prompt is required');
        }

        return app(SubAgentRunner::class)->run(
            $this->agent,
            $prompt,
            $isBackground,
            $context,
            $conversationId ?: null,
        );
    }

    /**
     * Get the underlying agent model.
     */
    public function getAgent(): Agent
    {
        return $this->agent;
    }
}
