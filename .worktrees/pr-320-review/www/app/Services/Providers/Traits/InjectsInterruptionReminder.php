<?php

namespace App\Services\Providers\Traits;

/**
 * Trait for injecting interruption reminders into user messages.
 *
 * When a previous assistant response was interrupted, this prepends
 * a system reminder to the last user message so the AI has context.
 */
trait InjectsInterruptionReminder
{
    /**
     * Inject an interruption reminder into the last user message.
     */
    protected function injectInterruptionReminder(array $messages, string $reminder): array
    {
        // Find the last user message (searching from end)
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $content = $messages[$i]['content'];

                // Handle string content
                if (is_string($content)) {
                    $messages[$i]['content'] = $reminder . "\n\n" . $content;
                }
                // Handle array content (Anthropic format with content blocks)
                elseif (is_array($content) && !empty($content)) {
                    // Prepend reminder as first text block
                    array_unshift($messages[$i]['content'], [
                        'type' => 'text',
                        'text' => $reminder,
                    ]);
                }

                break;
            }
        }

        return $messages;
    }
}
