<?php

namespace App\Services\Providers;

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
use App\Models\Credential;
use App\Models\Message;
use App\Services\ConversationStreamLogger;
use App\Services\ModelRepository;
use App\Services\Providers\Traits\InjectsInterruptionReminder;
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
class ClaudeCodeProvider implements AIProviderInterface
{
    use InjectsInterruptionReminder;

    private ModelRepository $models;
    /** @var resource|null */
    private $activeProcess = null;

    public function __construct(ModelRepository $models)
    {
        $this->models = $models;
    }

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
     *
     * Note: We intentionally don't fall back to API key here.
     * Users must explicitly authenticate with `claude login` to use Claude Code.
     * This prevents unknowing API key usage when OAuth isn't set up.
     */
    public function isAuthenticated(): bool
    {
        return $this->hasOAuthCredentials();
    }

    public function getProviderType(): string
    {
        return 'claude_code';
    }

    public function isAvailable(): bool
    {
        // Check if claude CLI is available
        $output = [];
        $returnCode = 0;
        exec('which claude 2>/dev/null', $output, $returnCode);

        return $returnCode === 0 && !empty($output);
    }

    public function getModels(): array
    {
        return $this->models->getModelsArray('claude_code');
    }

    public function getContextWindow(string $model): int
    {
        return $this->models->getContextWindow($model);
    }

    /**
     * Stream a message using Claude Code CLI.
     *
     * Claude Code manages conversation history internally via session IDs.
     * We only send the latest user message; previous context is restored automatically.
     */
    public function streamMessage(
        Conversation $conversation,
        array $options = []
    ): Generator {
        if (!$this->isAvailable()) {
            yield StreamEvent::error('Claude Code CLI not available');

            return;
        }

        // Check if authenticated via OAuth (claude login)
        if (!$this->isAuthenticated()) {
            yield StreamEvent::error('CLAUDE_CODE_AUTH_REQUIRED:Claude Code authentication required. Please run "claude login" in the container.');

            return;
        }

        // Get the latest user message from the conversation
        $latestMessage = $this->getLatestUserMessage($conversation);
        if ($latestMessage === null) {
            yield StreamEvent::error('No user message found in conversation');

            return;
        }

        // Inject interruption reminder if previous response was interrupted
        if (!empty($options['interruption_reminder'])) {
            $latestMessage = $options['interruption_reminder'] . "\n\n" . $latestMessage;
        }

        // Build CLI command (user message is passed via stdin, not in command)
        $command = $this->buildCommand($conversation, $options);

        Log::channel('api')->info('ClaudeCodeProvider: Starting CLI stream', [
            'conversation_id' => $conversation->id,
            'session_id' => $conversation->claude_session_id ?? 'new',
            'model' => $conversation->model,
            'user_message' => substr($latestMessage, 0, 100),
            'command_preview' => substr($command, 0, 300) . '...',
        ]);

        // Execute and stream (user message is written to stdin)
        yield from $this->executeAndStream($command, $latestMessage, $conversation, $options);
    }

