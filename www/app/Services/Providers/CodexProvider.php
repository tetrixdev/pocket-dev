<?php

namespace App\Services\Providers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ModelRepository;
use App\Streaming\StreamEvent;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Codex CLI provider.
 *
 * Uses the `codex` CLI tool with streaming JSON output.
 * Codex manages its own conversation history via thread IDs.
 */
class CodexProvider extends AbstractCliProvider
{
    /**
     * Maximum bytes for system prompt file (Codex default is 32KB).
     * Set high to accommodate PocketDev's large system prompt (~108KB currently).
     */
    private const PROJECT_DOC_MAX_BYTES = 500000; // ~488KB

    public function __construct(ModelRepository $models)
    {
        parent::__construct($models);
    }

    // ========================================================================
    // Provider identity
    // ========================================================================

    public function getProviderType(): string
    {
        return 'codex';
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

    // ========================================================================
    // Template method implementations
    // ========================================================================

    protected function isCliBinaryAvailable(): bool
    {
        $output = [];
        $returnCode = 0;
        exec('which codex 2>/dev/null', $output, $returnCode);
        return $returnCode === 0 && !empty($output);
    }

    protected function hasAuthCredentials(): bool
    {
        return $this->hasCredentials();
    }

    protected function getAuthRequiredError(): string
    {
        return 'CODEX_AUTH_REQUIRED:Codex authentication required. Please run: codex login --device-auth';
    }

    protected function buildCliCommand(
        Conversation $conversation,
        array $options
    ): string {
        $model = $conversation->model ?? config('ai.providers.codex.default_model', 'gpt-5.3-codex');

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
            // Increase max bytes for system prompt (default is 32KB, PocketDev prompt is ~108KB)
            $parts[] = '-c';
            $parts[] = escapeshellarg('project_doc_max_bytes=' . self::PROJECT_DOC_MAX_BYTES);
        }

        // Resume existing session (must come AFTER options, BEFORE prompt)
        $sessionId = $this->getSessionId($conversation);
        if (!empty($sessionId)) {
            $parts[] = 'resume';
            $parts[] = escapeshellarg($sessionId);
        }

        // No need to pass OPENAI_API_KEY - Codex reads from ~/.codex/auth.json
        // which is set up via `codex login`

        return implode(' ', $parts);
    }

    protected function prepareProcessInput(string $command, string $userMessage): array
    {
        // Codex takes prompt as argument, not stdin
        return [
            'command' => $command . ' ' . escapeshellarg($userMessage),
            'stdin' => null,
        ];
    }

    protected function initParseState(): array
    {
        return [
            'blockIndex' => 0,
            'textStarted' => false,
            'thinkingStarted' => false,
            'toolUseStarted' => false,
            'threadId' => null,
            // Codex reports cumulative tokens - these represent current context usage
            'inputTokens' => 0,
            'outputTokens' => 0,
            'cachedTokens' => 0,
        ];
    }

    protected function getSessionIdFromState(array $state): ?string
    {
        return $state['threadId'];
    }

    protected function classifyEventForTimeout(array $parsedData): array
    {
        $type = $parsedData['type'] ?? '';

        // Codex doesn't have tool_progress events yet
        // All events reset the timer
        return [
            'phase' => match ($type) {
                'item.started' => 'tool_execution',  // command_execution starting
                'item.completed' => 'streaming',
                'thread.started', 'turn.started' => 'initial',
                default => null,
            },
            'resetsTimer' => true,
            'shouldSkip' => false,
        ];
    }

    protected function closeOpenBlocks(array $state): Generator
    {
        if ($state['textStarted']) {
            yield StreamEvent::textStop($state['blockIndex']);
        }
        if ($state['thinkingStarted']) {
            yield StreamEvent::thinkingStop($state['blockIndex']);
        }
        if ($state['toolUseStarted']) {
            yield StreamEvent::toolUseStop($state['blockIndex']);
        }
    }

