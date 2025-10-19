<?php

namespace App\Exceptions\ClaudeCode;

class TimeoutException extends ClaudeCodeException
{
    public function __construct(int $timeout)
    {
        parent::__construct(
            "Claude Code operation timed out after {$timeout} seconds",
            408,
            null,
            ['timeout' => $timeout]
        );
    }
}