    /**
     * Build messages array from conversation.
     *
     * For Claude Code, we only need the latest user message since
     * the CLI maintains its own conversation history.
     */
    public function buildMessagesFromConversation(Conversation $conversation): array
    {
        // Claude Code manages history internally, but we return the full
        // message history for compatibility with the interface
        $messages = [];

        foreach ($conversation->messages as $message) {
            if ($message->role === 'system') {
                continue;
            }

            $content = $message->content;

            // Filter out 'interrupted' blocks (UI-only marker)
            if (is_array($content)) {
                $content = array_values(array_filter($content, fn($block) =>
                    ($block['type'] ?? '') !== 'interrupted'
                ));
            }

            $messages[] = [
                'role' => $message->role,
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * Get the latest user message content as a string.
     */
    private function getLatestUserMessage(Conversation $conversation): ?string
    {
        // Use fresh query to avoid cached relationship data
        $messages = \App\Models\Message::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->latest('id')
            ->first();

        if (!$messages) {
            return null;
        }

        $content = $messages->content;

        // Handle array content (multi-part messages)
        if (is_array($content)) {
            $textParts = [];
            foreach ($content as $block) {
                if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    $textParts[] = $block['text'];
                }
            }

            return implode("\n", $textParts);
        }

        return is_string($content) ? $content : null;
    }

    /**
     * Build the Claude CLI command.
     */
    private function buildCommand(
        Conversation $conversation,
        array $options
    ): string {
        $model = $conversation->model ?? config('ai.providers.claude_code.default_model', 'opus');

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

        // Base command with streaming JSON output
        // Note: --verbose is required when using --print with stream-json
        // --include-partial-messages enables real streaming with content_block_delta events
        // User message is provided via stdin (not as argument) because --tools breaks argument parsing
        // --dangerously-skip-permissions allows all tool calls without approval prompts
        // TODO: Consider making --dangerously-skip-permissions configurable via Setting::get()
        //       for deployments requiring approval prompts before tool execution
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

        // Use --resume for conversation continuity (not --session-id which is for new sessions)
        if (!empty($conversation->claude_session_id)) {
            $parts[] = '--resume';
            $parts[] = escapeshellarg($conversation->claude_session_id);
        }

        // Add tools restriction if configured (empty array = all tools)
        // --tools limits which tools are available to Claude
        // --allowedTools would just auto-approve tools (not restrict them)
        if (!empty($allowedTools)) {
            $parts[] = '--tools';
            $parts[] = escapeshellarg(implode(',', $allowedTools));
        }

        // Note: permissions.deny patterns in ~/.claude/settings.json are respected natively
        // when loaded via --settings flag. No need to pass --disallowedTools explicitly.

        // Add system prompt if provided
        // Using --system-prompt instead of --append-system-prompt because:
        // 1. --append-system-prompt has a bug where it sends as user message (issue #4523)
        // 2. --system-prompt correctly sets the system prompt, enabling agent switching
        // Note: CLAUDE.md is still read separately by Claude Code
        if (!empty($options['system'])) {
            $parts[] = '--system-prompt';
            $parts[] = escapeshellarg($options['system']);
        }

        // Build environment variable prefix
        $envVars = [];

        // Note: We don't set ANTHROPIC_API_KEY here.
        // Claude Code uses OAuth credentials from ~/.claude/.credentials.json
        // (set up via `claude login`). If not authenticated, streamMessage()
        // returns an error before we get here.

        // Set thinking tokens via environment variable
        $thinkingTokens = $this->getThinkingTokens($conversation);
        if ($thinkingTokens > 0) {
            $envVars[] = "MAX_THINKING_TOKENS={$thinkingTokens}";
        }

        $envPrefix = !empty($envVars) ? implode(' ', $envVars) . ' ' : '';

        return $envPrefix . implode(' ', $parts) . ' 2>&1';
    }

    /**
     * Get thinking tokens from conversation reasoning config.
     */
    private function getThinkingTokens(Conversation $conversation): int
    {
        $reasoningConfig = $conversation->getReasoningConfig();

        return $reasoningConfig['thinking_tokens'] ?? 0;
    }

    /**
     * Execute the command and stream events.
     */
    private function executeAndStream(
        string $command,
        string $userMessage,
        Conversation $conversation,
        array $options
    ): Generator {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Get workspace-specific credentials merged with global ones
        $workspaceId = $conversation->workspace_id;
        $credentials = Credential::getEnvArrayForWorkspace($workspaceId);

        // Merge credentials with current environment
        // Credentials override any existing env vars with the same name
        $env = array_merge($_ENV, $_SERVER, $credentials);

        // Filter to only string values (proc_open requires this)
        $env = array_filter($env, fn($v) => is_string($v) || is_numeric($v));
        $env = array_map(fn($v) => (string) $v, $env);

        $process = proc_open($command, $descriptors, $pipes, base_path(), $env);

        if (!is_resource($process)) {
            yield StreamEvent::error('Failed to start Claude Code CLI process');

            return;
        }

        $this->activeProcess = $process;

        // Initialize per-conversation stream logger (only in debug mode)
        $debugLogging = config('app.debug');
        $streamLogger = $debugLogging ? app(ConversationStreamLogger::class) : null;
        $verboseLogging = false; // Set to true for full delta logging when debugging
        $uuid = $conversation->uuid;
        if ($streamLogger) {
            $streamLogger->init($uuid);
            $streamLogger->logCommand($uuid, $command);
        }

        // Write user message to stdin (required for --tools flag to work correctly)
        fwrite($pipes[0], $userMessage);
        fclose($pipes[0]); // Close stdin to signal EOF - CLI needs this to start processing

        // Log stdin after writing
        if ($streamLogger) {
            $streamLogger->logStdin($uuid, $userMessage);
        }

        // Set stdout to non-blocking
        stream_set_blocking($pipes[1], false);

        $buffer = '';
        $state = [
            'blockIndex' => 0,
            'textStarted' => false,
            'thinkingStarted' => false,
            'currentToolUse' => null,
            'sessionId' => null,
            'totalCost' => null,
            'gotStreamEvents' => false, // Track if we got stream_events (to skip duplicate assistant message)
            'awaitingCompactionSummary' => false, // Track if next user message is compaction summary
            'compactionMetadata' => null, // Store compaction metadata to attach to summary
            // Token tracking for context window calculation
            'inputTokens' => 0,
            'outputTokens' => 0,
            'cacheCreationTokens' => 0,
            'cacheReadTokens' => 0,
        ];

        try {
            while (true) {
                $status = proc_get_status($process);

                // Read available data
                $chunk = fread($pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $buffer .= $chunk;

                    // Process complete lines (JSONL format)
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);

                        if (empty(trim($line))) {
                            continue;
                        }

                        // Log JSONL lines for debugging (skip stream_event deltas unless verbose)
                        if ($streamLogger) {
                            $parsedLine = json_decode($line, true);
                            $eventType = $parsedLine['type'] ?? null;
                            if ($verboseLogging || $eventType !== 'stream_event') {
                                $streamLogger->logStream($uuid, $line, $parsedLine);
                            }
                        }

                        yield from $this->parseJsonLine($line, $state);

                        // Save session ID immediately when captured (for abort sync support)
                        // This ensures the session ID is available even if stream is aborted
                        if ($state['sessionId'] && !$conversation->claude_session_id) {
                            $conversation->claude_session_id = $state['sessionId'];
                            $conversation->save();
                            Log::channel('api')->info('ClaudeCodeProvider: Session ID captured early', [
                                'session_id' => $state['sessionId'],
                            ]);
                        }
                    }
                }

                // Check if process has ended
                if (!$status['running']) {
                    break;
                }

                // Small sleep to prevent CPU spinning
                usleep(1000);
            }

            // Process any remaining buffer
            if (!empty(trim($buffer))) {
                if ($streamLogger) {
                    $parsedLine = json_decode($buffer, true);
                    $eventType = $parsedLine['type'] ?? null;
                    if ($verboseLogging || $eventType !== 'stream_event') {
                        $streamLogger->logStream($uuid, $buffer, $parsedLine);
                    }
                }
                yield from $this->parseJsonLine($buffer, $state);
            }

            // Close open blocks
            if ($state['textStarted']) {
                yield StreamEvent::textStop($state['blockIndex']);
            }
            if ($state['thinkingStarted']) {
                yield StreamEvent::thinkingStop($state['blockIndex']);
            }

            // Update conversation with session ID if we got one
            if ($state['sessionId'] && !$conversation->claude_session_id) {
                $conversation->claude_session_id = $state['sessionId'];
                $conversation->save();
            }

            // Emit usage event with tokens and cost
            // Only emit if we have either cost or token data
            if ($state['totalCost'] !== null || $state['inputTokens'] > 0) {
                yield StreamEvent::usage(
                    $state['inputTokens'],
                    $state['outputTokens'],
                    $state['cacheCreationTokens'] ?: null,
                    $state['cacheReadTokens'] ?: null,
                    $state['totalCost']
                );
            }

            // Close pipes before proc_close (required order)
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($pipes[2])) {
                fclose($pipes[2]);
            }

            // Check exit code
            $exitCode = proc_close($process);
            $this->activeProcess = null;

            // Fix permissions on Claude config files so PHP-FPM (www-data) can read/write them
            // Claude CLI creates these with 600, we need 660 for group (appgroup) write access
            $home = getenv('HOME') ?: '/home/appuser';
            @chmod($home . '/.claude.json', 0660);
            @chmod($home . '/.claude.json.backup', 0660);
            @chmod($home . '/.claude/settings.json', 0664);

            if ($exitCode !== 0) {
                Log::channel('api')->warning('ClaudeCodeProvider: CLI exited with non-zero code', [
                    'exit_code' => $exitCode,
                ]);
            }

            // Log completion with summary
            if ($streamLogger) {
                $streamLogger->logComplete($uuid, [
                    'exit_code' => $exitCode,
                    'session_id' => $state['sessionId'],
                    'total_cost' => $state['totalCost'],
                    'input_tokens' => $state['inputTokens'],
                    'output_tokens' => $state['outputTokens'],
                ]);
            }

            yield StreamEvent::done('end_turn');

        } catch (\Throwable $e) {
            Log::channel('api')->error('ClaudeCodeProvider: Stream error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Log error to conversation stream log
            if ($streamLogger) {
                $streamLogger->logError($uuid, $e->getMessage());
            }

            yield StreamEvent::error($e->getMessage());

            // Clean up safely
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
            if (is_resource($process)) {
                proc_terminate($process);
            }
            $this->activeProcess = null;
        }
    }

