<?php

namespace App\Services\Providers;

use App\Contracts\AIProviderInterface;
use App\Contracts\HasNativeSession;
use App\Models\Conversation;
use App\Models\Credential;
use App\Models\Message;
use App\Services\ConversationStreamLogger;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for CLI-based providers (Claude Code, Codex).
 *
 * Handles:
 * - proc_open lifecycle (start, non-blocking read, close)
 * - JSONL line parsing loop
 * - Phase-aware timeout tracking
 * - Session ID persistence
 * - Stream logging integration
 * - Process abort/cleanup
 */
abstract class AbstractCliProvider implements AIProviderInterface, HasNativeSession
{
    protected ModelRepository $models;

    /** @var resource|null */
    protected $activeProcess = null;

    // Phase-aware timeout constants (seconds) - overridable by subclasses
    protected const TIMEOUT_INITIAL = 1800;
    protected const TIMEOUT_STREAMING = 1800;
    protected const TIMEOUT_TOOL_EXECUTION = 1800;
    protected const TIMEOUT_PENDING_RESPONSE = 1800;

    public function __construct(ModelRepository $models)
    {
        $this->models = $models;
    }

    // ========================================================================
    // AIProviderInterface implementation
    // ========================================================================

    public function executesToolsInternally(): bool
    {
        return true;
    }

    public function getSystemPromptType(): string
    {
        return 'cli';
    }

    public function getModels(): array
    {
        return $this->models->getModelsArray($this->getProviderType());
    }

    public function getContextWindow(string $model): int
    {
        return $this->models->getContextWindow($model);
    }

    // ========================================================================
    // Template methods (must be implemented by subclasses)
    // ========================================================================

    /**
     * Check if the CLI binary is installed and accessible.
     */
    abstract protected function isCliBinaryAvailable(): bool;

    /**
     * Check if authentication credentials exist.
     */
    abstract protected function hasAuthCredentials(): bool;

    /**
     * Get the error message when auth is missing.
     */
    abstract protected function getAuthRequiredError(): string;

    /**
     * Build the CLI command string.
     *
     * @param Conversation $conversation
     * @param array $options Provider options including 'system' prompt
     * @return string The shell command (without 2>&1 redirect)
     */
    abstract protected function buildCliCommand(
        Conversation $conversation,
        array $options
    ): string;

