<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Per-conversation logging of Claude Code CLI stream data.
 *
 * Logs all JSONL data streamed between PocketDev and Claude Code CLI
 * to per-conversation files for debugging and development purposes.
 *
 * Log format (JSONL):
 * {"ts": "2026-01-13T10:30:00.123Z", "dir": "meta", "type": "command", "data": "claude --print ..."}
 * {"ts": "2026-01-13T10:30:00.124Z", "dir": "in", "type": "stdin", "data": "User message here"}
 * {"ts": "2026-01-13T10:30:00.200Z", "dir": "out", "type": "stream", "data": {"type": "system", ...}}
 */
class ConversationStreamLogger
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = storage_path('logs/conversations');
    }

    /**
     * Get log file path for a conversation.
     */
    public function getLogPath(string $uuid): string
    {
        return "{$this->basePath}/{$uuid}.jsonl";
    }

    /**
     * Initialize logging for a conversation.
     * Creates directory if needed and returns the file path.
     */
    public function init(string $uuid): string
    {
        if (!File::isDirectory($this->basePath)) {
            File::makeDirectory($this->basePath, 0755, true);
        }

        return $this->getLogPath($uuid);
    }

    /**
     * Log the command being executed.
     */
    public function logCommand(string $uuid, string $command): void
    {
        $this->write($uuid, 'meta', 'command', $command);
    }

    /**
     * Log stdin (user message) sent to Claude.
     */
    public function logStdin(string $uuid, string $content): void
    {
        $this->write($uuid, 'in', 'stdin', $content);
    }

    /**
     * Log a JSONL line from Claude CLI stdout.
     *
     * @param string $uuid Conversation UUID
     * @param string $line Raw line from stdout
     * @param array|null $parsed Parsed JSON data (optional, for richer logging)
     */
    public function logStream(string $uuid, string $line, ?array $parsed = null): void
    {
        $this->write($uuid, 'out', 'stream', $parsed ?? $line);
    }

    /**
     * Log stderr output from Claude CLI.
     */
    public function logStderr(string $uuid, string $content): void
    {
        $this->write($uuid, 'out', 'stderr', $content);
    }

    /**
     * Log an error.
     */
    public function logError(string $uuid, string $error): void
    {
        $this->write($uuid, 'meta', 'error', $error);
    }

    /**
     * Log stream completion.
     */
    public function logComplete(string $uuid, array $summary = []): void
    {
        $this->write($uuid, 'meta', 'complete', $summary);
    }

    /**
     * Delete log file for a conversation.
     */
    public function delete(string $uuid): bool
    {
        $path = $this->getLogPath($uuid);
        if (File::exists($path)) {
            return File::delete($path);
        }

        return true;
    }

    /**
     * Check if log file exists.
     */
    public function exists(string $uuid): bool
    {
        return File::exists($this->getLogPath($uuid));
    }

    /**
     * Get file size in bytes.
     */
    public function getSize(string $uuid): ?int
    {
        $path = $this->getLogPath($uuid);
        if (File::exists($path)) {
            return File::size($path);
        }

        return null;
    }

    /**
     * Write a log entry.
     */
    private function write(string $uuid, string $dir, string $type, mixed $data): void
    {
        $path = $this->getLogPath($uuid);

        $entry = [
            'ts' => now()->toISOString(),
            'dir' => $dir,
            'type' => $type,
            'data' => $data,
        ];

        File::append($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