    /**
     * Abort the current streaming operation.
     *
     * Uses SIGINT (like Ctrl+C) first, then SIGKILL if needed.
     */
    public function abort(): void
    {
        if ($this->activeProcess !== null && is_resource($this->activeProcess)) {
            // Step 1: Try SIGINT (like Ctrl+C) - signal 2
            proc_terminate($this->activeProcess, 2);
            usleep(200000); // 200ms for graceful shutdown

            $status = proc_get_status($this->activeProcess);
            if (!$status['running']) {
                try {
                    proc_close($this->activeProcess);
                } catch (\Exception $e) {
                    Log::warning('ClaudeCodeProvider: Error closing process after SIGINT', ['error' => $e->getMessage()]);
                }
                $this->activeProcess = null;
                return;
            }

            // Step 2: Force kill with SIGKILL (signal 9) as last resort
            proc_terminate($this->activeProcess, 9);

            try {
                proc_close($this->activeProcess);
            } catch (\Exception $e) {
                Log::warning('ClaudeCodeProvider: Error closing process after SIGKILL', ['error' => $e->getMessage()]);
            }

            $this->activeProcess = null;
        }
    }

    /**
     * Sync aborted message to Claude Code's native JSONL session file.
     *
     * When a stream is aborted, Claude Code's internal session may not have
     * the completed content blocks. This method writes the missing messages
     * to the JSONL session file so the next --resume has full context.
     */
    public function syncAbortedMessage(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage
    ): bool {
        $sessionId = $conversation->claude_session_id;

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

        // Read existing file to find the last parentUuid
        $lastUuid = $this->getLastUuidFromFile($sessionFile);

        // Build the JSONL entries
        $entries = [];

        // 1. User message entry
        $userUuid = (string) Str::uuid();
        $entries[] = $this->buildUserSessionEntry(
            $userMessage,
            $sessionId,
            $lastUuid,
            $userUuid,
            $workingDir
        );

        // 2. Assistant message entry (only completed blocks)
        $assistantUuid = (string) Str::uuid();
        $entries[] = $this->buildAssistantSessionEntry(
            $assistantMessage,
            $sessionId,
            $userUuid,
            $assistantUuid,
            $workingDir,
            $conversation->model ?? 'claude-sonnet-4-20250514'
        );

        // Append to session file
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

    /**
     * Get the session file path for a given working directory and session ID.
     * Claude Code stores sessions in ~/.claude/projects/{encoded-path}/{session_id}.jsonl
     */
    private function getSessionFilePath(string $workingDir, string $sessionId): ?string
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $claudeDir = $home . '/.claude/projects';

        // Claude Code encodes paths by replacing / with -
        $encodedPath = str_replace('/', '-', $workingDir);

        $sessionFile = "{$claudeDir}/{$encodedPath}/{$sessionId}.jsonl";

        // Create directory if it doesn't exist (for early abort scenarios)
        $dir = dirname($sessionFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                Log::warning('ClaudeCodeProvider: Failed to create session directory', [
                    'dir' => $dir,
                ]);
                return null;
            }
            Log::info('ClaudeCodeProvider: Created session directory', [
                'dir' => $dir,
            ]);
        }

