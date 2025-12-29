<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\StreamManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cleanup stale conversations that got stuck in "processing" state.
 *
 * This handles cases where the queue worker dies mid-job and the
 * failed() method never gets called (e.g., container restart).
 */
class CleanupStaleConversations extends Command
{
    protected $signature = 'conversations:cleanup-stale
                            {--threshold=5 : Minutes a conversation can be processing before considered stale}';

    protected $description = 'Cleanup conversations stuck in processing state';

    public function handle(StreamManager $streamManager): int
    {
        $thresholdMinutes = (int) $this->option('threshold');
        $threshold = now()->subMinutes($thresholdMinutes);

        // Find conversations that have been "processing" for too long
        $staleConversations = Conversation::where('status', Conversation::STATUS_PROCESSING)
            ->where('updated_at', '<', $threshold)
            ->get();

        if ($staleConversations->isEmpty()) {
            $this->info('No stale conversations found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$staleConversations->count()} stale conversation(s).");

        foreach ($staleConversations as $conversation) {
            // Double-check: is the stream actually still active in Redis?
            if ($streamManager->isStreaming($conversation->uuid)) {
                $this->warn("Skipping {$conversation->uuid}: stream still active in Redis");
                continue;
            }

            $this->info("Cleaning up stale conversation: {$conversation->uuid}");

            Log::warning('CleanupStaleConversations: Marking conversation as failed', [
                'conversation' => $conversation->uuid,
                'stuck_since' => $conversation->updated_at,
            ]);

            // Try to recover any content from Redis
            $recoveredBlocks = $this->reconstructContentFromRedis($streamManager, $conversation->uuid);

            // Find or create assistant message
            $lastUserMessage = $conversation->messages()
                ->where('role', Message::ROLE_USER)
                ->latest('id')
                ->first();

            $lastAssistant = $conversation->messages()
                ->where('role', Message::ROLE_ASSISTANT)
                ->when($lastUserMessage, fn($q) => $q->where('id', '>', $lastUserMessage->id))
                ->latest('id')
                ->first();

            $errorBlock = [
                'type' => 'error',
                'message' => 'Stream interrupted unexpectedly. The AI worker may have restarted.',
            ];

            if ($lastAssistant && is_array($lastAssistant->content)) {
                $content = $lastAssistant->content;
                $content[] = $errorBlock;
                $lastAssistant->update(['content' => $content]);
            } elseif (!empty($recoveredBlocks)) {
                $recoveredBlocks[] = $errorBlock;
                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => Message::ROLE_ASSISTANT,
                    'content' => $recoveredBlocks,
                    'stop_reason' => 'error',
                ]);
            } else {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => Message::ROLE_ASSISTANT,
                    'content' => [$errorBlock],
                    'stop_reason' => 'error',
                ]);
            }

            // Mark as failed and send error event (popup if SSE connected)
            $conversation->markFailed();
            $streamManager->failStream($conversation->uuid, 'Stream interrupted unexpectedly');
            $streamManager->cleanup($conversation->uuid);
        }

        $this->info('Cleanup complete.');
        return Command::SUCCESS;
    }

    /**
     * Reconstruct content blocks from Redis stream events.
     */
    private function reconstructContentFromRedis(StreamManager $streamManager, string $uuid): array
    {
        $events = $streamManager->getEvents($uuid);

        if (empty($events)) {
            return [];
        }

        $blocks = [];
        $blockData = [];

        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            $blockIndex = $event['block_index'] ?? null;
            $content = $event['content'] ?? '';
            $metadata = $event['metadata'] ?? [];

            if ($blockIndex === null) {
                continue;
            }

            switch ($type) {
                case 'thinking_start':
                    $blockData[$blockIndex] = ['type' => 'thinking', 'thinking' => ''];
                    break;
                case 'thinking_delta':
                    if (isset($blockData[$blockIndex])) {
                        $blockData[$blockIndex]['thinking'] .= $content;
                    }
                    break;
                case 'thinking_stop':
                    if (isset($blockData[$blockIndex])) {
                        $blocks[$blockIndex] = $blockData[$blockIndex];
                    }
                    break;
                case 'text_start':
                    $blockData[$blockIndex] = ['type' => 'text', 'text' => ''];
                    break;
                case 'text_delta':
                    if (isset($blockData[$blockIndex])) {
                        $blockData[$blockIndex]['text'] .= $content;
                    }
                    break;
                case 'text_stop':
                    if (isset($blockData[$blockIndex])) {
                        $blocks[$blockIndex] = $blockData[$blockIndex];
                    }
                    break;
                case 'tool_use_start':
                    $blockData[$blockIndex] = [
                        'type' => 'tool_use',
                        'id' => $metadata['tool_id'] ?? 'unknown',
                        'name' => $metadata['tool_name'] ?? 'unknown',
                        'input' => new \stdClass(),
                        '_inputJson' => '',
                    ];
                    break;
                case 'tool_use_delta':
                    if (isset($blockData[$blockIndex])) {
                        $blockData[$blockIndex]['_inputJson'] .= $content;
                    }
                    break;
                case 'tool_use_stop':
                    if (isset($blockData[$blockIndex])) {
                        $inputJson = $blockData[$blockIndex]['_inputJson'] ?? '';
                        $parsedInput = json_decode($inputJson, true);
                        $blockData[$blockIndex]['input'] = $parsedInput ?? new \stdClass();
                        unset($blockData[$blockIndex]['_inputJson']);
                        $blocks[$blockIndex] = $blockData[$blockIndex];
                    }
                    break;
            }
        }

        // Include incomplete blocks
        foreach ($blockData as $index => $data) {
            if (!isset($blocks[$index])) {
                if (isset($data['_inputJson'])) {
                    $data['input'] = json_decode($data['_inputJson'], true) ?? new \stdClass();
                    unset($data['_inputJson']);
                }
                $blocks[$index] = $data;
            }
        }

        ksort($blocks);
        return array_values($blocks);
    }
}
