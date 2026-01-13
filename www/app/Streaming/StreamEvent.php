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

    public function __construct(
        public string $type,
        public ?int $blockIndex = null,
        public ?string $content = null,
        public ?array $metadata = null,
    ) {}

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
        ?int $contextWindowSize = null
    ): self {
        $metadata = array_filter([
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_creation_tokens' => $cacheCreation,
            'cache_read_tokens' => $cacheRead,
            'cost' => $cost,
            'context_window_size' => $contextWindowSize,
        ], fn($v) => $v !== null);

        // Calculate context percentage if we have both tokens and window size
        // Uses input + output for better estimate (slightly overestimates due to thinking tokens)
        if ($inputTokens > 0 && $contextWindowSize > 0) {
            $totalContext = $inputTokens + $outputTokens;
            $metadata['context_percentage'] = min(100, round(($totalContext / $contextWindowSize) * 100, 1));
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

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'block_index' => $this->blockIndex,
            'content' => $this->content,
            'metadata' => $this->metadata,
        ], fn($v) => $v !== null);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
