<?php

namespace App\Tools;

class ToolResult
{
    /**
     * When true, the stream loop should end the parent turn immediately after
     * this tool result is saved — without making another AI round-trip.
     * Used by SubAgentTool in background mode so the parent conversation
     * becomes interactive again as soon as the child job is dispatched.
     */
    public bool $endTurn = false;

    /**
     * Human-readable text saved as a synthetic closing assistant message
     * when $endTurn is true. Keeps the conversation history well-formed
     * (prevents two consecutive user messages on the next turn).
     */
    public ?string $endTurnMessage = null;

    public function __construct(
        public string $output,
        public bool $isError = false,
    ) {}

    public static function success(string $output): self
    {
        return new self($output, false);
    }

    /**
     * Like success(), but signals the stream loop to end the parent turn
     * immediately without a second AI call.
     *
     * @param string $output       JSON output returned as the tool result content
     * @param string $endTurnMessage  Text for the synthetic closing assistant message
     */
    public static function successEndTurn(string $output, string $endTurnMessage): self
    {
        $result = new self($output, false);
        $result->endTurn = true;
        $result->endTurnMessage = $endTurnMessage;
        return $result;
    }

    public static function error(string $message): self
    {
        return new self($message, true);
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'is_error' => $this->isError,
        ];
    }
}
