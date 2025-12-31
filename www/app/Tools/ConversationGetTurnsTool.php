<?php

namespace App\Tools;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Retrieve full content for specific conversation turns.
 */
class ConversationGetTurnsTool extends Tool
{
    public string $name = 'ConversationGetTurns';

    public string $description = 'Retrieve full content for specific conversation turns.';

    public string $category = 'memory_data';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'turns' => [
                'type' => 'array',
                'description' => 'Array of turns to retrieve, each with conversation_uuid and turn_number',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'conversation_uuid' => ['type' => 'string'],
                        'turn_number' => ['type' => 'integer'],
                    ],
                    'required' => ['conversation_uuid', 'turn_number'],
                ],
            ],
        ],
        'required' => ['turns'],
    ];

    public ?string $instructions = <<<'INSTRUCTIONS'
Retrieve full content for specific conversation turns.

## When to Use
- After searching conversations, to get full context for promising matches
- To fetch adjacent turns for additional context (e.g., turn N-1 and N together)
- When you need the complete assistant response, not just the preview

## Parameters
- turns: Array of turns to retrieve. Each element should have:
  - conversation_uuid: The conversation's UUID
  - turn_number: The turn number within that conversation

## Output
Returns full content for each requested turn, including:
- User message (complete)
- Assistant response (complete, including tool calls)
- Previous assistant context (if available)
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
php artisan conversation:get-turns --turns='[{"conversation_uuid":"abc-123","turn_number":5}]'
php artisan conversation:get-turns --turns='[{"conversation_uuid":"abc-123","turn_number":4},{"conversation_uuid":"abc-123","turn_number":5}]'
```
CLI;

    public function getArtisanCommand(): ?string
    {
        return 'conversation:get-turns';
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $turns = $input['turns'] ?? [];

        if (empty($turns)) {
            return ToolResult::error('turns array is required and cannot be empty');
        }

        if (count($turns) > 10) {
            return ToolResult::error('Maximum 10 turns can be requested at once');
        }

        $results = [];

        foreach ($turns as $turnRequest) {
            $uuid = $turnRequest['conversation_uuid'] ?? null;
            $turnNumber = $turnRequest['turn_number'] ?? null;

            if (!$uuid || $turnNumber === null) {
                continue;
            }

            $conversation = Conversation::where('uuid', $uuid)->first();
            if (!$conversation) {
                $results[] = [
                    'conversation_uuid' => $uuid,
                    'turn_number' => $turnNumber,
                    'error' => 'Conversation not found',
                ];
                continue;
            }

            // Get messages for this turn
            $messages = Message::where('conversation_id', $conversation->id)
                ->where('turn_number', $turnNumber)
                ->orderBy('sequence')
                ->get();

            if ($messages->isEmpty()) {
                $results[] = [
                    'conversation_uuid' => $uuid,
                    'turn_number' => $turnNumber,
                    'error' => 'Turn not found',
                ];
                continue;
            }

            // Get previous turn for context
            $prevMessages = Message::where('conversation_id', $conversation->id)
                ->where('turn_number', $turnNumber - 1)
                ->orderBy('sequence')
                ->get();

            $prevAssistantText = $this->extractAssistantText($prevMessages);

            // Extract content
            $userText = null;
            $assistantContent = [];

            foreach ($messages as $message) {
                if ($message->role === 'user') {
                    // Handle both string and block-array formats consistently
                    $text = $message->getTextContent();
                    if ($text !== '') {
                        $userText = $text;
                    }
                } elseif ($message->role === 'assistant') {
                    $assistantContent[] = $this->formatAssistantContent($message->content);
                }
            }

            $results[] = [
                'conversation_uuid' => $uuid,
                'conversation_title' => $conversation->title,
                'turn_number' => $turnNumber,
                'url' => "/chat/{$uuid}?turn={$turnNumber}",
                'previous_assistant' => $prevAssistantText,
                'user_message' => $userText,
                'assistant_response' => implode("\n\n", array_filter($assistantContent)),
            ];
        }

        return ToolResult::success(json_encode([
            'turns' => $results,
            'count' => count($results),
        ], JSON_PRETTY_PRINT));
    }

    private function extractAssistantText($messages): ?string
    {
        $parts = [];

        foreach ($messages as $message) {
            if ($message->role !== 'assistant') {
                continue;
            }

            $content = $message->content;

            if (is_string($content)) {
                $parts[] = $content;
                continue;
            }

            if (is_array($content)) {
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $parts[] = $block['text'] ?? '';
                    }
                }
            }
        }

        $text = implode("\n", array_filter($parts));
        return $text ?: null;
    }

    private function formatAssistantContent($content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $parts[] = $block['text'] ?? '';
            } elseif ($type === 'tool_use') {
                $name = $block['name'] ?? 'unknown';
                $input = $block['input'] ?? [];
                $parts[] = "[Tool: {$name}]\n" . json_encode($input, JSON_PRETTY_PRINT);
            }
            // Skip thinking, tool_result, etc.
        }

        return implode("\n\n", array_filter($parts));
    }
}
