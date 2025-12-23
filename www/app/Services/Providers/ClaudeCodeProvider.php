<?php

namespace App\Services\Providers;

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
use App\Services\AppSettingsService;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Claude Code CLI provider.
 *
 * Uses the `claude` CLI tool with streaming JSON output.
 * Claude Code manages its own conversation history via session IDs.
 */
class ClaudeCodeProvider implements AIProviderInterface
{
    private ModelRepository $models;
    private AppSettingsService $appSettings;

    public function __construct(ModelRepository $models, AppSettingsService $appSettings)
    {
        $this->models = $models;
        $this->appSettings = $appSettings;
    }

    /**
     * Check if Anthropic API key is configured.
     */
    public function hasApiKey(): bool
    {
        return $this->appSettings->hasAnthropicApiKey();
    }

    /**
     * Check if OAuth credentials exist (from `claude login`).
     */
    public function hasOAuthCredentials(): bool
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $credentialsFile = $home . '/.claude/.credentials.json';
        return file_exists($credentialsFile);
    }

    /**
     * Check if any authentication method is configured.
     */
    public function isAuthenticated(): bool
    {
        return $this->hasOAuthCredentials() || $this->hasApiKey();
    }

    /**
     * Get the Anthropic API key.
     */
    private function getApiKey(): ?string
    {
        return $this->appSettings->getAnthropicApiKey();
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

        // Check if authenticated (OAuth or API key)
        if (!$this->isAuthenticated()) {
            yield StreamEvent::error('CLAUDE_CODE_AUTH_REQUIRED:Claude Code authentication required. Please login or add your API key.');

            return;
        }

        // Get the latest user message from the conversation
        $latestMessage = $this->getLatestUserMessage($conversation);
        if ($latestMessage === null) {
            yield StreamEvent::error('No user message found in conversation');

            return;
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

            $messages[] = [
                'role' => $message->role,
                'content' => $message->content,
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

        // Get allowed tools from settings (Setting::get already decodes JSON)
        $allowedTools = \App\Models\Setting::get('chat.claude_code_allowed_tools', []);
        if (!is_array($allowedTools)) {
            $allowedTools = [];
        }

        // Get enabled tools from NativeToolService (respects global disabled state)
        $nativeToolService = app(\App\Services\NativeToolService::class);
        $enabledToolNames = $nativeToolService->getEnabledToolNames('claude_code');

        // Filter tools: if specific tools selected, intersect with enabled; otherwise use all enabled
        if (!empty($allowedTools)) {
            $allowedTools = array_values(array_intersect($allowedTools, $enabledToolNames));
        } else {
            // "All tools" means all enabled tools (excludes globally disabled)
            $allowedTools = $enabledToolNames;
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

        // Add system prompt if provided
        if (!empty($options['system'])) {
            $parts[] = '--append-system-prompt';
            $parts[] = escapeshellarg($options['system']);
        }

        // Build environment variable prefix
        $envVars = [];

        // Add Anthropic API key only if OAuth isn't available
        // (OAuth credentials in ~/.claude/.credentials.json take precedence)
        if (!$this->hasOAuthCredentials()) {
            $apiKey = $this->getApiKey();
            if ($apiKey) {
                $envVars[] = 'ANTHROPIC_API_KEY=' . escapeshellarg($apiKey);
            }
        }

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

        $process = proc_open($command, $descriptors, $pipes, base_path());

        if (!is_resource($process)) {
            yield StreamEvent::error('Failed to start Claude Code CLI process');

            return;
        }

        // Write user message to stdin (required for --tools flag to work correctly)
        fwrite($pipes[0], $userMessage);
        fclose($pipes[0]);

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

                        yield from $this->parseJsonLine($line, $state);
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

            // Emit cost if available
            if ($state['totalCost'] !== null) {
                yield StreamEvent::usage(0, 0, null, null, $state['totalCost']);
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

            if ($exitCode !== 0) {
                Log::channel('api')->warning('ClaudeCodeProvider: CLI exited with non-zero code', [
                    'exit_code' => $exitCode,
                ]);
            }

            yield StreamEvent::done('end_turn');

        } catch (\Throwable $e) {
            Log::channel('api')->error('ClaudeCodeProvider: Stream error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

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
        }
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
            case 'message_stop':
                // Message lifecycle events - ignore, we handle content blocks
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
