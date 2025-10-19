<?php

namespace App\Exceptions\ClaudeCode;

class CLINotFoundException extends ClaudeCodeException
{
    public function __construct()
    {
        parent::__construct(
            'Claude Code CLI not found. Please ensure it is installed and in your PATH.',
            404
        );
    }
}
