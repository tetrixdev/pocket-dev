<?php

namespace App\Services;

use App\Enums\Provider;
use App\Models\Conversation;

/**
 * Builds the system prompt for AI providers.
 * Includes customizable core prompt, tool instructions, and working directory context.
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

        // System prompt (core + additional, customizable via settings)
        $sections[] = $this->systemPromptService->get();

        // Tool instructions (from tools that have them)
        $toolInstructions = $toolRegistry->getInstructions();
        if (!empty($toolInstructions)) {
            $sections[] = $this->buildToolSection($toolInstructions);
        }

        // Working directory context
        $sections[] = $this->buildContextSection($conversation);

        return implode("\n\n", array_filter($sections));
    }

    /**
     * Build the system prompt specifically for Claude Code provider.
     * Injects PocketDev-exclusive tools (memory, tool management, user tools).
     */
    public function buildForClaudeCode(Conversation $conversation): string
    {
        $sections = [];

        // System prompt (core + additional, customizable via settings)
        $sections[] = $this->systemPromptService->get();

        // PocketDev tools (memory, tool management, user tools)
        // These are tools that don't have native Claude Code equivalents
        $pocketDevToolPrompt = $this->toolSelector->buildSystemPrompt(Provider::ClaudeCode->value);
        if (!empty($pocketDevToolPrompt)) {
            $sections[] = $pocketDevToolPrompt;
        }

        // Working directory context
        $sections[] = $this->buildContextSection($conversation);

        return implode("\n\n", array_filter($sections));
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