        return $sessionFile;
    }

    /**
     * Get the last UUID from an existing session file.
     */
    private function getLastUuidFromFile(string $sessionFile): ?string
    {
        if (!file_exists($sessionFile)) {
            return null;
        }

        // Read last line efficiently
        $file = new \SplFileObject($sessionFile, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();

        // Read backwards to find last entry with a uuid field
        // (skip queue-operation and other metadata entries that don't have uuid)
        for ($i = $lastLine; $i >= 0; $i--) {
            $file->seek($i);
            $line = trim($file->current());
            if (!empty($line)) {
                $data = json_decode($line, true);
                if (isset($data['uuid'])) {
                    return $data['uuid'];
                }
                // Continue searching if this entry has no uuid (e.g., queue-operation)
            }
        }

        return null;
    }

    /**
     * Build a user message JSONL entry for Claude Code session file.
     */
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
            'version' => '2.0.76', // Claude Code version
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

    /**
     * Build an assistant message JSONL entry for Claude Code session file.
     */
    private function buildAssistantSessionEntry(
        Message $message,
        string $sessionId,
        string $parentUuid,
        string $uuid,
        string $cwd,
        string $model
    ): array {
        // Use content blocks, filtering out UI-only markers
        $content = is_array($message->content) ? $message->content : [];
        $content = array_values(array_filter($content, fn($block) =>
            ($block['type'] ?? '') !== 'interrupted'
        ));

        // Sanity check: we should never sync tool_use blocks
        // With proper abort handling, abort either:
        // - Happens during thinking/text (no tool_use yet)
        // - Waits for tool_result then skips sync (skipSync=true)
        // If we have tool_use blocks here, something is wrong with the abort flow
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
                'stop_reason' => 'end_turn', // Mark as completed for Claude Code
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

    /**
     * Parse a JSONL line and yield StreamEvents.
     *
     * Claude Code outputs lines like:
     * {"type":"user","message":{...}}
     * {"type":"assistant","message":{"role":"assistant","content":[...]}}
     * {"type":"result","subtype":"success","total_cost_usd":0.003,"session_id":"abc"}
     */
    private function parseJsonLine(string $line, array &$state): Generator
    {
        $data = json_decode($line, true);

        if ($data === null) {
            Log::channel('api')->debug('ClaudeCodeProvider: Failed to parse JSON line', [
                'line' => substr($line, 0, 200),
            ]);

            return;
        }

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'user':
                // User message - may contain tool results
                yield from $this->parseUserMessage($data, $state);
                break;

            case 'assistant':
                // Skip if we already got stream_events (which contain the same content)
                if (!$state['gotStreamEvents']) {
                    yield from $this->parseAssistantMessage($data, $state);
                }
                break;

            case 'result':
                // Final result with metadata
                if (isset($data['session_id'])) {
                    $state['sessionId'] = $data['session_id'];
                }
                if (isset($data['total_cost_usd'])) {
                    $state['totalCost'] = (float) $data['total_cost_usd'];
                }
                Log::channel('api')->info('ClaudeCodeProvider: Result received', [
                    'subtype' => $data['subtype'] ?? 'unknown',
                    'session_id' => $state['sessionId'],
                    'total_cost' => $state['totalCost'],
                    'num_turns' => $data['num_turns'] ?? null,
                ]);
                break;

            case 'stream_event':
                // Real-time streaming events from --include-partial-messages
                $state['gotStreamEvents'] = true;
                yield from $this->parseStreamEvent($data, $state);
                break;

            case 'system':
                // System init message - capture session_id
                if (isset($data['session_id'])) {
                    $state['sessionId'] = $data['session_id'];
                }
                // Detect compaction event (type: system, subtype: compact_boundary)
                if (($data['subtype'] ?? '') === 'compact_boundary') {
                    $compactMetadata = $data['compact_metadata'] ?? [];
                    $preTokens = is_array($compactMetadata) ? ($compactMetadata['pre_tokens'] ?? null) : null;
                    $trigger = is_array($compactMetadata) ? ($compactMetadata['trigger'] ?? 'auto') : 'auto';
                    Log::channel('api')->info('ClaudeCodeProvider: Context compaction detected', [
                        'pre_tokens' => $preTokens,
                        'trigger' => $trigger,
                    ]);
                    // Store metadata and flag to capture the summary in the next user message
                    $state['awaitingCompactionSummary'] = true;
                    $state['compactionMetadata'] = [
                        'pre_tokens' => $preTokens,
                        'trigger' => $trigger,
                    ];
                    // Don't yield contextCompacted here - we'll yield compactionSummary with full data
                }
                break;

            default:
                Log::channel('api')->debug('ClaudeCodeProvider: Unknown event type', [
                    'type' => $type,
                ]);
        }
    }

    /**
     * Parse a stream_event and yield StreamEvents.
     * These come from --include-partial-messages flag.
     */
    private function parseStreamEvent(array $data, array &$state): Generator
    {
        $event = $data['event'] ?? [];
        $eventType = $event['type'] ?? '';

        switch ($eventType) {
            case 'content_block_start':
                $contentBlock = $event['content_block'] ?? [];
                $blockType = $contentBlock['type'] ?? '';

                if ($blockType === 'thinking') {
                    // Close text block if open
                    if ($state['textStarted']) {
                        yield StreamEvent::textStop($state['blockIndex']);
                        $state['textStarted'] = false;
                        $state['blockIndex']++;
                    }

                    // Start thinking block
                    if (!$state['thinkingStarted']) {
                        yield StreamEvent::thinkingStart($state['blockIndex']);
                        $state['thinkingStarted'] = true;
                    }
                } elseif ($blockType === 'tool_use') {
                    // Close text/thinking blocks if open
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

                    // Start tool use block
                    $toolId = $contentBlock['id'] ?? 'tool_' . $state['blockIndex'];
                    $toolName = $contentBlock['name'] ?? 'unknown';
                    $state['currentToolUse'] = [
                        'id' => $toolId,
                        'name' => $toolName,
                        'inputJson' => '',
                    ];
                    yield StreamEvent::toolUseStart($state['blockIndex'], $toolId, $toolName);
                } elseif ($blockType === 'text') {
                    // Close thinking block if open (text comes after thinking)
                    if ($state['thinkingStarted']) {
                        yield StreamEvent::thinkingStop($state['blockIndex']);
                        $state['thinkingStarted'] = false;
                        $state['blockIndex']++;
                    }

                    // Start text block
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
                    // Thinking delta
                    $thinking = $delta['thinking'] ?? '';
                    if ($thinking !== '') {
                        if (!$state['thinkingStarted']) {
                            yield StreamEvent::thinkingStart($state['blockIndex']);
                            $state['thinkingStarted'] = true;
                        }
                        yield StreamEvent::thinkingDelta($state['blockIndex'], $thinking);
                    }
                } elseif ($deltaType === 'text_delta') {
                    // Text delta
                    $text = $delta['text'] ?? '';
                    if ($text !== '') {
                        if (!$state['textStarted']) {
                            yield StreamEvent::textStart($state['blockIndex']);
                            $state['textStarted'] = true;
                        }
                        yield StreamEvent::textDelta($state['blockIndex'], $text);
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    // Tool input JSON delta
                    $partialJson = $delta['partial_json'] ?? '';
                    if ($partialJson !== '' && $state['currentToolUse'] !== null) {
                        $state['currentToolUse']['inputJson'] .= $partialJson;
                        yield StreamEvent::toolUseDelta($state['blockIndex'], $partialJson);
                    }
                } elseif ($deltaType === 'signature_delta') {
                    // Thinking block signature (required for multi-turn conversations)
                    $signature = $delta['signature'] ?? '';
                    if ($signature !== '') {
                        yield StreamEvent::thinkingSignature($state['blockIndex'], $signature);
                    }
                }
                break;

            case 'content_block_stop':
                if ($state['thinkingStarted']) {
                    // Thinking block ended
                    yield StreamEvent::thinkingStop($state['blockIndex']);
                    $state['thinkingStarted'] = false;
                    $state['blockIndex']++;
                } elseif ($state['textStarted']) {
                    // Text block ended
                    yield StreamEvent::textStop($state['blockIndex']);
                    $state['textStarted'] = false;
                    $state['blockIndex']++;
                } elseif ($state['currentToolUse'] !== null) {
                    // Tool use block ended
                    yield StreamEvent::toolUseStop($state['blockIndex']);
                    $state['currentToolUse'] = null;
                    $state['blockIndex']++;
                }
                break;

            case 'message_start':
            case 'message_delta':
                // Extract usage data from message events
                // Claude Code reports: input_tokens (new) + cache_creation + cache_read = total context
                $message = $event['message'] ?? [];
                $usage = $message['usage'] ?? [];
                if (!empty($usage)) {
                    // Calculate total context tokens (all input types combined)
                    // This includes: system prompt, CLAUDE.md, conversation history, tools
                    $state['inputTokens'] = ($usage['input_tokens'] ?? 0)
                        + ($usage['cache_creation_input_tokens'] ?? 0)
                        + ($usage['cache_read_input_tokens'] ?? 0);
                    $state['outputTokens'] = $usage['output_tokens'] ?? 0;
                    $state['cacheCreationTokens'] = $usage['cache_creation_input_tokens'] ?? 0;
                    $state['cacheReadTokens'] = $usage['cache_read_input_tokens'] ?? 0;
                }
                break;

            case 'message_stop':
                // Message complete - no action needed
                break;
        }
    }

    /**
     * Parse an assistant message and yield StreamEvents.
     */
    private function parseAssistantMessage(array $data, array &$state): Generator
    {
        $message = $data['message'] ?? [];
        $content = $message['content'] ?? [];

        if (!is_array($content)) {
            return;
        }

        foreach ($content as $block) {
            $blockType = $block['type'] ?? '';

            switch ($blockType) {
                case 'text':
                    // Text content
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
                    // Thinking/reasoning content
                    if (!$state['thinkingStarted']) {
                        // Close text block if open
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
                    // Tool invocation
                    // Close any open blocks first
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

                    // Emit the input as JSON
                    $inputJson = is_array($input) ? json_encode($input) : (string) $input;
                    yield StreamEvent::toolUseDelta($state['blockIndex'], $inputJson);

                    yield StreamEvent::toolUseStop($state['blockIndex']);
                    $state['blockIndex']++;
                    break;

                case 'tool_result':
                    // Tool result (from Claude Code's internal tool execution)
                    $toolId = $block['tool_use_id'] ?? 'unknown';
                    $resultContent = $block['content'] ?? '';
                    $isError = $block['is_error'] ?? false;

                    yield StreamEvent::toolResult($toolId, $resultContent, $isError);
                    break;
            }
        }
    }

    /**
     * Parse a user message (which may contain tool results or command outputs).
     */
    private function parseUserMessage(array $data, array &$state): Generator
    {
        $message = $data['message'] ?? [];
        $content = $message['content'] ?? [];

        // Check if this is a compaction summary (user message immediately after compact_boundary)
        if ($state['awaitingCompactionSummary']) {
            $state['awaitingCompactionSummary'] = false;
            $summaryText = '';

            // Extract text content from the message
            if (is_string($content)) {
                $summaryText = $content;
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $summaryText = $block['text'] ?? '';
                        break;
                    }
                }
            }

            if (!empty($summaryText)) {
                $metadata = $state['compactionMetadata'] ?? [];
                Log::channel('api')->info('ClaudeCodeProvider: Compaction summary captured', [
                    'summary_length' => strlen($summaryText),
                    'pre_tokens' => $metadata['pre_tokens'] ?? null,
                ]);
                yield StreamEvent::compactionSummary($summaryText, $metadata);
                $state['compactionMetadata'] = null;
                return; // Don't process as regular user message
            }
        }

        // Handle string content (e.g., local command outputs)
        if (is_string($content)) {
            // Check for local command stdout (from /context, /usage, etc.)
            if (preg_match('/<local-command-stdout>(.*?)<\/local-command-stdout>/s', $content, $matches)) {
                $output = trim($matches[1]);
                // Try to extract command name from preceding message
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

                // Handle string content
                if (is_string($resultContent)) {
                    yield StreamEvent::toolResult($toolId, $resultContent, $isError);
                } elseif (is_array($resultContent)) {
                    // Handle array content (e.g., images)
                    yield StreamEvent::toolResult($toolId, json_encode($resultContent), $isError);
                }
            }
        }
    }
}
