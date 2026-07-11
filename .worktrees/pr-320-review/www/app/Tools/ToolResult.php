<?php

namespace App\Tools;

class ToolResult
{
    public function __construct(
        public string $output,
        public bool $isError = false,
    ) {}

    public static function success(string $output): self
    {
        return new self($output, false);
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
