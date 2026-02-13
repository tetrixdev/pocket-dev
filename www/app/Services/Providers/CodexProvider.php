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
 * OpenAI Codex CLI provider.
 *
 * Uses the `codex` CLI tool with streaming JSON output.
 * Codex manages its own conversation history via thread IDs.
 */
class CodexProvider extends AbstractCliProvider
{
    private AppSettingsService $appSettings;

    public function __construct(ModelRepository $models, AppSettingsService $appSettings)
    {
        parent::__construct($models);
        $this->appSettings = $appSettings;
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
            'threadId' => null,
            'inputTokens' => 0,
            'outputTokens' => 0,
            'cachedTokens' => 0,
            // Track last turn's tokens for context percentage (not cumulative)
            'lastTurnInputTokens' => 0,
            'lastTurnOutputTokens' => 0,
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
    }

    protected function emitUsage(array $state): Generator
    {
        if ($state['inputTokens'] > 0 || $state['outputTokens'] > 0) {
            // Pass cumulative tokens for billing, and last turn tokens for context tracking
            // The context window size will be added by ProcessConversationStream
            yield StreamEvent::usage(
                $state['inputTokens'],
                $state['outputTokens'],
                $state['cachedTokens'] > 0 ? $state['cachedTokens'] : null,
                null,  // cacheRead (not tracked separately)
                null,  // cost (calculated by ProcessConversationStream)
                null,  // contextWindowSize (added by ProcessConversationStream)
                $state['lastTurnInputTokens'] > 0 ? $state['lastTurnInputTokens'] : null,
                $state['lastTurnOutputTokens'] > 0 ? $state['lastTurnOutputTokens'] : null
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
                // Capture usage information - accumulate across multiple turns for billing
                $usage = $data['usage'] ?? [];
                $turnInputTokens = $usage['input_tokens'] ?? 0;
                $turnOutputTokens = $usage['output_tokens'] ?? 0;
                $turnCachedTokens = $usage['cached_input_tokens'] ?? 0;

                $state['inputTokens'] += $turnInputTokens;
                $state['outputTokens'] += $turnOutputTokens;
                $state['cachedTokens'] += $turnCachedTokens;

                // Track last turn's tokens for context percentage calculation
                // The input_tokens from the last turn represents current context usage
                $state['lastTurnInputTokens'] = $turnInputTokens;
                $state['lastTurnOutputTokens'] = $turnOutputTokens;

                Log::channel('api')->info('CodexProvider: Turn completed', [
                    'turn_input_tokens' => $turnInputTokens,
                    'turn_output_tokens' => $turnOutputTokens,
                    'total_input_tokens' => $state['inputTokens'],
                    'total_output_tokens' => $state['outputTokens'],
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
}
