<?php

namespace App\Exceptions\ClaudeCode;

use Exception;

class ClaudeCodeException extends Exception
{
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null,
        protected array $context = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function report(): void
    {
        if (config('claude.logging.enabled')) {
            logger()->log(
                config('claude.logging.level'),
                $this->getMessage(),
                $this->context
            );
        }
    }
}
