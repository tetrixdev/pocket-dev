<?php

namespace App\Services;

use App\Streaming\StreamEvent;
use Illuminate\Support\Facades\Redis;

/**
 * Manages streaming state in Redis for background streaming and reconnection.
 *
 * Stream data is stored as:
 * - stream:{uuid}:events - Redis List of JSON-encoded StreamEvents
 * - stream:{uuid}:status - 'streaming' | 'completed' | 'failed'
 * - stream:{uuid}:metadata - JSON with start time, model, etc.
 * - stream:{uuid}:position:{client} - Last read position for each client
 */
class StreamManager
{
    private const PREFIX = 'stream:';
    private const TTL_STREAMING = 3600;      // 1 hour for active streams
    private const TTL_COMPLETED = 1800;      // 30 minutes for completed streams (allows reconnection after slow refresh)

    /**
     * Start a new stream for a conversation.
     */
    public function startStream(string $conversationUuid, array $metadata = []): void
    {
        RequestFlowLogger::log('stream.start', 'Starting stream in Redis', [
            'conversation_uuid' => $conversationUuid,
            'metadata' => $metadata,
        ]);

        $key = $this->key($conversationUuid);

        Redis::multi();
        Redis::set("{$key}:status", 'streaming');
        Redis::set("{$key}:metadata", json_encode(array_merge($metadata, [
            'started_at' => now()->toIso8601String(),
        ])));
        Redis::del("{$key}:events"); // Clear any old events
        Redis::expire("{$key}:status", self::TTL_STREAMING);
        Redis::expire("{$key}:metadata", self::TTL_STREAMING);
        Redis::exec();

        RequestFlowLogger::log('stream.started', 'Stream started in Redis');
    }

    /**
     * Append an event to the stream.
     *
     * Uses Redis transaction to ensure event is in the list before publishing.
     * This prevents race conditions where subscribers receive events not yet in the list.
     */
    public function appendEvent(string $conversationUuid, StreamEvent $event): void
    {
        static $firstEventTime = null;
        static $eventCount = 0;

        $key = $this->key($conversationUuid);
        $json = $event->toJson();
        $eventCount++;

        // Log first event and periodically after that
        if ($firstEventTime === null) {
            $firstEventTime = microtime(true);
            RequestFlowLogger::log('stream.first_event_append', 'First event pushed to Redis', [
                'event_type' => $event->type,
            ]);
        } elseif ($event->type === 'text_delta' && $eventCount <= 5) {
            // Log first few text deltas to track when actual content arrives
            RequestFlowLogger::log('stream.text_delta_append', 'Text delta pushed to Redis', [
                'event_number' => $eventCount,
                'ms_since_first' => round((microtime(true) - $firstEventTime) * 1000, 2),
            ]);
        }

        // Use transaction for list operations to ensure atomicity
        Redis::multi();
        Redis::rpush("{$key}:events", $json);
        Redis::expire("{$key}:events", self::TTL_STREAMING);
        Redis::exec();

        // Publish after transaction completes - ensures event is in list first
        // This ordering prevents race conditions where subscribers try to read
        // events that haven't been committed to the list yet
        Redis::publish("stream:{$conversationUuid}", $json);
    }

    /**
     * Mark stream as completed.
     *
     * @param string $conversationUuid
     * @param string $status The completion status: 'completed' or 'aborted'
     */
    public function completeStream(string $conversationUuid, string $status = 'completed'): void
    {
        RequestFlowLogger::log('stream.complete', 'Completing stream', [
            'conversation_uuid' => $conversationUuid,
            'status' => $status,
        ]);

        $key = $this->key($conversationUuid);

        Redis::multi();
        Redis::set("{$key}:status", $status);
        // Reduce TTL for completed streams
        Redis::expire("{$key}:status", self::TTL_COMPLETED);
        Redis::expire("{$key}:events", self::TTL_COMPLETED);
        Redis::expire("{$key}:metadata", self::TTL_COMPLETED);
        Redis::exec();

        // Publish completion event
        Redis::publish("stream:{$conversationUuid}", json_encode([
            'type' => 'stream_completed',
            'status' => $status,
        ]));

        RequestFlowLogger::log('stream.completed', 'Stream marked as completed in Redis');
    }

    /**
     * Mark stream as failed.
     */
    public function failStream(string $conversationUuid, string $error): void
    {
        RequestFlowLogger::log('stream.fail', 'Failing stream', [
            'conversation_uuid' => $conversationUuid,
            'error' => $error,
        ]);

        $key = $this->key($conversationUuid);

        // Append error event
        $this->appendEvent($conversationUuid, StreamEvent::error($error));

        Redis::multi();
        Redis::set("{$key}:status", 'failed');
        Redis::expire("{$key}:status", self::TTL_COMPLETED);
        Redis::expire("{$key}:events", self::TTL_COMPLETED);
        Redis::expire("{$key}:metadata", self::TTL_COMPLETED);
        Redis::exec();

        // Publish failure event
        Redis::publish("stream:{$conversationUuid}", json_encode([
            'type' => 'stream_failed',
            'error' => $error,
        ]));

        RequestFlowLogger::log('stream.failed', 'Stream marked as failed in Redis');
    }

