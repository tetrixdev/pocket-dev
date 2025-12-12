<?php

namespace App\Services;

use App\Models\Conversation;

/**
 * Builds the system prompt for AI providers.
 * Includes tool instructions and working directory context.
 */
class SystemPromptBuilder
{
    /**
     * Build the system prompt for a conversation.
     */
    public function build(Conversation $conversation, ToolRegistry $toolRegistry): string
    {
        $sections = [];

        // Core identity
        $sections[] = $this->buildIdentitySection();

        // Tool instructions (from tools that have them)
        $toolInstructions = $toolRegistry->getInstructions();
        if (!empty($toolInstructions)) {
            $sections[] = $this->buildToolSection($toolInstructions);
        }

        // Working directory context
        $sections[] = $this->buildContextSection($conversation);

        // Guidelines
        $sections[] = $this->buildGuidelinesSection();

        return implode("\n\n", array_filter($sections));
    }

    private function buildIdentitySection(): string
    {
        return <<<'PROMPT'
You are an AI coding assistant with access to tools for reading, editing, and exploring code.

You help developers by:
- Reading and understanding code
- Making targeted edits to files
- Running commands in the terminal
- Searching for patterns in codebases
- Finding files by name or pattern
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

    private function buildGuidelinesSection(): string
    {
        return <<<'PROMPT'
# Guidelines

- Always read files before editing them
- Make minimal, focused changes - don't add unnecessary features
- Preserve existing code style and formatting
- When editing, ensure old_string is unique or use replace_all
- For complex changes, break them into smaller steps
- Explain your reasoning before making changes
PROMPT;
    }
}
