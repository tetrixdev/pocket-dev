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
        $model = $conversation->model ?? config('ai.providers.cursor_agent.default_model', 'claude-4.6-sonnet-medium');

        // Sync MCP servers from Claude Code config to Cursor config
        $workingDir = $conversation->working_directory ?? '/workspace';
        $this->syncMcpServersFromClaudeCode($workingDir);

        $parts = [
            'agent',
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
            'gotStreamEvents' => false,
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

        // Tool progress heartbeats
        if ($peekType === 'tool_progress') {
            return ['phase' => null, 'resetsTimer' => true, 'shouldSkip' => true];
        }

        $phase = null;
        $resetsTimer = true;

        if ($peekType === 'stream_event') {
            $event = $parsedData['event'] ?? [];
            $streamEventType = is_array($event) ? ($event['type'] ?? '') : '';
            if ($streamEventType === 'content_block_delta') {
                $phase = 'streaming';
            }
        } elseif ($peekType === 'assistant') {
            $message = is_array($parsedData['message'] ?? null) ? $parsedData['message'] : [];
            $stopReason = $message['stop_reason'] ?? null;
            if ($stopReason === 'tool_use') {
                $phase = 'tool_execution';
            }
        } elseif ($peekType === 'user') {
            $phase = 'pending_response';
        } elseif ($peekType === 'result') {
            // Result indicates completion
            $phase = 'streaming';
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
     * Start with Claude Code compatible parser since Cursor's stream-json format
     * is expected to be similar. Unknown events are logged and skipped defensively.
     *
     * Known event types (based on Claude Code stream-json):
     * - user: User message echo
     * - assistant: Assistant message (with content blocks)
     * - result: Final result with session_id, cost, etc.
     * - stream_event: Streaming deltas (content_block_start/delta/stop, message_start/delta/stop)
     * - system: System events (compaction, etc.)
     */
    protected function parseJsonLine(string $line, array &$state, ?array $preDecoded = null): Generator
    {
        $data = $preDecoded ?? json_decode($line, true);

        if (!is_array($data)) {
            Log::channel('api')->debug('CursorAgentProvider: Failed to parse JSON line', [
                'line' => substr($line, 0, 200),
            ]);
            return;
        }

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'user':
                // User message echo, nothing to emit to frontend
                break;

            case 'assistant':
                if (!$state['gotStreamEvents']) {
                    yield from $this->parseAssistantMessage($data, $state);
                }
                break;

            case 'result':
                if (isset($data['session_id'])) {
                    $state['sessionId'] = $data['session_id'];
                }
                // Also check for chat_id (Cursor-specific)
                if (isset($data['chat_id'])) {
                    $state['sessionId'] = $data['chat_id'];
                }
                if (isset($data['total_cost_usd'])) {
                    $state['totalCost'] = (float) $data['total_cost_usd'];
                }
                Log::channel('api')->info('CursorAgentProvider: Result received', [
                    'subtype' => $data['subtype'] ?? 'unknown',
                    'is_error' => $data['is_error'] ?? false,
                    'session_id' => $state['sessionId'],
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
                break;

            case 'stream_event':
                $state['gotStreamEvents'] = true;
                yield from $this->parseStreamEvent($data, $state);
                break;

            case 'system':
                if (isset($data['session_id'])) {
                    $state['sessionId'] = $data['session_id'];
                }
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

    private function parseStreamEvent(array $data, array &$state): Generator
    {
        $event = $data['event'] ?? [];
        if (!is_array($event)) {
            Log::channel('api')->debug('CursorAgentProvider: Non-array event in stream_event');
            return;
        }
        $eventType = $event['type'] ?? '';

        switch ($eventType) {
            case 'content_block_start':
                $contentBlock = $event['content_block'] ?? [];
                $blockType = $contentBlock['type'] ?? '';

                if ($blockType === 'thinking') {
                    if ($state['textStarted']) {
                        yield StreamEvent::textStop($state['blockIndex']);
                        $state['textStarted'] = false;
                        $state['blockIndex']++;
                    }
                    if (!$state['thinkingStarted']) {
                        yield StreamEvent::thinkingStart($state['blockIndex']);
                        $state['thinkingStarted'] = true;
                    }
                } elseif ($blockType === 'tool_use') {
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
                    $toolId = $contentBlock['id'] ?? 'tool_' . $state['blockIndex'];
                    $toolName = $contentBlock['name'] ?? 'unknown';
                    $state['currentToolUse'] = [
                        'id' => $toolId,
                        'name' => $toolName,
                        'inputJson' => '',
                    ];
                    yield StreamEvent::toolUseStart($state['blockIndex'], $toolId, $toolName);
                } elseif ($blockType === 'text') {
                    if ($state['thinkingStarted']) {
                        yield StreamEvent::thinkingStop($state['blockIndex']);
                        $state['thinkingStarted'] = false;
                        $state['blockIndex']++;
                    }
                    if (!$state['textStarted']) {
                        yield StreamEvent::textStart($state['blockIndex']);
                        $state['textStarted'] = true;
                    }
                }
                break;

            case 'content_block_delta':
                $delta = $event['delta'] ?? [];
                $deltaType = $delta['type'] ?? '';

                if ($deltaType === 'thinking_delta') {
                    $thinking = $delta['thinking'] ?? '';
                    if ($thinking !== '') {
                        if (!$state['thinkingStarted']) {
                            yield StreamEvent::thinkingStart($state['blockIndex']);
                            $state['thinkingStarted'] = true;
                        }
                        yield StreamEvent::thinkingDelta($state['blockIndex'], $thinking);
                    }
                } elseif ($deltaType === 'text_delta') {
                    $text = $delta['text'] ?? '';
                    if ($text !== '') {
                        if (!$state['textStarted']) {
                            yield StreamEvent::textStart($state['blockIndex']);
                            $state['textStarted'] = true;
                        }
                        yield StreamEvent::textDelta($state['blockIndex'], $text);
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    $partialJson = $delta['partial_json'] ?? '';
                    if ($partialJson !== '' && $state['currentToolUse'] !== null) {
                        $state['currentToolUse']['inputJson'] .= $partialJson;
                        yield StreamEvent::toolUseDelta($state['blockIndex'], $partialJson);
                    }
                } elseif ($deltaType === 'signature_delta') {
                    $signature = $delta['signature'] ?? '';
                    if ($signature !== '') {
                        yield StreamEvent::thinkingSignature($state['blockIndex'], $signature);
                    }
                }
                break;

            case 'content_block_stop':
                if ($state['thinkingStarted']) {
                    yield StreamEvent::thinkingStop($state['blockIndex']);
                    $state['thinkingStarted'] = false;
                    $state['blockIndex']++;
                } elseif ($state['textStarted']) {
                    yield StreamEvent::textStop($state['blockIndex']);
                    $state['textStarted'] = false;
                    $state['blockIndex']++;
                } elseif ($state['currentToolUse'] !== null) {
                    yield StreamEvent::toolUseStop($state['blockIndex']);
                    $state['currentToolUse'] = null;
                    $state['blockIndex']++;
                }
                break;

            case 'message_start':
            case 'message_delta':
                $message = $event['message'] ?? [];
                $usage = $message['usage'] ?? [];
                if (!empty($usage)) {
                    $state['inputTokens'] = ($usage['input_tokens'] ?? 0)
                        + ($usage['cache_creation_input_tokens'] ?? 0)
                        + ($usage['cache_read_input_tokens'] ?? 0);
                    $state['outputTokens'] = $usage['output_tokens'] ?? 0;
                }
                break;

            case 'message_stop':
                break;

            default:
                Log::channel('api')->debug('CursorAgentProvider: Unknown stream event type', [
                    'event_type' => $eventType,
                ]);
        }
    }

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
                    if (!$state['thinkingStarted']) {
                        if ($state['textStarted']) {
                            yield StreamEvent::textStop($state['blockIndex']);
                            $state['textStarted'] = false;
                            $state['blockIndex']++;
                        }
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
