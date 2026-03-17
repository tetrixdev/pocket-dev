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
 * This handles cases where:
 * 1. Queue worker dies mid-job and failed() never gets called
 * 2. Orphaned streams: Redis shows "streaming" but job crashed/died
 * 3. Abort flag set but no job running to process it
 */
class CleanupStaleConversations extends Command
{
    protected $signature = 'conversations:cleanup-stale
                            {--threshold=5 : Minutes a conversation can be processing before considered stale}
                            {--orphan-threshold=3 : Minutes to consider a "streaming" Redis status as orphaned}';

    protected $description = 'Cleanup conversations stuck in processing state (including orphaned streams)';

    public function handle(StreamManager $streamManager): int
    {
        $thresholdMinutes = (int) $this->option('threshold');
        $orphanThresholdMinutes = (int) $this->option('orphan-threshold');
        $threshold = now()->subMinutes($thresholdMinutes);
        $orphanThreshold = now()->subMinutes($orphanThresholdMinutes);

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
                $redisStatus = $streamManager->getStatus($conversation->uuid);
                $hasAbortFlag = $streamManager->checkAbortFlag($conversation->uuid);
                $lastActivity = $conversation->last_activity_at;
                $reason = null;

                // Case 1: Redis says "streaming" but no activity for too long = orphaned
                if ($redisStatus === 'streaming') {
                    // Only skip if we have KNOWN recent activity
                    // Null activity is suspicious (stream started but no progress recorded)
                    if ($lastActivity && $lastActivity >= $orphanThreshold) {
                        // Stream appears active, skip
                        $this->line("Skipping {$conversation->uuid}: stream appears active in Redis");
                        continue;
                    }

                    // Activity is stale - check abort flag first (more specific case)
                    if ($hasAbortFlag) {
                        $reason = 'orphaned_abort';
                        $this->warn("Detected orphaned abort: {$conversation->uuid} (abort flag set, no activity)");
                    } else {
                        $reason = 'orphaned_stream';
                        $this->warn("Detected orphaned stream: {$conversation->uuid} (last activity: {$lastActivity})");
                    }
                } elseif ($redisStatus === null) {
                    // Redis keys expired but DB still says processing = orphaned
                    $reason = 'redis_expired';
                } else {
                    // Redis status is 'completed' or 'failed' but DB not updated = finalization failed
                    $reason = 'finalization_failed';
                }

                $this->info("Cleaning up stale conversation: {$conversation->uuid} (reason: {$reason})");

                Log::warning('CleanupStaleConversations: Marking conversation as failed', [
                    'conversation' => $conversation->uuid,
                    'stuck_since' => $conversation->updated_at,
                    'last_activity' => $lastActivity,
                    'redis_status' => $redisStatus,
                    'has_abort_flag' => $hasAbortFlag,
                    'reason' => $reason,
                    'provider' => $conversation->provider_type,
                ]);

                // Wrap DB operations in transaction for consistency
                DB::transaction(function () use ($conversation, $reason) {
                    // Add an error block - use ROLE_ERROR so it renders as expandable error block
                    $errorMessage = match ($reason) {
                        'orphaned_stream' => 'Stream interrupted unexpectedly. The AI worker may have crashed while processing.',
                        'orphaned_abort' => 'Abort was requested but the stream was already interrupted. Recovery complete.',
                        'redis_expired' => 'Stream interrupted unexpectedly. The AI worker may have restarted.',
                        'finalization_failed' => 'Stream completed but cleanup was interrupted. Recovery complete.',
                        default => 'Stream interrupted unexpectedly.',
                    };

                    Message::create([
                        'conversation_id' => $conversation->id,
                        'role' => Message::ROLE_ERROR,
                        'content' => [[
                            'type' => 'error',
                            'message' => $errorMessage,
                        ]],
                        'stop_reason' => 'error',
                    ]);

                    // Mark as failed
                    $conversation->markFailed();
                });

                // Redis cleanup (outside transaction - not critical if these fail)
                // Note: cleanup() already clears abort flags, so no need for separate clearAbortFlag()
                $streamManager->failStream($conversation->uuid, 'Stream interrupted unexpectedly');
                $streamManager->cleanup($conversation->uuid);

                $this->info("Cleaned up: {$conversation->uuid}");
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
