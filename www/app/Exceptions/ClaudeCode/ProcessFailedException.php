<?php

namespace App\Exceptions\ClaudeCode;

class ProcessFailedException extends ClaudeCodeException
{
    public function __construct(int $exitCode, string $stderr, array $context = [])
    {
        parent::__construct(
            "Claude Code process failed with exit code {$exitCode}: {$stderr}",
            $exitCode,
            null,
            array_merge($context, ['stderr' => $stderr])
        );
    }
}