    /**
     * Get the user message to send to the CLI.
     * Returns null if no user message is available.
     */
    protected function getLatestUserMessage(Conversation $conversation): ?string
    {
        $messages = \App\Models\Message::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->latest('id')
            ->first();

        if (!$messages) {
            return null;
        }

        $content = $messages->content;

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
     * Prepare the process input.
     *
     * Returns an array with:
     * - 'command': The full command to execute (including user message if needed as arg)
     * - 'stdin': Content to write to stdin (null if message is passed as arg)
     *
     * @param string $command The base command from buildCliCommand()
     * @param string $userMessage The user message content
     * @return array{command: string, stdin: ?string}
     */
    abstract protected function prepareProcessInput(
        string $command,
        string $userMessage
    ): array;

    /**
     * Build the environment variables for the CLI process.
     * Base implementation provides common env setup; subclasses extend.
     *
     * @param Conversation $conversation
     * @param array $options
     * @return array<string, string>
     */
    protected function buildEnvironment(
        Conversation $conversation,
        array $options
    ): array {
        // Get workspace-specific credentials merged with global ones
        $workspaceId = $conversation->workspace_id;
        $credentials = Credential::getEnvArrayForWorkspace($workspaceId);

        // Merge credentials with current environment
        $env = array_merge($_ENV, $_SERVER, $credentials);

        // Filter to only string values (proc_open requires this)
        $env = array_filter($env, fn($v) => is_string($v) || is_numeric($v));
        $env = array_map(fn($v) => (string) $v, $env);

        // Inject session ID for panel tool support
        $sessionId = $conversation->screen?->session?->id;
        if ($sessionId) {
            $env['POCKETDEV_SESSION_ID'] = $sessionId;
        }

        return $env;
    }

    /**
     * Initialize the state array for JSONL parsing.
     * Subclasses should call parent and extend.
     */
    abstract protected function initParseState(): array;

    /**
     * Parse a single JSONL line and yield StreamEvents.
     *
     * @param string $line The raw JSONL line
     * @param array &$state Mutable parse state
     * @param array|null $preDecoded Pre-decoded JSON data (optimization)
     * @return Generator<StreamEvent>
     */
    abstract protected function parseJsonLine(
        string $line,
        array &$state,
        ?array $preDecoded = null
    ): Generator;

    /**
     * Extract session ID from parse state (if captured).
     */
    abstract protected function getSessionIdFromState(array $state): ?string;

    /**
     * Determine the timeout phase from a parsed JSONL event.
     *
     * Returns the new phase string, or null to keep the current phase.
     * Also returns whether the event resets the timeout timer.
     *
     * @return array{phase: ?string, resetsTimer: bool, shouldSkip: bool}
     */
    abstract protected function classifyEventForTimeout(array $parsedData): array;

    /**
     * Called after the process completes normally.
     * Subclass hook for cleanup (e.g., removing temp files, fixing permissions).
     *
     * @param Conversation $conversation
     * @param array $state Final parse state
     * @param int $exitCode Process exit code
     */
    protected function onProcessComplete(
        Conversation $conversation,
        array $state,
        int $exitCode
    ): void {
        // Default: no-op. Subclasses override as needed.
    }

    /**
     * Whether to use ConversationStreamLogger for this provider.
     * Default: true (enabled in debug mode).
     */
    protected function supportsStreamLogging(): bool
    {
        return true;
    }

    /**
     * Whether a parsed event should be logged (for filtering verbose deltas).
     */
    protected function shouldLogEvent(array $parsedData): bool
    {
        return true; // Default: log everything. ClaudeCodeProvider overrides to skip stream_event deltas unless verbose.
    }

    /**
     * Close any open content blocks at stream end.
     * Override in subclass based on state tracking.
     */
    abstract protected function closeOpenBlocks(array $state): Generator;

    /**
     * Emit usage event from accumulated state.
     * Override in subclass based on state tracking.
     */
    abstract protected function emitUsage(array $state): Generator;

    /**
     * Get completion summary for logging.
     */
    abstract protected function getCompletionSummary(array $state, int $exitCode): array;

    // ========================================================================
    // Core Implementation (shared logic)
    // ========================================================================

    public function isAvailable(): bool
    {
        return $this->isCliBinaryAvailable();
    }

    public function streamMessage(
        Conversation $conversation,
        array $options = []
    ): Generator {
        if (!$this->isAvailable()) {
            yield StreamEvent::error($this->getProviderType() . ' CLI not available');
            return;
        }

        if (!$this->hasAuthCredentials()) {
            yield StreamEvent::error($this->getAuthRequiredError());
            return;
        }

        $latestMessage = $this->getLatestUserMessage($conversation);
        if ($latestMessage === null) {
            yield StreamEvent::error('No user message found in conversation');
            return;
        }

        // Inject interruption reminder if previous response was interrupted
        if (!empty($options['interruption_reminder'])) {
            $latestMessage = $options['interruption_reminder'] . "\n\n" . $latestMessage;
        }

        $command = $this->buildCliCommand($conversation, $options);

        Log::channel('api')->info($this->getProviderType() . ': Starting CLI stream', [
            'conversation_id' => $conversation->id,
            'session_id' => $this->getSessionId($conversation) ?? 'new',
            'model' => $conversation->model,
            'user_message' => substr($latestMessage, 0, 100),
            'command_preview' => substr($command, 0, 300) . '...',
        ]);

        yield from $this->executeAndStream($command, $latestMessage, $conversation, $options);
    }

    /**
     * Execute the CLI process and stream events.
     * This is the main proc_open lifecycle method.
     */
    protected function executeAndStream(
        string $command,
        string $userMessage,
        Conversation $conversation,
        array $options
    ): Generator {
        $processInput = $this->prepareProcessInput($command, $userMessage);
        $fullCommand = $processInput['command'] . ' 2>&1';
        $stdinContent = $processInput['stdin'];

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $env = $this->buildEnvironment($conversation, $options);
        $workingDirectory = $conversation->working_directory ?? base_path();

        $process = proc_open($fullCommand, $descriptors, $pipes, $workingDirectory, $env);

        if (!is_resource($process)) {
            yield StreamEvent::error('Failed to start ' . $this->getProviderType() . ' CLI process');
            return;
        }

        $this->activeProcess = $process;

        // Initialize per-conversation stream logger
        $debugLogging = config('app.debug') && $this->supportsStreamLogging();
        $streamLogger = $debugLogging ? app(ConversationStreamLogger::class) : null;
        $uuid = $conversation->uuid;
        if ($streamLogger) {
            $streamLogger->init($uuid);
            $streamLogger->logCommand($uuid, $fullCommand);
        }

        // Write to stdin if needed (some CLIs take input via stdin, others via args)
        if ($stdinContent !== null) {
            fwrite($pipes[0], $stdinContent);
            if ($streamLogger) {
                $streamLogger->logStdin($uuid, $stdinContent);
            }
        }
        fclose($pipes[0]); // Close stdin to signal EOF

        // Set stdout to non-blocking
        stream_set_blocking($pipes[1], false);

        $buffer = '';
        $state = $this->initParseState();

        try {
            // Phase-aware timeout tracking
            $phase = 'initial';
            $lastOutputTime = microtime(true);
            $timedOut = false;

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

                        // Parse once, reuse
                        $parsedLine = json_decode($line, true);

                        // Log JSONL lines for debugging
                        if ($streamLogger && is_array($parsedLine) && $this->shouldLogEvent($parsedLine)) {
                            $streamLogger->logStream($uuid, $line, $parsedLine);
                        }

                        // Guard against non-array JSON
                        if (!is_array($parsedLine)) {
                            Log::channel('api')->warning($this->getProviderType() . ': Non-array JSONL line', [
                                'line' => substr($line, 0, 500),
                            ]);
                            continue;
                        }

                        // Classify event for timeout management
                        $classification = $this->classifyEventForTimeout($parsedLine);

                        if ($classification['shouldSkip']) {
                            if ($classification['resetsTimer']) {
                                $lastOutputTime = microtime(true);
                            }
                            continue;
                        }

                        if ($classification['phase'] !== null) {
                            $phase = $classification['phase'];
                        }
                        if ($classification['resetsTimer']) {
                            $lastOutputTime = microtime(true);
                        }

                        yield from $this->parseJsonLine($line, $state, $parsedLine);

                        // Save session ID immediately when captured
                        $capturedSessionId = $this->getSessionIdFromState($state);
                        if ($capturedSessionId && !$this->getSessionId($conversation)) {
                            $this->setSessionId($conversation, $capturedSessionId);
                            $conversation->save();
                            Log::channel('api')->info($this->getProviderType() . ': Session ID captured early', [
                                'session_id' => $capturedSessionId,
                            ]);
                        }
                    }
                }

                // Check if process has ended
                if (!$status['running']) {
                    break;
                }

                // Phase-aware timeout check
                $timeout = match ($phase) {
                    'initial' => static::TIMEOUT_INITIAL,
                    'streaming' => static::TIMEOUT_STREAMING,
                    'tool_execution' => static::TIMEOUT_TOOL_EXECUTION,
                    'pending_response' => static::TIMEOUT_PENDING_RESPONSE,
                    default => static::TIMEOUT_INITIAL,
                };
                $elapsed = microtime(true) - $lastOutputTime;

                if ($elapsed > $timeout) {
                    Log::channel('api')->warning($this->getProviderType() . ': Phase-aware timeout', [
                        'phase' => $phase,
                        'timeout' => $timeout,
                        'elapsed' => round($elapsed, 2),
                        'conversation_uuid' => $uuid,
                    ]);

                    // Graceful shutdown: SIGINT -> 200ms -> SIGKILL
                    proc_terminate($process, 2);
                    usleep(200000);
                    $procStatus = proc_get_status($process);
                    if ($procStatus['running']) {
                        proc_terminate($process, 9);
                    }

                    $timedOut = true;
                    yield StreamEvent::error(
                        $this->getProviderType() . " process timed out after {$timeout}s in {$phase} phase"
                    );
                    break;
                }

                usleep(1000); // 1ms sleep to prevent CPU spinning
            }