    /**
     * Get stream status.
     *
     * @return string|null 'streaming', 'completed', 'failed', or null if not found
     */
    public function getStatus(string $conversationUuid): ?string
    {
        return Redis::get($this->key($conversationUuid) . ':status');
    }

    /**
     * Check if a stream is currently active.
     */
    public function isStreaming(string $conversationUuid): bool
    {
        return $this->getStatus($conversationUuid) === 'streaming';
    }

    /**
     * Get all buffered events from the stream.
     *
     * @param int $fromIndex Start reading from this index (0-based)
     * @return array Array of decoded event arrays
     */
    public function getEvents(string $conversationUuid, int $fromIndex = 0): array
    {
        $key = $this->key($conversationUuid);
        $events = Redis::lrange("{$key}:events", $fromIndex, -1);

        return array_map(fn($e) => json_decode($e, true), $events);
    }

    /**
     * Get the total number of events in the stream.
     */
    public function getEventCount(string $conversationUuid): int
    {
        return (int) Redis::llen($this->key($conversationUuid) . ':events');
    }

    /**
     * Get the last event from the stream.
     *
     * Useful for verifying event continuity on reconnection.
     *
     * @return array|null The last event as decoded array, or null if no events
     */
    public function getLastEvent(string $conversationUuid): ?array
    {
        $key = $this->key($conversationUuid);
        $event = Redis::lindex("{$key}:events", -1);  // Get last element (index -1)

        return $event ? json_decode($event, true) : null;
    }

    /**
     * Get stream metadata.
     */
    public function getMetadata(string $conversationUuid): ?array
    {
        $data = Redis::get($this->key($conversationUuid) . ':metadata');
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Subscribe to stream events (blocking).
     * Used for SSE endpoint to receive real-time updates.
     *
     * @param string $conversationUuid
     * @param callable $callback Called with each event: function(array $event): bool
     *                           Return false to stop listening
     * @param int $timeout Timeout in seconds (0 = forever)
     */
    public function subscribe(string $conversationUuid, callable $callback, int $timeout = 0): void
    {
        // Note: This uses Redis pub/sub which requires a dedicated connection.
        // In production, consider using Redis Streams (XREAD) for better reliability.
        $redis = Redis::connection('default')->client();

        // Use a separate connection for status checks (can't use same connection inside pub/sub loop)
        $statusRedis = Redis::connection('default')->client();

        // For predis, we need to use pubSubLoop
        $pubsub = $redis->pubSubLoop();
        $pubsub->subscribe("stream:{$conversationUuid}");

        $startTime = time();

        foreach ($pubsub as $message) {
            if ($message->kind === 'message') {
                $event = json_decode($message->payload, true);
                if ($callback($event) === false) {
                    break;
                }
            }

            // Check timeout
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                break;
            }

            // Check if stream completed using separate connection
            $status = $statusRedis->get($this->key($conversationUuid) . ':status');
            if ($status !== 'streaming') {
                break;
            }
        }

        $pubsub->unsubscribe();
    }

    /**
     * Set the abort flag for a stream.
     * The job will check this flag and terminate if set.
     *
     * @param string $conversationUuid
     * @param bool $skipSync If true, the job should skip syncing to CLI session files
     *                       (used when aborting after tool execution completed)
     */
    public function setAbortFlag(string $conversationUuid, bool $skipSync = false): void
    {
        RequestFlowLogger::log('stream.abort_flag_set', 'Setting abort flag', [
            'conversation_uuid' => $conversationUuid,
            'skip_sync' => $skipSync,
        ]);

        $key = $this->key($conversationUuid);
        Redis::set("{$key}:abort", 'true', 'EX', self::TTL_STREAMING);
        if ($skipSync) {
            Redis::set("{$key}:abort_skip_sync", 'true', 'EX', self::TTL_STREAMING);
        }
    }

    /**
     * Check if the abort flag is set for a stream.
     */
    public function checkAbortFlag(string $conversationUuid): bool
    {
        return Redis::get($this->key($conversationUuid) . ':abort') === 'true';
    }

    /**
     * Check if sync should be skipped when aborting.
     * This is true when abort happened after tool execution completed.
     */
    public function shouldSkipSyncOnAbort(string $conversationUuid): bool
    {
        return Redis::get($this->key($conversationUuid) . ':abort_skip_sync') === 'true';
    }

    /**
     * Clear the abort flag for a stream.
     */
    public function clearAbortFlag(string $conversationUuid): void
    {
        RequestFlowLogger::log('stream.abort_flag_clear', 'Clearing abort flag', [
            'conversation_uuid' => $conversationUuid,
        ]);

        $key = $this->key($conversationUuid);
        Redis::del("{$key}:abort", "{$key}:abort_skip_sync");
    }

    /**
     * Clean up a stream's data.
     */
    public function cleanup(string $conversationUuid): void
    {
        $key = $this->key($conversationUuid);
        Redis::del("{$key}:events", "{$key}:status", "{$key}:metadata", "{$key}:abort", "{$key}:abort_skip_sync");
    }

    /**
     * Get the Redis key prefix for a conversation.
     */
    private function key(string $conversationUuid): string
    {
        return self::PREFIX . $conversationUuid;
    }
}