    protected function emitUsage(array $state): Generator
    {
        if ($state['inputTokens'] > 0 || $state['outputTokens'] > 0) {
            // Codex reports cumulative tokens which represent current context usage
            // (same approach as ClaudeCodeProvider)
            yield StreamEvent::usage(
                $state['inputTokens'],
                $state['outputTokens'],
                $state['cachedTokens'] > 0 ? $state['cachedTokens'] : null
            );
        }
    }

    protected function getCompletionSummary(array $state, int $exitCode): array
    {
        return [
            'exit_code' => $exitCode,
            'thread_id' => $state['threadId'],
            'input_tokens' => $state['inputTokens'],
            'output_tokens' => $state['outputTokens'],
            'cached_tokens' => $state['cachedTokens'],
        ];
    }

    protected function onProcessComplete(Conversation $conversation, array $state, int $exitCode): void
    {
        // Clean up system prompt file after process completes
        // Codex reads this at startup, so it's safe to remove after proc_close
        $this->cleanupPocketDevInstructionsFile($conversation->working_directory ?? base_path());
    }

    // ========================================================================
    // JSONL Parsing (Codex specific)
    // ========================================================================

    /**
     * Parse a JSONL line and yield StreamEvents.
     *
     * Codex outputs lines like:
     * {"type":"thread.started","thread_id":"..."}
     * {"type":"turn.started"}
     * {"type":"item.completed","item":{"id":"...","type":"reasoning|agent_message|command_execution",...}}
     * {"type":"turn.completed","usage":{"input_tokens":...,"output_tokens":...}}
     */
    protected function parseJsonLine(string $line, array &$state, ?array $preDecoded = null): Generator
    {
        $data = $preDecoded ?? json_decode($line, true);

        if (!is_array($data)) {
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
                    $state['toolUseStarted'] = true;
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
                    $state['toolUseStarted'] = false;
                    yield StreamEvent::toolResult($toolId, $output, $exitCode !== 0);
                    $state['blockIndex']++;
                }
                break;

            case 'turn.completed':
                // Codex CLI reports token usage - just use values directly like ClaudeCodeProvider
                // The input_tokens represents current context usage (what's in the context window)
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

    // ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Write system prompt to POCKETDEV-SYSTEM.md in the working directory.
     * Codex reads this via project_doc_fallback_filenames config.
     * Using a unique filename avoids overwriting user's AGENTS.md.
     *
     * @throws \RuntimeException if content exceeds PROJECT_DOC_MAX_BYTES
     */
    private function writePocketDevInstructionsFile(string $workingDir, string $content): void
    {
        $file = rtrim($workingDir, '/') . '/POCKETDEV-SYSTEM.md';
        $contentBytes = strlen($content);

        // Check if system prompt exceeds the configured limit
        if ($contentBytes > self::PROJECT_DOC_MAX_BYTES) {
            $contentKb = round($contentBytes / 1024, 1);
            $limitKb = round(self::PROJECT_DOC_MAX_BYTES / 1024, 1);

            Log::channel('api')->error('CodexProvider: System prompt exceeds size limit', [
                'content_bytes' => $contentBytes,
                'limit_bytes' => self::PROJECT_DOC_MAX_BYTES,
                'file' => $file,
            ]);

            throw new \RuntimeException(
                "System prompt too large for Codex ({$contentKb}KB exceeds {$limitKb}KB limit). " .
                "Try reducing enabled tools, memory tables, or skills in PocketDev settings."
            );
        }

        // Log size for monitoring (helps catch approaching limits)
        if ($contentBytes > self::PROJECT_DOC_MAX_BYTES * 0.8) {
            $usagePercent = round(($contentBytes / self::PROJECT_DOC_MAX_BYTES) * 100);
            Log::channel('api')->warning('CodexProvider: System prompt approaching size limit', [
                'content_bytes' => $contentBytes,
                'limit_bytes' => self::PROJECT_DOC_MAX_BYTES,
                'usage_percent' => $usagePercent,
            ]);
        }

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
}
