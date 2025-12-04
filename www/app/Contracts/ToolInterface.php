<?php

namespace App\Contracts;

use App\Tools\ExecutionContext;
use App\Tools\ToolResult;

interface ToolInterface
{
    /**
     * Execute the tool with the given input.
     *
     * @param array $input The tool input parameters
     * @param ExecutionContext $context Execution context (working directory, etc.)
     * @return ToolResult The result of the tool execution
     */
    public function execute(array $input, ExecutionContext $context): ToolResult;
}
