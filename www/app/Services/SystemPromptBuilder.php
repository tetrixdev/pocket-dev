<?php

namespace App\Services;

use App\Enums\Provider;
use App\Models\Conversation;

/**
 * Builds the system prompt for AI providers.
 *
 * System prompt structure (in order):
 * 1. Core system prompt (from resources/defaults or storage override)
 * 2. Additional system prompt (generic, from config or storage override)
 * 3. Agent-specific system prompt (from conversation's agent, if any)
 * 4. Tool instructions (dynamically generated from available tools)
 * 5. Working directory context
 */
class SystemPromptBuilder
{
    public function __construct(
        private SystemPromptService $systemPromptService,
        private ToolSelector $toolSelector
    ) {}

    /**
     * Build the system prompt for a conversation.
     */
    public function build(Conversation $conversation, ToolRegistry $toolRegistry): string
    {
        $sections = [];

        // 1 & 2: Core + Additional system prompt (customizable via settings)
        $sections[] = $this->systemPromptService->get();

        // 3: Agent-specific system prompt (if conversation has an agent with custom prompt)
        // Also fetch agent for tool filtering
        $agent = $conversation->agent()->first();
        $agentPrompt = $agent?->system_prompt;
        if (!empty($agentPrompt)) {
            $sections[] = $this->buildAgentSection($agentPrompt);
        }

        // 4: Tool instructions (from tools that have them)
        // Filter by agent's allowed_tools if specified
        $allowedTools = $agent?->allowed_tools;
        $toolInstructions = $toolRegistry->getInstructions($allowedTools);
        if (!empty($toolInstructions)) {
            $sections[] = $this->buildToolSection($toolInstructions);
        }

        // 5: Working directory context
        $sections[] = $this->buildContextSection($conversation);

        return implode("\n\n", array_filter($sections));
    }

    /**
     * Build the system prompt for CLI providers (Claude Code, Codex).
     * Injects PocketDev-exclusive tools (memory, tool management, user tools).
     */
    public function buildForCliProvider(Conversation $conversation, string $provider): string
    {
        $sections = [];

        // 1 & 2: Core + Additional system prompt (customizable via settings)
        $sections[] = $this->systemPromptService->get();

        // 3: Agent-specific system prompt (if conversation has an agent with custom prompt)
        // Also fetch agent for tool filtering
        $agent = $conversation->agent()->first();
        $agentPrompt = $agent?->system_prompt;
        if (!empty($agentPrompt)) {
            $sections[] = $this->buildAgentSection($agentPrompt);
        }

        // 4: PocketDev tools (memory, tool management, user tools)
        // These are tools that don't have native CLI provider equivalents
        // Filter by agent's allowed_tools if specified
        $allowedTools = $agent?->allowed_tools;
        $pocketDevToolPrompt = $this->toolSelector->buildSystemPrompt($provider, $allowedTools);
        if (!empty($pocketDevToolPrompt)) {
            $sections[] = $pocketDevToolPrompt;
        }

        // 5: Working directory context
        $sections[] = $this->buildContextSection($conversation);

        return implode("\n\n", array_filter($sections));
    }

    private function buildAgentSection(string $prompt): string
    {
        return <<<PROMPT
# Agent Instructions

{$prompt}
PROMPT;
    }

    private function buildToolSection(string $instructions): string
    {
        return <<<PROMPT
# Tool Instructions

{$instructions}
PROMPT;
    }

    private function buildContextSection(Conversation $conversation): string
    {
        $workingDir = $conversation->working_directory;

        return <<<PROMPT
# Working Directory

Current project: {$workingDir}

All file operations should be relative to or within this directory.
PROMPT;
    }
}
