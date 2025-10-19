<?php

namespace App\Exceptions\ClaudeCode;

class JSONDecodeException extends ClaudeCodeException
{
    public function __construct(string $output, string $error)
    {
        parent::__construct(
            "Failed to parse Claude Code JSON output: {$error}",
            500,
            null,
            ['output' => $output, 'json_error' => $error]
        );
    }
}
