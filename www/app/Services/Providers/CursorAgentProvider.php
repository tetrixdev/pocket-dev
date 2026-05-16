<?php

namespace App\Services\Providers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AppSettingsService;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Cursor Agent CLI provider.
 *
 * Uses the `agent` CLI tool with streaming JSON output.
 * Cursor Agent manages its own conversation history via chat IDs.
 *
 * CLI mapping:
 *   claude --print           => agent -p
 *   --output-format stream-json => --output-format stream-json
 *   --model <model>          => --model <model>
 *   --resume <sessionId>     => --resume <chatId>
 *   --dangerously-skip-permissions => --force --trust
 *   --verbose                => --stream-partial-output
 *   --system-prompt          => (via stdin/workspace rules)
 */
class CursorAgentProvider extends AbstractCliProvider
{
    public function __construct(ModelRepository $models)
    {
        parent::__construct($models);
    }

    // ========================================================================
    // Provider identity
    // ========================================================================

    public function getProviderType(): string
    {
        return 'cursor_agent';
    }

    // ========================================================================
    // HasNativeSession implementation
    // ========================================================================

    public function getSessionId(Conversation $conversation): ?string
    {
        return $conversation->provider_session_id;
    }

    public function setSessionId(Conversation $conversation, string $sessionId): void
    {
        $conversation->provider_session_id = $sessionId;
    }

    // ========================================================================
    // Authentication
    // ========================================================================

    /**
     * Check if Cursor auth.json exists and contains valid credentials,
     * or if an API key is configured in settings.
     */
    public function hasCredentials(): bool
    {
        // Check API key in database
        $settings = app(AppSettingsService::class);
        if ($settings->hasCursorAgentApiKey()) {
            return true;
        }

        // Check auth file
        $home = getenv('HOME') ?: '/home/appuser';
        $authFile = $home . '/.config/cursor/auth.json';

        if (!is_readable($authFile)) {
            return false;
        }

        $content = @file_get_contents($authFile);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return false;
        }

        // Valid if has accessToken (Cursor OAuth format)
        if (!empty($data['accessToken'])) {
            return true;
        }