            // Process remaining buffer
            if (!empty(trim($buffer))) {
                $parsedLine = json_decode($buffer, true);
                if ($streamLogger && is_array($parsedLine) && $this->shouldLogEvent($parsedLine)) {
                    $streamLogger->logStream($uuid, $buffer, $parsedLine);
                }
                if (is_array($parsedLine)) {
                    yield from $this->parseJsonLine($buffer, $state, $parsedLine);
                }
            }

            // Close open blocks (subclass state will have these flags)
            yield from $this->closeOpenBlocks($state);

            // Persist session ID if not already done
            $capturedSessionId = $this->getSessionIdFromState($state);
            if ($capturedSessionId && !$this->getSessionId($conversation)) {
                $this->setSessionId($conversation, $capturedSessionId);
                $conversation->save();
            }

            // Emit usage event
            yield from $this->emitUsage($state);

            // Close pipes
            if (is_resource($pipes[1])) fclose($pipes[1]);
            if (is_resource($pipes[2])) fclose($pipes[2]);

            $exitCode = proc_close($process);
            $this->activeProcess = null;

            // Subclass cleanup hook
            $this->onProcessComplete($conversation, $state, $exitCode);

            if ($exitCode !== 0) {
                Log::channel('api')->warning($this->getProviderType() . ': CLI exited with non-zero code', [
                    'exit_code' => $exitCode,
                ]);
            }

