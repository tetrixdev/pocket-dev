<?php

namespace App\Services\Providers;

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
use App\Models\Credential;
use App\Models\Message;
use App\Services\AppSettingsService;
use App\Services\ModelRepository;
use App\Services\Providers\Traits\InjectsInterruptionReminder;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Codex CLI provider.
 *
 * Uses the `codex` CLI tool with streaming JSON output.
 * Codex manages its own conversation history via thread IDs.
 */
class CodexProvider implements AIProviderInterface
{
    use InjectsInterruptionReminder;

    private ModelRepository $models;
    private AppSettingsService $appSettings;
    /** @var resource|null */
    private $activeProcess = null;

    public function __construct(ModelRepository $models, AppSettingsService $appSettings)
    {
        $this->models = $models;
        $this->appSettings = $appSettings;
    }

    public function getProviderType(): string
    {
        return 'codex';
    }

    /**
     * Check if Codex CLI is installed.
     */
    public function isAvailable(): bool
    {
        $output = [];
        $returnCode = 0;
        exec('which codex 2>/dev/null', $output, $returnCode);

        return $returnCode === 0 && !empty($output);
    }

    /**
     * Check if Codex auth.json exists and is readable (contains either OAuth or API key).
     */
    public function hasCredentials(): bool
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $authFile = $home . '/.codex/auth.json';
        return is_readable($authFile);
    }

    /**
     * Codex authentication is handled entirely via ~/.codex/auth.json
     * (set up via `codex login --device-auth` for subscription or
     * `codex login --with-api-key` for API key)
     */
    public function isAuthenticated(): bool
    {
        return $this->hasCredentials();
    }

    public function getModels(): array
    {
        return $this->models->getModelsArray('codex');
    }

    public function getContextWindow(string $model): int
    {
        return $this->models->getContextWindow($model);
    }

    /**
     * Stream a message using Codex CLI.
     *
     * Codex manages conversation history internally via thread IDs.
     * We only send the latest user message; previous context is restored automatically.
     */
    public function streamMessage(
        Conversation $conversation,
        array $options = []
    ): Generator {
        if (!$this->isAvailable()) {
            yield StreamEvent::error('Codex CLI not available. Install with: npm i -g @openai/codex');
            return;
        }

        if (!$this->isAuthenticated()) {
            yield StreamEvent::error('CODEX_AUTH_REQUIRED:Codex authentication required. Please run: codex login --device-auth');
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

        $command = $this->buildCommand($conversation, $options);

        Log::channel('api')->info('CodexProvider: Starting CLI stream', [
            'conversation_id' => $conversation->id,
            'thread_id' => $conversation->codex_session_id ?? 'new',
            'model' => $conversation->model,
            'user_message' => substr($latestMessage, 0, 100),
            'command_preview' => substr($command, 0, 300) . '...',
        ]);

        yield from $this->executeAndStream($command, $latestMessage, $conversation, $options);
    }

    /**
     * Build messages array from conversation.
     */
    public function buildMessagesFromConversation(Conversation $conversation): array
    {
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
     * Build the Codex CLI command.
     *
     * Command structure:
     * - New session: codex exec [OPTIONS] PROMPT
     * - Resume: codex exec [OPTIONS] resume SESSION_ID PROMPT
     */
    private function buildCommand(
        Conversation $conversation,
        array $options
    ): string {
        $model = $conversation->model ?? config('ai.providers.codex.default_model', 'gpt-5.2-codex');

        $parts = ['codex', 'exec'];

        // Add flags BEFORE resume subcommand (required by CLI)
        $parts[] = '--json';
        $parts[] = '--skip-git-repo-check';
        $parts[] = '--dangerously-bypass-approvals-and-sandbox';
        $parts[] = '--model';
        $parts[] = escapeshellarg($model);

        // Working directory
        $workingDir = $conversation->working_directory ?? base_path();
        $parts[] = '-C';
        $parts[] = escapeshellarg($workingDir);

        // Add system prompt via POCKETDEV-SYSTEM.md in the working directory
        // Codex reads this file via project_doc_fallback_filenames and appends to context
        // Using a unique filename avoids overwriting user's AGENTS.md
        if (!empty($options['system'])) {
            $this->writePocketDevInstructionsFile($workingDir, $options['system']);
            $parts[] = '-c';
            $parts[] = escapeshellarg('project_doc_fallback_filenames=["POCKETDEV-SYSTEM.md"]');
        }

        // Resume existing session (must come AFTER options, BEFORE prompt)
        if (!empty($conversation->codex_session_id)) {
            $parts[] = 'resume';
            $parts[] = escapeshellarg($conversation->codex_session_id);
        }

        // No need to pass OPENAI_API_KEY - Codex reads from ~/.codex/auth.json
        // which is set up via `codex login`

        return implode(' ', $parts);
    }

    /**
     * Write system prompt to POCKETDEV-SYSTEM.md in the working directory.
     * Codex reads this via project_doc_fallback_filenames config.
     * Using a unique filename avoids overwriting user's AGENTS.md.
     */
    private function writePocketDevInstructionsFile(string $workingDir, string $content): void
    {
        $file = rtrim($workingDir, '/') . '/POCKETDEV-SYSTEM.md';

        if (file_put_contents($file, $content) === false) {
            Log::channel('api')->warning('CodexProvider: Failed to write POCKETDEV-SYSTEM.md', [
                'file' => $file,
            ]);
        }
    }

    /**
     * Remove the temporary system prompt file after Codex has read it.
     */
    private function cleanupPocketDevInstructionsFile(string $workingDir): void
    {
        $file = rtrim($workingDir, '/') . '/POCKETDEV-SYSTEM.md';

        if (file_exists($file)) {
            @unlink($file);
        }
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
        // Append user message as argument (Codex takes prompt as arg, not stdin)
        $fullCommand = $command . ' ' . escapeshellarg($userMessage) . ' 2>&1';

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Get workspace-specific credentials merged with global ones
        $workspaceId = $conversation->workspace_id;
        $credentials = Credential::getEnvArrayForWorkspace($workspaceId);

        // Merge credentials with current environment
        $env = array_merge($_ENV, $_SERVER, $credentials);

        // Filter to only string values (proc_open requires this)
        $env = array_filter($env, fn($v) => is_string($v) || is_numeric($v));
        $env = array_map(fn($v) => (string) $v, $env);

        // Inject session ID for panel tool support
        // This allows `pd tool:run` to automatically know the session context
        $sessionId = $conversation->screen?->session?->id;
        if ($sessionId) {
            $env['POCKETDEV_SESSION_ID'] = $sessionId;
        }

        $process = proc_open($fullCommand, $descriptors, $pipes, $conversation->working_directory ?? base_path(), $env);

        if (!is_resource($process)) {
            yield StreamEvent::error('Failed to start Codex CLI process');
            return;
        }

        $this->activeProcess = $process;

        // Close stdin (not needed for Codex)
        fclose($pipes[0]);

        // Set stdout to non-blocking
        stream_set_blocking($pipes[1], false);

        $buffer = '';
        $state = [
            'blockIndex' => 0,
            'textStarted' => false,
            'thinkingStarted' => false,
            'threadId' => null,
            'inputTokens' => 0,
            'outputTokens' => 0,
            'cachedTokens' => 0,
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

            // Update conversation with thread ID if we got one
            if ($state['threadId'] && $conversation->codex_session_id !== $state['threadId']) {
                $conversation->codex_session_id = $state['threadId'];
                $conversation->save();
            }

            // Emit usage if available
            if ($state['inputTokens'] > 0 || $state['outputTokens'] > 0) {
                yield StreamEvent::usage(
                    $state['inputTokens'],
                    $state['outputTokens'],
                    $state['cachedTokens'] > 0 ? $state['cachedTokens'] : null
                );
            }

            // Close pipes before proc_close
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($pipes[2])) {
                fclose($pipes[2]);
            }

            $exitCode = proc_close($process);
            $this->activeProcess = null;

            // Clean up system prompt file after process completes
            // Codex reads this at startup, so it's safe to remove after proc_close
            $this->cleanupPocketDevInstructionsFile($conversation->working_directory ?? base_path());

            if ($exitCode !== 0) {
                Log::channel('api')->warning('CodexProvider: CLI exited with non-zero code', [
                    'exit_code' => $exitCode,
                ]);
            }

            yield StreamEvent::done('end_turn');

        } catch (\Throwable $e) {
            Log::channel('api')->error('CodexProvider: Stream error', [
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
            $this->activeProcess = null;

            // Clean up system prompt file on error too
            $this->cleanupPocketDevInstructionsFile($conversation->working_directory ?? base_path());
        }
    }

    /**
     * Abort the current streaming operation.
     */
    public function abort(): void
    {
        if ($this->activeProcess !== null && is_resource($this->activeProcess)) {
            // Try graceful termination first (15 = SIGTERM)
            proc_terminate($this->activeProcess, 15);

            // Give it 100ms to terminate gracefully
            usleep(100000);

            $status = proc_get_status($this->activeProcess);
            if ($status['running']) {
                // Force kill if still running (9 = SIGKILL)
                proc_terminate($this->activeProcess, 9);
            }

            try {
                proc_close($this->activeProcess);
            } catch (\Exception $e) {
                Log::warning('CodexProvider: Error closing process', ['error' => $e->getMessage()]);
            }

            $this->activeProcess = null;
        }
    }

    /**
     * Sync aborted message to native storage.
     * TODO: Implement if Codex CLI has a session file format that can be synced.
     * For now, this is a no-op since Codex session storage format is unknown.
     */
    public function syncAbortedMessage(
        Conversation $conversation,
        Message $userMessage,
        Message $assistantMessage
    ): bool {
        return true;
    }

    /**
     * Parse a JSONL line and yield StreamEvents.
     *
     * Codex outputs lines like:
     * {"type":"thread.started","thread_id":"..."}
     * {"type":"turn.started"}
     * {"type":"item.completed","item":{"id":"...","type":"reasoning|agent_message|command_execution",...}}
     * {"type":"turn.completed","usage":{"input_tokens":...,"output_tokens":...}}
     */
    private function parseJsonLine(string $line, array &$state): Generator
    {
        $data = json_decode($line, true);

        if ($data === null) {
            Log::channel('api')->debug('CodexProvider: Failed to parse JSON line', [
                'line' => substr($line, 0, 200),
            ]);
            return;
        }

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'thread.started':
                // Capture thread ID for session resume
                $state['threadId'] = $data['thread_id'] ?? null;
                Log::channel('api')->info('CodexProvider: Thread started', [
                    'thread_id' => $state['threadId'],
                ]);
                break;

            case 'turn.started':
                // Turn beginning - nothing specific to emit
                break;

            case 'item.started':
                // Item in progress (e.g., command running)
                $item = $data['item'] ?? [];
                $itemType = $item['type'] ?? '';

                if ($itemType === 'command_execution') {
                    // Start tool use block for command
                    $toolId = $item['id'] ?? 'tool_' . $state['blockIndex'];
                    $command = $item['command'] ?? '';

                    yield StreamEvent::toolUseStart($state['blockIndex'], $toolId, 'Bash');
                    yield StreamEvent::toolUseDelta($state['blockIndex'], json_encode(['command' => $command]));
                }
                break;

            case 'item.completed':
                $item = $data['item'] ?? [];
                $itemType = $item['type'] ?? '';

                if ($itemType === 'reasoning') {
                    // Thinking/reasoning content
                    $text = $item['text'] ?? '';
                    if ($text !== '') {
                        // Close text block if open
                        if ($state['textStarted']) {
                            yield StreamEvent::textStop($state['blockIndex']);
                            $state['textStarted'] = false;
                            $state['blockIndex']++;
                        }

                        yield StreamEvent::thinkingStart($state['blockIndex']);
                        $state['thinkingStarted'] = true;
                        yield StreamEvent::thinkingDelta($state['blockIndex'], $text);
                        yield StreamEvent::thinkingStop($state['blockIndex']);
                        $state['thinkingStarted'] = false;
                        $state['blockIndex']++;
                    }
                } elseif ($itemType === 'agent_message') {
                    // Text response
                    $text = $item['text'] ?? '';
                    if ($text !== '') {
                        // Close thinking block if open
                        if ($state['thinkingStarted']) {
                            yield StreamEvent::thinkingStop($state['blockIndex']);
                            $state['thinkingStarted'] = false;
                            $state['blockIndex']++;
                        }

                        if (!$state['textStarted']) {
                            yield StreamEvent::textStart($state['blockIndex']);
                            $state['textStarted'] = true;
                        }
                        yield StreamEvent::textDelta($state['blockIndex'], $text);
                        yield StreamEvent::textStop($state['blockIndex']);
                        $state['textStarted'] = false;
                        $state['blockIndex']++;
                    }
                } elseif ($itemType === 'command_execution') {
                    // Command execution completed
                    $toolId = $item['id'] ?? 'tool_' . $state['blockIndex'];
                    $output = $item['aggregated_output'] ?? '';
                    $exitCode = $item['exit_code'] ?? 0;

                    yield StreamEvent::toolUseStop($state['blockIndex']);
                    yield StreamEvent::toolResult($toolId, $output, $exitCode !== 0);
                    $state['blockIndex']++;
                }
                break;

            case 'turn.completed':
                // Capture usage information
                $usage = $data['usage'] ?? [];
                $state['inputTokens'] = $usage['input_tokens'] ?? 0;
                $state['outputTokens'] = $usage['output_tokens'] ?? 0;
                $state['cachedTokens'] = $usage['cached_input_tokens'] ?? 0;

                Log::channel('api')->info('CodexProvider: Turn completed', [
                    'input_tokens' => $state['inputTokens'],
                    'output_tokens' => $state['outputTokens'],
                    'cached_tokens' => $state['cachedTokens'],
                ]);
                break;

            case 'error':
                $message = $data['message'] ?? 'Unknown error';
                yield StreamEvent::error($message);
                break;

            case 'turn.failed':
                $error = $data['error'] ?? [];
                $message = $error['message'] ?? 'Turn failed';
                yield StreamEvent::error($message);
                break;

            default:
                Log::channel('api')->debug('CodexProvider: Unknown event type', [
                    'type' => $type,
                ]);
        }
    }
}
