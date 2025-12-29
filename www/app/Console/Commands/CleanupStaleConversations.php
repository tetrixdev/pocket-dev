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

            // Simply add an error block - no complex reconstruction
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => [[
                    'type' => 'error',
                    'message' => 'Stream interrupted unexpectedly. The AI worker may have restarted.',
                ]],
                'stop_reason' => 'error',
            ]);

            // Mark as failed and cleanup
            $conversation->markFailed();
            $streamManager->failStream($conversation->uuid, 'Stream interrupted unexpectedly');
            $streamManager->cleanup($conversation->uuid);
        }

        $this->info('Cleanup complete.');
        return Command::SUCCESS;
    }
}