        return false;
    }

    /**
     * Cursor authentication is handled via ~/.config/cursor/auth.json
     * (set up via `agent login` for subscription) or API key in database.
     */
    public function isAuthenticated(): bool
    {
        return $this->hasCredentials();
    }

    // ========================================================================
    // Template method implementations
    // ========================================================================

    protected function isCliBinaryAvailable(): bool
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $agentPath = $home . '/.local/bin/agent';

        // Check specific known path first (avoids false positives from generic 'agent' name)
        if (file_exists($agentPath) && is_executable($agentPath)) {
            return true;
        }

        // Fallback to which
        $output = [];
        $returnCode = 0;
        exec('which agent 2>/dev/null', $output, $returnCode);
        return $returnCode === 0 && !empty($output);
    }

    protected function hasAuthCredentials(): bool
    {
        return $this->hasCredentials();
    }

    protected function getAuthRequiredError(): string
    {
        return 'CURSOR_AGENT_AUTH_REQUIRED:Cursor Agent authentication required. Please set up authentication at /cursor/auth';
    }

    protected function buildCliCommand(
        Conversation $conversation,
        array $options
    ): string {
        $model = $conversation->model ?? config('ai.providers.cursor_agent.default_model', 'auto');

        // Sync MCP servers from Claude Code config to Cursor config
        $workingDir = $conversation->working_directory ?? '/workspace';
        $this->syncMcpServersFromClaudeCode($workingDir);

        // Ensure ~/.cursor directories are writable by www-data (queue worker)
        // The agent CLI creates project-specific state dirs under ~/.cursor/projects/
        $home = getenv('HOME') ?: '/home/appuser';
        $this->ensureCursorDirectories($home);

        // Use absolute path because the queue worker's PATH may not include ~/.local/bin
        $agentBin = $home . '/.local/bin/agent';
        if (!file_exists($agentBin)) {
            // Fallback to which
            $whichResult = shell_exec('which agent 2>/dev/null');
            $agentBin = ($whichResult !== null ? trim($whichResult) : '') ?: 'agent';
        }

        $parts = [
            $agentBin,
            '-p',
            '--output-format', 'stream-json',
            '--model', escapeshellarg($model),
            '--force',
            '--trust',
            '--approve-mcps',
        ];

        // Use --resume for conversation continuity
        $sessionId = $this->getSessionId($conversation);
        if (!empty($sessionId)) {
            $parts[] = '--resume';
            $parts[] = escapeshellarg($sessionId);
        }

        // Set workspace directory
        $parts[] = '--workspace';
        $parts[] = escapeshellarg($workingDir);

        return implode(' ', $parts);
    }

    protected function prepareProcessInput(string $command, string $userMessage): array
    {
        // Cursor Agent takes user message via stdin (same as Claude Code)
        return [
            'command' => $command,
            'stdin' => $userMessage,
        ];
    }

    protected function buildEnvironment(Conversation $conversation, array $options): array
    {
        $env = parent::buildEnvironment($conversation, $options);

        // Inject API key if configured (for API key mode, not subscription)
        $settings = app(AppSettingsService::class);
        $apiKey = $settings->getCursorAgentApiKey();
        if (!empty($apiKey)) {
            $env['CURSOR_API_KEY'] = $apiKey;
        }

        return $env;
    }

    protected function initParseState(): array
    {
        return [
            'blockIndex' => 0,
            'textStarted' => false,
            'thinkingStarted' => false,
            'currentToolUse' => null,
            'sessionId' => null,
            'totalCost' => null,
            'inputTokens' => 0,
            'outputTokens' => 0,
        ];
    }

    protected function getSessionIdFromState(array $state): ?string
    {
        return $state['sessionId'];
    }

    protected function classifyEventForTimeout(array $parsedData): array
    {
        $peekType = $parsedData['type'] ?? '';
        $subtype = $parsedData['subtype'] ?? '';

        // Tool progress heartbeats
        if ($peekType === 'tool_progress') {
            return ['phase' => null, 'resetsTimer' => true, 'shouldSkip' => true];
        }

        $phase = null;
        $resetsTimer = true;

        switch ($peekType) {
            case 'thinking':
                $phase = 'streaming';
                break;

            case 'tool_call':
                $phase = ($subtype === 'started') ? 'tool_execution' : 'pending_response';
                break;

            case 'assistant':
                $phase = 'streaming';
                break;

            case 'user':
                $phase = 'pending_response';
                break;

            case 'result':
                $phase = 'streaming';
                break;

            case 'system':
                $phase = 'initial';
                break;
        }

        return ['phase' => $phase, 'resetsTimer' => $resetsTimer, 'shouldSkip' => false];
    }

    protected function shouldLogEvent(array $parsedData): bool
    {
        $verboseLogging = config('ai.providers.cursor_agent.verbose_logging', false);
        $eventType = $parsedData['type'] ?? null;
        return $verboseLogging || $eventType !== 'stream_event';
    }

    protected function closeOpenBlocks(array $state): Generator
    {
        if ($state['textStarted']) {
            yield StreamEvent::textStop($state['blockIndex']);
        }
        if ($state['thinkingStarted']) {
            yield StreamEvent::thinkingStop($state['blockIndex']);
        }
        if ($state['currentToolUse'] !== null) {
            yield StreamEvent::toolUseStop($state['blockIndex']);
        }
    }

    protected function emitUsage(array $state): Generator
    {
        if ($state['totalCost'] !== null || $state['inputTokens'] > 0) {
            yield StreamEvent::usage(
                $state['inputTokens'],
                $state['outputTokens'],
                null, // cacheCreation
                null, // cacheRead
                $state['totalCost']
            );
        }
    }

    protected function getCompletionSummary(array $state, int $exitCode): array
    {
        return [
            'exit_code' => $exitCode,
            'session_id' => $state['sessionId'],
            'total_cost' => $state['totalCost'],
            'input_tokens' => $state['inputTokens'],
            'output_tokens' => $state['outputTokens'],
        ];
    }

    /**
     * Cursor Agent manages its own history, so no session file sync needed.
     */
    public function syncAbortedMessage(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage
    ): bool {
        return false;
    }

    // ========================================================================
    // JSONL Parsing (Cursor Agent)
    // ========================================================================

    /**
     * Parse a JSONL line and yield StreamEvents.
     *
     * Cursor Agent uses a different stream format than Claude Code:
     * - system/init: Session init with session_id, model
     * - user: User message echo
     * - thinking/delta: Thinking text delta (streaming)
     * - thinking/completed: Thinking block done
     * - tool_call/started: Tool execution started (with tool details)
     * - tool_call/completed: Tool execution completed (with output)
     * - assistant: Full assistant message (with content blocks)
     * - result: Final result with session_id, usage stats
     * - error: Error message
     */
    protected function parseJsonLine(string $line, array &$state, ?array $preDecoded = null): Generator
    {
        $data = $preDecoded ?? json_decode($line, true);

        if (!is_array($data)) {
            // Cursor Agent may emit non-JSON lines (e.g., "S: Named models unavailable...")
            // Skip them gracefully instead of logging as errors
            $trimmed = trim($line);
            if ($trimmed !== '' && !str_starts_with($trimmed, '{')) {
                Log::channel('api')->debug('CursorAgentProvider: Non-JSON line from CLI', [
                    'line' => substr($trimmed, 0, 200),
                ]);
            }
            return;
        }

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'system':
                // Init event: capture session_id
                if (isset($data['session_id'])) {
                    $state['sessionId'] = $data['session_id'];
                }
                break;

            case 'user':
                // User message echo, nothing to emit to frontend
                break;

            case 'thinking':
                yield from $this->parseThinkingEvent($data, $state);
                break;

            case 'tool_call':
                yield from $this->parseToolCallEvent($data, $state);
                break;

            case 'assistant':
                yield from $this->parseAssistantMessage($data, $state);
                break;

            case 'result':
                yield from $this->parseResultEvent($data, $state);
                break;

            case 'error':
                $message = $data['message'] ?? ($data['error'] ?? 'Unknown error');
                yield StreamEvent::error($message);
                break;

            default:
                Log::channel('api')->debug('CursorAgentProvider: Unknown event type', [
                    'type' => $type,
                    'keys' => array_keys($data),
                ]);
        }
    }

    /**
     * Handle thinking events (thinking/delta, thinking/completed).
     */
    private function parseThinkingEvent(array $data, array &$state): Generator
    {
        $subtype = $data['subtype'] ?? '';

        if ($subtype === 'delta') {
            $text = $data['text'] ?? '';
            if ($text !== '') {
                // Close text block if open (thinking comes before text)
                if ($state['textStarted']) {
                    yield StreamEvent::textStop($state['blockIndex']);
                    $state['textStarted'] = false;
                    $state['blockIndex']++;
                }
                if (!$state['thinkingStarted']) {
                    yield StreamEvent::thinkingStart($state['blockIndex']);
                    $state['thinkingStarted'] = true;
                }
                yield StreamEvent::thinkingDelta($state['blockIndex'], $text);
            }
        } elseif ($subtype === 'completed') {
            if ($state['thinkingStarted']) {
                yield StreamEvent::thinkingStop($state['blockIndex']);
                $state['thinkingStarted'] = false;
                $state['blockIndex']++;
            }
        }
    }

    /**
     * Handle tool_call events (tool_call/started, tool_call/completed).
     */
    private function parseToolCallEvent(array $data, array &$state): Generator
    {
        $subtype = $data['subtype'] ?? '';
        $callId = $data['call_id'] ?? ('tool_' . $state['blockIndex']);

        if ($subtype === 'started') {
            // Close any open text/thinking blocks
            if ($state['textStarted']) {
                yield StreamEvent::textStop($state['blockIndex']);
                $state['textStarted'] = false;
                $state['blockIndex']++;
            }
            if ($state['thinkingStarted']) {
                yield StreamEvent::thinkingStop($state['blockIndex']);
                $state['thinkingStarted'] = false;
                $state['blockIndex']++;
            }

            // Determine tool name from the tool_call structure
            // Format: {"tool_call": {"shellToolCall": {..., "description": "..."}, ...}}
            $toolCall = $data['tool_call'] ?? [];
            $toolName = 'unknown';
            $inputJson = '{}';

            foreach ($toolCall as $key => $value) {
                if (is_array($value)) {
                    // Convert camelCase key to readable name (e.g., shellToolCall -> shell)
                    $toolName = str_replace('ToolCall', '', $key);
                    $toolName = str_replace('toolCall', '', $toolName) ?: $key;

                    // Extract relevant args for display
                    $args = $value['args'] ?? $value;
                    // Remove internal fields, keep user-visible ones
                    unset($args['toolCallId'], $args['skipApproval'], $args['simpleCommands'],
                          $args['hasInputRedirect'], $args['hasOutputRedirect'], $args['parsingResult'],
                          $args['fileOutputThresholdBytes'], $args['isBackground'], $args['timeoutBehavior'],
                          $args['hardTimeout'], $args['closeStdin']);
                    $inputJson = json_encode($args);
                    break;
                }
            }

            $state['currentToolUse'] = [
                'id' => $callId,
                'name' => $toolName,
            ];

            yield StreamEvent::toolUseStart($state['blockIndex'], $callId, $toolName);
            yield StreamEvent::toolUseDelta($state['blockIndex'], $inputJson);

        } elseif ($subtype === 'completed') {
            // Tool completed with output
            if ($state['currentToolUse'] !== null) {
                yield StreamEvent::toolUseStop($state['blockIndex']);
                $state['currentToolUse'] = null;
                $state['blockIndex']++;
            }

            // Emit tool result
            $output = $data['output'] ?? '';
            if (is_array($output)) {
                $output = json_encode($output);
            }
            yield StreamEvent::toolResult($callId, (string) $output, false);
        }
    }

    /**
     * Handle result event with usage stats.
     */
    private function parseResultEvent(array $data, array &$state): Generator
    {
        if (isset($data['session_id'])) {
            $state['sessionId'] = $data['session_id'];
        }
        if (isset($data['chat_id'])) {
            $state['sessionId'] = $data['chat_id'];
        }

        // Extract usage (Cursor uses camelCase: inputTokens, outputTokens)
        $usage = $data['usage'] ?? [];
        if (!empty($usage)) {
            $state['inputTokens'] = ($usage['inputTokens'] ?? $usage['input_tokens'] ?? 0)
                + ($usage['cacheReadTokens'] ?? $usage['cache_read_input_tokens'] ?? 0)
                + ($usage['cacheWriteTokens'] ?? $usage['cache_creation_input_tokens'] ?? 0);
            $state['outputTokens'] = $usage['outputTokens'] ?? $usage['output_tokens'] ?? 0;
        }

        if (isset($data['total_cost_usd'])) {
            $state['totalCost'] = (float) $data['total_cost_usd'];
        }

        Log::channel('api')->info('CursorAgentProvider: Result received', [
            'subtype' => $data['subtype'] ?? 'unknown',
            'is_error' => $data['is_error'] ?? false,
            'session_id' => $state['sessionId'],
            'duration_ms' => $data['duration_ms'] ?? null,
        ]);

        if (!empty($data['is_error'])) {
            $errorMsg = $data['result'] ?? null;
            if (is_array($errorMsg)) {
                $errorMsg = json_encode($errorMsg);
            }
            if (($errorMsg === null || $errorMsg === '') && !empty($data['errors']) && is_array($data['errors'])) {
                $firstError = $data['errors'][0] ?? null;
                $errorMsg = is_array($firstError) ? json_encode($firstError) : $firstError;
            }
            if ($errorMsg === null || $errorMsg === '') {
                $errorMsg = 'Cursor Agent error: ' . ($data['subtype'] ?? 'unknown error');
            }
            $errorMsg = is_string($errorMsg) ? $errorMsg : (string) $errorMsg;

            Log::channel('api')->error('CursorAgentProvider: CLI returned error result', [
                'error_message' => $errorMsg,
            ]);
            yield StreamEvent::error($errorMsg);
        }
    }

    /**
     * Parse assistant message with content blocks.
     * Handles text, thinking, tool_use, and tool_result blocks.
     */
    private function parseAssistantMessage(array $data, array &$state): Generator
    {
        $message = $data['message'] ?? [];
        if (!is_array($message)) {
            return;
        }
        $content = $message['content'] ?? [];
        if (!is_array($content)) {
            return;
        }

        foreach ($content as $block) {
            $blockType = $block['type'] ?? '';

            switch ($blockType) {
                case 'text':
                    // Close thinking if open
                    if ($state['thinkingStarted']) {
                        yield StreamEvent::thinkingStop($state['blockIndex']);
                        $state['thinkingStarted'] = false;
                        $state['blockIndex']++;
                    }
                    if (!$state['textStarted']) {
                        yield StreamEvent::textStart($state['blockIndex']);
                        $state['textStarted'] = true;
                    }
                    $text = $block['text'] ?? '';
                    if ($text !== '') {
                        yield StreamEvent::textDelta($state['blockIndex'], $text);
                    }
                    break;

                case 'thinking':
                    if ($state['textStarted']) {
                        yield StreamEvent::textStop($state['blockIndex']);
                        $state['textStarted'] = false;
                        $state['blockIndex']++;
                    }
                    if (!$state['thinkingStarted']) {
                        yield StreamEvent::thinkingStart($state['blockIndex']);
                        $state['thinkingStarted'] = true;
                    }
                    $thinking = $block['thinking'] ?? '';
                    if ($thinking !== '') {
                        yield StreamEvent::thinkingDelta($state['blockIndex'], $thinking);
                    }
                    break;

                case 'tool_use':
                    if ($state['textStarted']) {
                        yield StreamEvent::textStop($state['blockIndex']);
                        $state['textStarted'] = false;
                        $state['blockIndex']++;
                    }
                    if ($state['thinkingStarted']) {
                        yield StreamEvent::thinkingStop($state['blockIndex']);
                        $state['thinkingStarted'] = false;
                        $state['blockIndex']++;
                    }

                    $toolId = $block['id'] ?? 'tool_' . $state['blockIndex'];
                    $toolName = $block['name'] ?? 'unknown';
                    $input = $block['input'] ?? [];

                    yield StreamEvent::toolUseStart($state['blockIndex'], $toolId, $toolName);
                    $inputJson = is_array($input) ? json_encode($input) : (string) $input;
                    yield StreamEvent::toolUseDelta($state['blockIndex'], $inputJson);
                    yield StreamEvent::toolUseStop($state['blockIndex']);
                    $state['blockIndex']++;
                    break;

                case 'tool_result':
                    $toolId = $block['tool_use_id'] ?? 'unknown';
                    $resultContent = $block['content'] ?? '';
                    $isError = $block['is_error'] ?? false;
                    yield StreamEvent::toolResult($toolId, $resultContent, $isError);
                    break;
            }
        }
    }

    // ========================================================================
    // Directory setup
    // ========================================================================

    /**
     * Ensure Cursor CLI directories exist and are writable by the www-data group.
     * The agent CLI creates project-specific state under ~/.cursor/projects/
     * and chat history under ~/.cursor/chats/, which need group write access
     * when the queue worker (www-data) runs the CLI.
     */
    private function ensureCursorDirectories(string $home): void
    {
        $dirs = [
            $home . '/.cursor',
            $home . '/.cursor/projects',
            $home . '/.cursor/chats',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            } elseif (!is_writable($dir)) {
                @chmod($dir, 0770);
            }
        }
    }

    // ========================================================================
    // MCP server synchronization
    // ========================================================================

    /**
     * Sync MCP servers from Claude Code config (~/.claude.json) to Cursor config (~/.cursor/mcp.json).
     *
     * Both use JSON format with "mcpServers" key, making this simpler than the Codex TOML conversion.
     * Reads global and project-specific MCP servers from Claude Code config and writes them
     * to Cursor's mcp.json.
     */
    private function syncMcpServersFromClaudeCode(string $workingDir): void
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $claudeConfigPath = $home . '/.claude.json';
        $cursorConfigPath = $home . '/.cursor/mcp.json';

        if (!is_readable($claudeConfigPath)) {
            return; // No Claude config, nothing to sync
        }

        try {
            $claudeConfig = json_decode(file_get_contents($claudeConfigPath), true);
            if (!is_array($claudeConfig)) {
                return;
            }

            $mcpServers = [];

            // 1. Collect global MCP servers (supports both array and keyed-object formats)
            if (isset($claudeConfig['mcpServers']) && is_array($claudeConfig['mcpServers'])) {
                foreach ($claudeConfig['mcpServers'] as $key => $server) {
                    if (!is_array($server) || !isset($server['command'])) {
                        continue;
                    }
                    $name = isset($server['name']) ? (string) $server['name'] : (string) $key;
                    $mcpServers[$name] = $server;
                }
            }

            // 2. Collect project-specific MCP servers (deepest matching path wins)
            if (isset($claudeConfig['projects']) && is_array($claudeConfig['projects'])) {
                $bestMatch = null;
                $bestMatchLength = -1;

                foreach ($claudeConfig['projects'] as $projectPath => $projectConfig) {
                    $normalizedPath = rtrim($projectPath, '/');
                    if (($workingDir === $normalizedPath || str_starts_with($workingDir, $normalizedPath . '/'))
                        && isset($projectConfig['mcpServers'])
                        && strlen($normalizedPath) > $bestMatchLength) {
                        $bestMatch = $projectConfig;
                        $bestMatchLength = strlen($normalizedPath);
                    }
                }

                if ($bestMatch !== null) {
                    foreach ($bestMatch['mcpServers'] as $name => $server) {
                        if (isset($server['command'])) {
                            // Project-level overrides global
                            $mcpServers[$name] = $server;
                        }
                    }
                }
            }

            // 3. Read existing Cursor MCP config (preserve non-mcpServers keys)
            $existingConfig = [];
            $hadMcpServers = false;
            if (is_readable($cursorConfigPath)) {
                $existingConfig = json_decode(file_get_contents($cursorConfigPath), true) ?? [];
                $hadMcpServers = isset($existingConfig['mcpServers']);
            }

            // If Claude has no MCP servers and Cursor has no existing MCP servers, skip
            if (empty($mcpServers) && !$hadMcpServers) {
                return;
            }

            // 4. Build the new config, preserving any non-MCP keys in existing config
            $newConfig = $existingConfig;
            $newConfig['mcpServers'] = $mcpServers;

            // 5. Write mcp.json
            $dir = dirname($cursorConfigPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $content = json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            if (file_put_contents($cursorConfigPath, $content, LOCK_EX) === false) {
                Log::channel('api')->error('CursorAgentProvider: Failed to write Cursor MCP config', [
                    'file' => $cursorConfigPath,
                ]);
                return;
            }

            Log::channel('api')->info('CursorAgentProvider: Synced MCP servers from Claude Code', [
                'server_count' => count($mcpServers),
                'servers' => array_keys($mcpServers),
            ]);

        } catch (\Throwable $e) {
            Log::channel('api')->warning('CursorAgentProvider: Failed to sync MCP servers', [
                'error' => $e->getMessage(),
            ]);
            // Non-fatal: Cursor Agent can still work without MCP servers
        }
    }
}
