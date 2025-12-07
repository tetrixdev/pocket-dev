<?php

namespace App\Streaming;

/**
 * Handles Server-Sent Events (SSE) output to the frontend.
 *
 * This class is responsible ONLY for formatting and sending events to the browser.
 * It knows nothing about providers (Anthropic, OpenAI, etc.) - it just sends StreamEvents.
 */
class SseWriter
{
    private bool $initialized = false;

    /**
     * Initialize SSE output by disabling buffering.
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Disable all output buffering for true streaming
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $this->initialized = true;
    }

    /**
     * Write a StreamEvent to the SSE output.
     */
    public function write(StreamEvent $event): void
    {
        $this->initialize();

        echo "data: " . $event->toJson() . "\n\n";
        flush();
    }

    /**
     * Write raw JSON data to the SSE output.
     */
    public function writeRaw(string $json): void
    {
        $this->initialize();

        echo "data: " . $json . "\n\n";
        flush();
    }

    /**
     * Write a raw error message (for cases where StreamEvent can't be created).
     */
    public function writeError(string $message): void
    {
        $this->initialize();

        echo "data: " . json_encode([
            'type' => 'error',
            'content' => $message,
        ]) . "\n\n";
        flush();
    }

    /**
     * Write a debug message (only in debug mode).
     */
    public function writeDebug(string $message, array $context = []): void
    {
        if (!config('app.debug')) {
            return;
        }

        $this->initialize();

        echo "data: " . json_encode([
            'type' => 'debug',
            'content' => $message,
            'metadata' => $context,
        ]) . "\n\n";
        flush();
    }

    /**
     * Get standard SSE headers for StreamedResponse.
     */
    public static function headers(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
