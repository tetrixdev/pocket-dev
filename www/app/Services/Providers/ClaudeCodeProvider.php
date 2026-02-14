<?php

namespace App\Services\Providers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Claude Code CLI provider.
 *
 * Uses the `claude` CLI tool with streaming JSON output.
 * Claude Code manages its own conversation history via session IDs.
 */
class ClaudeCodeProvider extends AbstractCliProvider
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
        return 'claude_code';
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
     * Check if OAuth credentials exist and are readable (from `claude login`).
     */
    public function hasOAuthCredentials(): bool
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $credentialsFile = $home . '/.claude/.credentials.json';
        return is_readable($credentialsFile);
    }

    /**
     * Check if OAuth authentication is configured (from `claude login`).
     */
    public function isAuthenticated(): bool
    {
        return $this->hasOAuthCredentials();
    }

    // ========================================================================
    // Template method implementations
    // ========================================================================

    protected function isCliBinaryAvailable(): bool
    {
        $output = [];
        $returnCode = 0;
        exec('which claude 2>/dev/null', $output, $returnCode);
        return $returnCode === 0 && !empty($output);
    }

    protected function hasAuthCredentials(): bool
    {
        return $this->isAuthenticated();
    }

    protected function getAuthRequiredError(): string
    {
        return 'CLAUDE_CODE_AUTH_REQUIRED:Claude Code authentication required. Please run "claude login" in the container.';
    }

    protected function buildCliCommand(
        Conversation $conversation,
        array $options
    ): string {
        $model = config('ai.providers.claude_code.override_model')
            ?: $conversation->model
            ?: config('ai.providers.claude_code.default_model', 'opus');

        // Get global allowed tools setting (Setting::get already decodes JSON)
        $globalAllowedTools = \App\Models\Setting::get('chat.claude_code_allowed_tools', []);
        if (!is_array($globalAllowedTools)) {
            $globalAllowedTools = [];
        }

        // Get agent's allowed tools (if agent exists)
        $agent = $conversation->agent;
        $agentAllowedTools = $agent?->allowed_tools; // null = all, array = specific

        // Get enabled tools from NativeToolService (respects global disabled state)
        $nativeToolService = app(\App\Services\NativeToolService::class);
        $enabledToolNames = $nativeToolService->getEnabledToolNames('claude_code');

        // Start with enabled tools (excludes globally disabled)
        $allowedTools = $enabledToolNames;

        // Filter by global setting if specified
        if (!empty($globalAllowedTools)) {
            $allowedTools = array_values(array_intersect($allowedTools, $globalAllowedTools));
        }

        // Filter by agent's allowed tools if specified (case-insensitive)
        if ($agentAllowedTools !== null) {
            $agentAllowedLower = array_map('strtolower', $agentAllowedTools);
            $allowedTools = array_values(array_filter($allowedTools, function ($tool) use ($agentAllowedLower) {
                return in_array(strtolower($tool), $agentAllowedLower, true);
            }));
        }

        $parts = [
            'claude',
            '--print',
            '--verbose',
            '--output-format', 'stream-json',
            '--include-partial-messages',
            '--dangerously-skip-permissions',
            '--setting-sources', 'user,project,local',
            '--settings', escapeshellarg('/home/appuser/.claude/settings.json'),
            '--model', escapeshellarg($model),
        ];

        // Use --resume for conversation continuity
        $sessionId = $this->getSessionId($conversation);
        if (!empty($sessionId)) {
            $parts[] = '--resume';
            $parts[] = escapeshellarg($sessionId);
        }

        // Add tools restriction if configured
        if (!empty($allowedTools)) {
            $parts[] = '--tools';
            $parts[] = escapeshellarg(implode(',', $allowedTools));
        }

        // Add system prompt if provided
        if (!empty($options['system'])) {
            $parts[] = '--system-prompt';
            $parts[] = escapeshellarg($options['system']);
        }

        return implode(' ', $parts);
    }

    protected function prepareProcessInput(string $command, string $userMessage): array
    {
        // Claude Code takes user message via stdin
        return [
            'command' => $command,
            'stdin' => $userMessage,
        ];
    }

    protected function buildEnvironment(Conversation $conversation, array $options): array
    {
        $env = parent::buildEnvironment($conversation, $options);

        // Set thinking tokens via environment variable (for extended thinking)
        $thinkingTokens = $this->getThinkingTokens($conversation);
        if ($thinkingTokens > 0) {
            $env['MAX_THINKING_TOKENS'] = (string) $thinkingTokens;
        }

        // Disable CLI internal retries
        $env['CLAUDE_CODE_MAX_RETRIES'] = '0';

        // Enable tool_progress heartbeats during tool execution
        $env['CLAUDE_CODE_CONTAINER_ID'] = 'pocketdev';

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
            'awaitingCompactionSummary' => false,
            'compactionMetadata' => null,
            'inputTokens' => 0,
            'outputTokens' => 0,
            'cacheCreationTokens' => 0,
            'cacheReadTokens' => 0,
        ];
    }

    protected function getSessionIdFromState(array $state): ?string
    {
        return $state['sessionId'];
    }

    protected function classifyEventForTimeout(array $parsedData): array
    {
        $peekType = $parsedData['type'] ?? '';

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
        } elseif ($peekType === 'system') {
            $subtype = $parsedData['subtype'] ?? '';
            $systemStatus = $parsedData['status'] ?? '';
            if ($subtype === 'compact_boundary' || $systemStatus === 'compacting') {
                $phase = 'pending_response';
            }
        }

        return ['phase' => $phase, 'resetsTimer' => $resetsTimer, 'shouldSkip' => false];
    }

    protected function shouldLogEvent(array $parsedData): bool
    {
        $verboseLogging = config('ai.providers.claude_code.verbose_logging', false);
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
                $state['cacheCreationTokens'] ?: null,
                $state['cacheReadTokens'] ?: null,
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

    protected function onProcessComplete(Conversation $conversation, array $state, int $exitCode): void
    {
        // Fix permissions on Claude config files so PHP-FPM (www-data) can read/write them
        $home = getenv('HOME') ?: '/home/appuser';
        @chmod($home . '/.claude.json', 0660);
        @chmod($home . '/.claude.json.backup', 0660);
        @chmod($home . '/.claude/settings.json', 0664);
    }

    // ========================================================================
    // JSONL Parsing (Claude Code specific)
    // ========================================================================

    protected function parseJsonLine(string $line, array &$state, ?array $preDecoded = null): Generator
    {
        $data = $preDecoded ?? json_decode($line, true);

        if (!is_array($data)) {
            Log::channel('api')->debug('ClaudeCodeProvider: Non-array JSON line', [
                'line' => substr($line, 0, 500),
                'decoded_type' => gettype($data),
            ]);
            return;
        }

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'user':
                yield from $this->parseUserMessage($data, $state);
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
                if (isset($data['total_cost_usd'])) {
                    $state['totalCost'] = (float) $data['total_cost_usd'];
                }
                Log::channel('api')->info('ClaudeCodeProvider: Result received', [
                    'subtype' => $data['subtype'] ?? 'unknown',
                    'is_error' => $data['is_error'] ?? false,
                    'session_id' => $state['sessionId'],
                    'total_cost' => $state['totalCost'],
                    'num_turns' => $data['num_turns'] ?? null,
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
                        $errorMsg = 'Claude Code error: ' . ($data['subtype'] ?? 'unknown error');
                    }
                    $errorMsg = is_string($errorMsg) ? $errorMsg : (string) $errorMsg;

                    Log::channel('api')->error('ClaudeCodeProvider: CLI returned error result', [
                        'error_message' => $errorMsg,
                        'subtype' => $data['subtype'] ?? 'unknown',
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
                if (($data['subtype'] ?? '') === 'compact_boundary') {
                    $compactMetadata = $data['compact_metadata'] ?? [];
                    $preTokens = is_array($compactMetadata) ? ($compactMetadata['pre_tokens'] ?? null) : null;
                    $trigger = is_array($compactMetadata) ? ($compactMetadata['trigger'] ?? 'auto') : 'auto';
                    Log::channel('api')->info('ClaudeCodeProvider: Context compaction detected', [
                        'pre_tokens' => $preTokens,
                        'trigger' => $trigger,
                    ]);
                    $state['awaitingCompactionSummary'] = true;
                    $state['compactionMetadata'] = [
                        'pre_tokens' => $preTokens,
                        'trigger' => $trigger,
                    ];
                }
                break;

            default:
                Log::channel('api')->debug('ClaudeCodeProvider: Unknown event type', [
                    'type' => $type,
                ]);
        }
    }

    private function parseStreamEvent(array $data, array &$state): Generator
    {
        $event = $data['event'] ?? [];
        if (!is_array($event)) {
            Log::channel('api')->debug('ClaudeCodeProvider: Non-array event in stream_event', [
                'event_type' => gettype($event),
                'event_preview' => is_string($event) ? substr($event, 0, 200) : null,
            ]);
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
                    $state['cacheCreationTokens'] = $usage['cache_creation_input_tokens'] ?? 0;
                    $state['cacheReadTokens'] = $usage['cache_read_input_tokens'] ?? 0;
                }
                break;

            case 'message_stop':
                break;
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

    private function parseUserMessage(array $data, array &$state): Generator
    {
        $message = $data['message'] ?? [];
        if (!is_array($message)) {
            return;
        }
        $content = $message['content'] ?? [];

        // Check if this is a compaction summary
        if ($state['awaitingCompactionSummary']) {
            $state['awaitingCompactionSummary'] = false;
            $summaryParts = [];

            if (is_string($content)) {
                $summaryParts[] = $content;
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $summaryParts[] = $block['text'] ?? '';
                    }
                }
            }

            $summaryText = trim(implode("\n", array_filter($summaryParts, fn($part) => $part !== '')));
            $metadata = $state['compactionMetadata'] ?? [];
            $state['compactionMetadata'] = null;

            if ($summaryText !== '') {
                Log::channel('api')->info('ClaudeCodeProvider: Compaction summary captured', [
                    'summary_length' => strlen($summaryText),
                    'pre_tokens' => $metadata['pre_tokens'] ?? null,
                ]);
                yield StreamEvent::compactionSummary($summaryText, $metadata);
            }

            return;
        }

        // Handle string content
        if (is_string($content)) {
            if (preg_match('/<local-command-stdout>(.*?)<\/local-command-stdout>/s', $content, $matches)) {
                $output = trim($matches[1]);
                $command = null;
                if (preg_match('/<command-name>(.*?)<\/command-name>/', $content, $cmdMatch)) {
                    $command = $cmdMatch[1];
                }
                yield StreamEvent::systemInfo($output, $command);
            }
            return;
        }

        if (!is_array($content)) {
            return;
        }

        foreach ($content as $block) {
            $blockType = $block['type'] ?? '';

            if ($blockType === 'tool_result') {
                $toolId = $block['tool_use_id'] ?? 'unknown';
                $resultContent = $block['content'] ?? '';
                $isError = $block['is_error'] ?? false;

                if (is_string($resultContent)) {
                    yield StreamEvent::toolResult($toolId, $resultContent, $isError);
                } elseif (is_array($resultContent)) {
                    yield StreamEvent::toolResult($toolId, json_encode($resultContent), $isError);
                }
            }
        }
    }

    // ========================================================================
    // Session file sync (Claude Code specific)
    // ========================================================================

    /**
     * Sync aborted message to Claude Code's native JSONL session file.
     */
    public function syncAbortedMessage(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage
    ): bool {
        $sessionId = $this->getSessionId($conversation);

        if (empty($sessionId)) {
            Log::warning('ClaudeCodeProvider: No session ID for sync', [
                'conversation' => $conversation->uuid,
            ]);
            return false;
        }

        $workingDir = $conversation->working_directory ?? '/workspace';
        $sessionFile = $this->getSessionFilePath($workingDir, $sessionId);

        if (!$sessionFile) {
            Log::warning('ClaudeCodeProvider: Could not determine session file path', [
                'session_id' => $sessionId,
                'working_dir' => $workingDir,
            ]);
            return false;
        }

        Log::info('ClaudeCodeProvider: Syncing aborted message', [
            'conversation' => $conversation->uuid,
            'session_id' => $sessionId,
            'session_file' => $sessionFile,
        ]);

        $lastUuid = $this->getLastUuidFromFile($sessionFile);

        $entries = [];

        $userUuid = (string) Str::uuid();
        $entries[] = $this->buildUserSessionEntry(
            $userMessage,
            $sessionId,
            $lastUuid,
            $userUuid,
            $workingDir
        );

        $assistantUuid = (string) Str::uuid();
        $entries[] = $this->buildAssistantSessionEntry(
            $assistantMessage,
            $sessionId,
            $userUuid,
            $assistantUuid,
            $workingDir,
            $conversation->model ?? 'claude-sonnet-4-20250514'
        );

        try {
            $content = implode("\n", array_map('json_encode', $entries)) . "\n";
            file_put_contents($sessionFile, $content, FILE_APPEND | LOCK_EX);

            Log::info('ClaudeCodeProvider: Successfully synced aborted message', [
                'session_file' => $sessionFile,
                'entries_written' => count($entries),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('ClaudeCodeProvider: Failed to write session file', [
                'session_file' => $sessionFile,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getSessionFilePath(string $workingDir, string $sessionId): ?string
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $claudeDir = $home . '/.claude/projects';
        $encodedPath = str_replace('/', '-', $workingDir);
        $sessionFile = "{$claudeDir}/{$encodedPath}/{$sessionId}.jsonl";

        $dir = dirname($sessionFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true)) {
                Log::warning('ClaudeCodeProvider: Failed to create session directory', [
                    'dir' => $dir,
                ]);
                return null;
            }
            // Set group ownership for cross-process access
            @chmod($dir, 0775);
            @chgrp($dir, 'appgroup');
            Log::info('ClaudeCodeProvider: Created session directory', [
                'dir' => $dir,
            ]);
        }

        return $sessionFile;
    }

    private function getLastUuidFromFile(string $sessionFile): ?string
    {
        if (!file_exists($sessionFile)) {
            return null;
        }

        $file = new \SplFileObject($sessionFile, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();

        for ($i = $lastLine; $i >= 0; $i--) {
            $file->seek($i);
            $line = trim($file->current());
            if (!empty($line)) {
                $data = json_decode($line, true);
                if (isset($data['uuid'])) {
                    return $data['uuid'];
                }
            }
        }

        return null;
    }

    private function buildUserSessionEntry(
        Message $message,
        string $sessionId,
        ?string $parentUuid,
        string $uuid,
        string $cwd
    ): array {
        return [
            'parentUuid' => $parentUuid,
            'isSidechain' => false,
            'userType' => 'external',
            'cwd' => $cwd,
            'sessionId' => $sessionId,
            'version' => '2.0.76',
            'gitBranch' => '',
            'type' => 'user',
            'message' => [
                'role' => 'user',
                'content' => is_string($message->content) ? $message->content : json_encode($message->content),
            ],
            'uuid' => $uuid,
            'timestamp' => $message->created_at->toISOString(),
        ];
    }

    private function buildAssistantSessionEntry(
        Message $message,
        string $sessionId,
        string $parentUuid,
        string $uuid,
        string $cwd,
        string $model
    ): array {
        $content = is_array($message->content) ? $message->content : [];
        $content = array_values(array_filter($content, fn($block) =>
            ($block['type'] ?? '') !== 'interrupted'
        ));

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                throw new \RuntimeException(
                    'BUG: Attempting to sync tool_use block without tool_result. ' .
                    'This should never happen - abort should wait for tool completion or skip sync.'
                );
            }
        }

        return [
            'parentUuid' => $parentUuid,
            'isSidechain' => false,
            'userType' => 'external',
            'cwd' => $cwd,
            'sessionId' => $sessionId,
            'version' => '2.0.76',
            'gitBranch' => '',
            'message' => [
                'model' => $model,
                'id' => 'msg_' . Str::random(24),
                'type' => 'message',
                'role' => 'assistant',
                'content' => $content,
                'stop_reason' => 'end_turn',
                'stop_sequence' => null,
                'usage' => [
                    'input_tokens' => $message->input_tokens ?? 0,
                    'output_tokens' => $message->output_tokens ?? 0,
                ],
            ],
            'requestId' => 'req_' . Str::random(24),
            'type' => 'assistant',
            'uuid' => $uuid,
            'timestamp' => $message->created_at->toISOString(),
        ];
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    private function getThinkingTokens(Conversation $conversation): int
    {
        $reasoningConfig = $conversation->getReasoningConfig();
        return $reasoningConfig['thinking_tokens'] ?? 0;
    }
}
