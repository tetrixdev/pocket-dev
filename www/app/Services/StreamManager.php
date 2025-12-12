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
    private const TTL_COMPLETED = 300;       // 5 minutes for completed streams

    /**
     * Start a new stream for a conversation.
     */
    public function startStream(string $conversationUuid, array $metadata = []): void
    {
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
    }

    /**
     * Append an event to the stream.
     */
    public function appendEvent(string $conversationUuid, StreamEvent $event): void
    {
        $key = $this->key($conversationUuid);
        Redis::rpush("{$key}:events", $event->toJson());
        Redis::expire("{$key}:events", self::TTL_STREAMING);

        // Publish to channel for real-time subscribers
        Redis::publish("stream:{$conversationUuid}", $event->toJson());
    }

    /**
     * Mark stream as completed.
     */
    public function completeStream(string $conversationUuid): void
    {
        $key = $this->key($conversationUuid);

        Redis::multi();
        Redis::set("{$key}:status", 'completed');
        // Reduce TTL for completed streams
        Redis::expire("{$key}:status", self::TTL_COMPLETED);
        Redis::expire("{$key}:events", self::TTL_COMPLETED);
        Redis::expire("{$key}:metadata", self::TTL_COMPLETED);
        Redis::exec();

        // Publish completion event
        Redis::publish("stream:{$conversationUuid}", json_encode([
            'type' => 'stream_completed',
        ]));
    }

    /**
     * Mark stream as failed.
     */
    public function failStream(string $conversationUuid, string $error): void
    {
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
     * Clean up a stream's data.
     */
    public function cleanup(string $conversationUuid): void
    {
        $key = $this->key($conversationUuid);
        Redis::del("{$key}:events", "{$key}:status", "{$key}:metadata");
    }

    /**
     * Get the Redis key prefix for a conversation.
     */
    private function key(string $conversationUuid): string
    {
        return self::PREFIX . $conversationUuid;
    }
}