            // Log completion
            if ($streamLogger) {
                $streamLogger->logComplete($uuid, $this->getCompletionSummary($state, $exitCode));
            }

            yield StreamEvent::done($timedOut ? 'timeout' : 'end_turn');

        } catch (\Throwable $e) {
            Log::channel('api')->error($this->getProviderType() . ': Stream error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($streamLogger) {
                $streamLogger->logError($uuid, $e->getMessage());
            }

            yield StreamEvent::error($e->getMessage());

            // Clean up safely
            if (is_resource($pipes[1])) fclose($pipes[1]);
            if (is_resource($pipes[2])) fclose($pipes[2]);
            if (is_resource($process)) proc_terminate($process);
            $this->activeProcess = null;
        }
    }

    /**
     * Abort the current streaming operation.
     * Default: SIGINT -> 200ms -> SIGKILL (matches Claude Code behavior).
     * Subclasses can override if different signal handling is needed.
     */
    public function abort(): void
    {
        if ($this->activeProcess !== null && is_resource($this->activeProcess)) {
            // SIGINT first (like Ctrl+C)
            proc_terminate($this->activeProcess, 2);
            usleep(200000);

            $status = proc_get_status($this->activeProcess);
            if ($status['running']) {
                proc_terminate($this->activeProcess, 9); // SIGKILL
            }

            try {
                proc_close($this->activeProcess);
            } catch (\Exception $e) {
                Log::warning($this->getProviderType() . ': Error closing process', [
                    'error' => $e->getMessage(),
                ]);
            }

            $this->activeProcess = null;
        }
    }

    /**
     * Default buildMessagesFromConversation for CLI providers.
     * CLI providers manage history internally, but this satisfies the interface.
     */
    public function buildMessagesFromConversation(Conversation $conversation): array
    {
        $messages = [];
        foreach ($conversation->messages as $message) {
            if ($message->role === 'system') continue;

            $content = $message->content;
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
     * Default syncAbortedMessage - no-op for CLI providers that don't support it.
     * ClaudeCodeProvider overrides with full implementation.
     */
    public function syncAbortedMessage(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage
    ): bool {
        return true; // No-op
    }
}
