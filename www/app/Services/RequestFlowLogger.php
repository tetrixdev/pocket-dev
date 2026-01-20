<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Comprehensive request flow logging for debugging chat message processing.
 *
 * Logs every decision point, branch, and timing information throughout
 * the request lifecycle - from HTTP request through job completion.
 *
 * Only active when APP_DEBUG=true.
 *
 * Log format (JSONL):
 * {"ts":"2026-01-20T10:30:00.123456Z","correlation_id":"req_abc123","conversation_uuid":"uuid",...}
 */
class RequestFlowLogger
{
    private static ?string $correlationId = null;
    private static ?string $conversationUuid = null;
    private static ?float $requestStartTime = null;

    /**
     * Start tracking a request. Call at the beginning of request handling.
     *
     * @param string $conversationUuid The conversation UUID being processed
     * @param string $type The request type (stream, abort, sse, etc.)
     * @return string The correlation ID for this request
     */
    public static function startRequest(string $conversationUuid, string $type = 'stream'): string
    {
        if (!config('app.debug')) {
            return '';
        }

        self::$correlationId = 'req_' . Str::random(12);
        self::$conversationUuid = $conversationUuid;
        self::$requestStartTime = microtime(true);

        self::log('request.start', "Request started: {$type}", [
            'type' => $type,
        ]);

        return self::$correlationId;
    }

    /**
     * Start tracking a job. Call at the beginning of job handling.
     *
     * @param string $conversationUuid The conversation UUID being processed
     * @param string $jobClass The job class name
     * @return string The correlation ID for this job
     */
    public static function startJob(string $conversationUuid, string $jobClass = 'ProcessConversationStream'): string
    {
        if (!config('app.debug')) {
            return '';
        }

        self::$correlationId = 'job_' . Str::random(12);
        self::$conversationUuid = $conversationUuid;
        self::$requestStartTime = microtime(true);

        self::log('job.start', "Job started: {$jobClass}", [
            'job_class' => $jobClass,
        ]);

        return self::$correlationId;
    }

    /**
     * End tracking for the current request/job.
     *
     * @param string $status Final status (completed, failed, aborted, etc.)
     * @param array $summary Optional summary data
     */
    public static function endRequest(string $status = 'completed', array $summary = []): void
    {
        if (!config('app.debug')) {
            return;
        }

        $totalTime = self::$requestStartTime ? (microtime(true) - self::$requestStartTime) * 1000 : null;

        self::log('request.end', "Request ended: {$status}", array_merge($summary, [
            'status' => $status,
            'total_duration_ms' => $totalTime ? round($totalTime, 2) : null,
        ]));

        // Reset state
        self::$correlationId = null;
        self::$conversationUuid = null;
        self::$requestStartTime = null;
    }

    /**
     * Log a stage in the request flow.
     *
     * @param string $stage Stage identifier (e.g., 'controller.stream.start')
     * @param string $message Human-readable message
     * @param array $context Additional context data
     */
    public static function log(string $stage, string $message, array $context = []): void
    {
        if (!config('app.debug')) {
            return;
        }

        $entry = self::buildLogEntry($stage, $message, $context);
        self::writeEntry($entry);
    }

    /**
     * Log a stage with timing measurement.
     * Returns a closure to call when the operation completes.
     *
     * Usage:
     *   $done = RequestFlowLogger::logTimed('stage', 'Starting operation');
     *   // ... do work ...
     *   $done(['result' => 'success']);
     *
     * @param string $stage Stage identifier
     * @param string $message Human-readable message
     * @param array $context Additional context data
     * @return callable Closure to call when operation completes
     */
    public static function logTimed(string $stage, string $message, array $context = []): callable
    {
        if (!config('app.debug')) {
            return fn() => null;
        }

        $startTime = microtime(true);
        self::log($stage . '.start', $message . ' (started)', $context);

        return function (array $endContext = []) use ($stage, $message, $startTime, $context) {
            $duration = (microtime(true) - $startTime) * 1000;
            self::log($stage . '.end', $message . ' (completed)', array_merge($context, $endContext, [
                'duration_ms' => round($duration, 2),
            ]));
        };
    }

