<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\StreamManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
            try {
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

                // Wrap DB operations in transaction for consistency
                DB::transaction(function () use ($conversation) {
                    // Add an error block - use ROLE_ERROR so it renders as expandable error block
                    Message::create([
                        'conversation_id' => $conversation->id,
                        'role' => Message::ROLE_ERROR,
                        'content' => [[
                            'type' => 'error',
                            'message' => 'Stream interrupted unexpectedly. The AI worker may have restarted.',
                        ]],
                        'stop_reason' => 'error',
                    ]);

                    // Mark as failed
                    $conversation->markFailed();
                });

                // Redis cleanup (outside transaction - not critical if these fail)
                $streamManager->failStream($conversation->uuid, 'Stream interrupted unexpectedly');
                $streamManager->cleanup($conversation->uuid);
            } catch (\Exception $e) {
                Log::error('CleanupStaleConversations: Failed to cleanup conversation', [
                    'conversation' => $conversation->uuid,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to cleanup {$conversation->uuid}: {$e->getMessage()}");
                // Continue to next conversation
            }
        }

        $this->info('Cleanup complete.');
        return Command::SUCCESS;
    }
}
