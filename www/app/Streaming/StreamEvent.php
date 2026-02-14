<?php

namespace App\Streaming;

/**
 * Generic stream event that all providers normalize to.
 * Frontend only knows about StreamEvent, not provider-specific formats.
 */
class StreamEvent
{
    // Event type constants
    public const THINKING_START = 'thinking_start';
    public const THINKING_DELTA = 'thinking_delta';
    public const THINKING_SIGNATURE = 'thinking_signature';
    public const THINKING_STOP = 'thinking_stop';
    public const TEXT_START = 'text_start';
    public const TEXT_DELTA = 'text_delta';
    public const TEXT_STOP = 'text_stop';
    public const TOOL_USE_START = 'tool_use_start';
    public const TOOL_USE_DELTA = 'tool_use_delta';
    public const TOOL_USE_STOP = 'tool_use_stop';
    public const TOOL_RESULT = 'tool_result';
    public const USAGE = 'usage';
    public const DONE = 'done';
    public const ERROR = 'error';
    public const DEBUG = 'debug';
    public const SYSTEM_INFO = 'system_info';
    public const CONTEXT_COMPACTED = 'context_compacted';
    public const COMPACTION_SUMMARY = 'compaction_summary';
    public const SCREEN_CREATED = 'screen_created';

    /**
     * Unique event ID for reliable event tracking.
     * Auto-generated if not provided.
     */
    public readonly string $eventId;

    public function __construct(
        public string $type,
        public ?int $blockIndex = null,
        public ?string $content = null,
        public ?array $metadata = null,
        ?string $eventId = null,
    ) {
        // Generate unique event ID if not provided
        // Format: evt_{timestamp_microseconds}_{random_hex}
        $this->eventId = $eventId ?? sprintf('evt_%s_%s',
            str_replace('.', '', (string) microtime(true)),
            bin2hex(random_bytes(4))
        );
    }

    public static function thinkingStart(int $blockIndex): self
    {
        return new self(self::THINKING_START, $blockIndex);
    }

    public static function thinkingDelta(int $blockIndex, string $content): self
    {
        return new self(self::THINKING_DELTA, $blockIndex, $content);
    }

    public static function thinkingSignature(int $blockIndex, string $signature): self
    {
        return new self(self::THINKING_SIGNATURE, $blockIndex, $signature);
    }

    public static function thinkingStop(int $blockIndex): self
    {
        return new self(self::THINKING_STOP, $blockIndex);
    }

    public static function textStart(int $blockIndex): self
    {
        return new self(self::TEXT_START, $blockIndex);
    }

    public static function textDelta(int $blockIndex, string $content): self
    {
        return new self(self::TEXT_DELTA, $blockIndex, $content);
    }

    public static function textStop(int $blockIndex): self
    {
        return new self(self::TEXT_STOP, $blockIndex);
    }

    public static function toolUseStart(int $blockIndex, string $toolId, string $toolName): self
    {
        return new self(self::TOOL_USE_START, $blockIndex, null, [
            'tool_id' => $toolId,
            'tool_name' => $toolName,
        ]);
    }

    public static function toolUseDelta(int $blockIndex, string $partialJson): self
    {
        return new self(self::TOOL_USE_DELTA, $blockIndex, $partialJson);
    }

    public static function toolUseStop(int $blockIndex): self
    {
        return new self(self::TOOL_USE_STOP, $blockIndex);
    }

    public static function toolResult(string $toolId, string $content, bool $isError = false): self
    {
        return new self(self::TOOL_RESULT, null, $content, [
            'tool_id' => $toolId,
            'is_error' => $isError,
        ]);
    }

    public static function usage(
        int $inputTokens,
        int $outputTokens,
        ?int $cacheCreation = null,
        ?int $cacheRead = null,
        ?float $cost = null,
        ?int $contextWindowSize = null,
        ?int $contextInputTokens = null,
        ?int $contextOutputTokens = null
    ): self {
        $metadata = array_filter([
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_creation_tokens' => $cacheCreation,
            'cache_read_tokens' => $cacheRead,
            'cost' => $cost,
            'context_window_size' => $contextWindowSize,
        ], fn($v) => $v !== null);

        // Include per-turn context tokens for updateContextUsage whenever provided
        // CLI providers emit usage BEFORE ProcessConversationStream adds context_window_size,
        // so we must include these fields regardless of contextWindowSize
        if ($contextInputTokens !== null) {
            $metadata['context_input_tokens'] = $contextInputTokens;
        }
        if ($contextOutputTokens !== null) {
            $metadata['context_output_tokens'] = $contextOutputTokens;
        }

        // Calculate context percentage only if we have window size
        // For CLI providers with multi-turn tool execution, use context-specific tokens
        // (representing the LAST turn's usage) instead of cumulative billing totals
        if ($contextWindowSize > 0) {
            $contextInput = $contextInputTokens ?? $inputTokens;
            $contextOutput = $contextOutputTokens ?? $outputTokens;
            if ($contextInput > 0 || $contextOutput > 0) {
                $totalContext = $contextInput + $contextOutput;
                $metadata['context_percentage'] = min(100, round(($totalContext / $contextWindowSize) * 100, 1));
            }
        }

        return new self(self::USAGE, null, null, $metadata);
    }

    public static function done(string $stopReason): self
    {
        return new self(self::DONE, null, null, ['stop_reason' => $stopReason]);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, null, $message);
    }

    public static function debug(string $message, array $context = []): self
    {
        return new self(self::DEBUG, null, $message, $context);
    }

    public static function systemInfo(string $content, ?string $command = null): self
    {
        return new self(self::SYSTEM_INFO, null, $content, $command ? ['command' => $command] : null);
    }

    /**
     * Create a context compaction event.
     *
     * Emitted when Claude Code auto-compacts the conversation context.
     *
     * @param int|null $preTokens Token count before compaction
     * @param string $trigger What triggered compaction ('auto' or 'manual')
     */
    public static function contextCompacted(?int $preTokens, string $trigger = 'auto'): self
    {
        return new self(self::CONTEXT_COMPACTED, null, 'Context was automatically compacted', [
            'pre_tokens' => $preTokens,
            'trigger' => $trigger,
        ]);
    }

    /**
     * Create a compaction summary event.
     *
     * Contains the full summary that Claude continues with after compaction.
     * This replaces the previous context_compacted event - it has the same
     * metadata plus the full summary content.
     *
     * @param string $summary The full compaction summary text
     * @param array $metadata Compaction metadata (pre_tokens, trigger)
     */
    public static function compactionSummary(string $summary, array $metadata = []): self
    {
        return new self(self::COMPACTION_SUMMARY, null, $summary, [
            'pre_tokens' => $metadata['pre_tokens'] ?? null,
            'trigger' => $metadata['trigger'] ?? 'auto',
        ]);
    }

    /**
     * Create a screen created event.
     *
     * Emitted when a new screen (panel or chat) is created in the session.
     * Frontend uses this to refresh the screen tabs and switch to the new screen.
     *
     * @param string $screenId The UUID of the created screen
     * @param string $screenType The type of screen ('panel' or 'chat')
     * @param string|null $panelSlug The panel slug if type is 'panel'
     */
    public static function screenCreated(string $screenId, string $screenType, ?string $panelSlug = null): self
    {
        return new self(self::SCREEN_CREATED, null, null, [
            'screen_id' => $screenId,
            'screen_type' => $screenType,
            'panel_slug' => $panelSlug,
        ]);
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'block_index' => $this->blockIndex,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'event_id' => $this->eventId,
        ], fn($v) => $v !== null);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