    /**
     * Log a decision point (if/else branch).
     *
     * @param string $stage Stage identifier
     * @param string $condition The condition being evaluated
     * @param bool $result The result of the condition
     * @param array $context Additional context
     */
    public static function logDecision(string $stage, string $condition, bool $result, array $context = []): void
    {
        if (!config('app.debug')) {
            return;
        }

        self::log($stage, "Decision: {$condition}", array_merge($context, [
            'condition' => $condition,
            'result' => $result,
            'branch' => $result ? 'true' : 'false',
        ]));
    }

    /**
     * Log an error condition.
     *
     * @param string $stage Stage identifier
     * @param string $message Error message
     * @param \Throwable|null $exception Optional exception
     * @param array $context Additional context
     */
    public static function logError(string $stage, string $message, ?\Throwable $exception = null, array $context = []): void
    {
        if (!config('app.debug')) {
            return;
        }

        $errorContext = $context;
        if ($exception) {
            $errorContext['exception_class'] = get_class($exception);
            $errorContext['exception_message'] = $exception->getMessage();
            $errorContext['exception_file'] = $exception->getFile();
            $errorContext['exception_line'] = $exception->getLine();
        }

        self::log($stage, "ERROR: {$message}", $errorContext);
    }

    /**
     * Set the correlation ID (for use when continuing from a previous context).
     */
    public static function setCorrelationId(string $correlationId): void
    {
        self::$correlationId = $correlationId;
    }

    /**
     * Set the conversation UUID (for use when context changes).
     */
    public static function setConversationUuid(string $uuid): void
    {
        self::$conversationUuid = $uuid;
    }

    /**
     * Get the current correlation ID.
     */
    public static function getCorrelationId(): ?string
    {
        return self::$correlationId;
    }

    /**
     * Build a log entry with all debug information.
     */
    private static function buildLogEntry(string $stage, string $message, array $context): array
    {
        $now = microtime(true);
        $elapsedMs = self::$requestStartTime ? ($now - self::$requestStartTime) * 1000 : null;

        return [
            'ts' => gmdate('Y-m-d\TH:i:s', (int) $now) . '.' . sprintf('%06d', ($now - floor($now)) * 1000000) . 'Z',
            'correlation_id' => self::$correlationId,
            'conversation_uuid' => self::$conversationUuid,
            'pid' => getmypid(),
            'memory_mb' => round(memory_get_usage() / 1024 / 1024, 2),
            'elapsed_ms' => $elapsedMs !== null ? round($elapsedMs, 2) : null,
            'stage' => $stage,
            'message' => $message,
            'context' => !empty($context) ? $context : null,
        ];
    }

    /**
     * Write a log entry to the log file.
     */
    private static function writeEntry(array $entry): void
    {
        $basePath = storage_path('logs/request-flow');

        if (!File::isDirectory($basePath)) {
            File::makeDirectory($basePath, 0755, true);
        }

        $filename = gmdate('Y-m-d') . '.log';
        $filepath = $basePath . '/' . $filename;

        // Remove null values for cleaner output
        $entry = array_filter($entry, fn($v) => $v !== null);

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Invalid UTF-8 or other encoding error - log warning and skip this entry
            \Log::warning('RequestFlowLogger: json_encode failed', [
                'error' => json_last_error_msg(),
                'event' => $entry['event'] ?? 'unknown',
            ]);
            return;
        }
        File::append($filepath, $json . "\n");
    }

    /**
     * Get human-readable memory usage.
     */
    public static function getReadableMemory(): string
    {
        $bytes = memory_get_usage();
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    /**
     * Get elapsed time since request start in human-readable format.
     */
    public static function getElapsedTime(): string
    {
        if (!self::$requestStartTime) {
            return '0.00ms';
        }

        $ms = (microtime(true) - self::$requestStartTime) * 1000;

        if ($ms < 1000) {
            return round($ms, 2) . 'ms';
        }

        return round($ms / 1000, 2) . 's';
    }
}
