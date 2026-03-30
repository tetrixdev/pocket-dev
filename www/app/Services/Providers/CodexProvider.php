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

    /**
     * Unique per-run system prompt filename to avoid overwriting user files.
     * Generated in buildCliCommand(), used by write/cleanup methods.
     */
    private ?string $systemPromptFile = null;

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
     * Check if Codex auth.json exists, is readable, and contains valid credentials.
     *
     * Validates the file content matches Codex's actual auth.json schema:
     * - OPENAI_API_KEY (API key mode)
     * - tokens.access_token (OAuth/subscription mode)
     */
    public function hasCredentials(): bool
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $authFile = $home . '/.codex/auth.json';

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

        // Valid if has OPENAI_API_KEY (API key format)
        if (!empty($data['OPENAI_API_KEY'])) {
            return true;
        }

        // Valid if has tokens.access_token (OAuth format)
        if (!empty($data['tokens']['access_token'])) {
            return true;
        }

        return false;
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

        // Add reasoning effort if configured (Codex config key: model_reasoning_effort)
        // See: https://developers.openai.com/codex/config-reference/
        $reasoningConfig = $conversation->getReasoningConfig();
        $effort = $reasoningConfig['effort'] ?? 'medium';
        if (!empty($effort) && $effort !== 'medium') {
            $parts[] = '-c';
            $parts[] = escapeshellarg('model_reasoning_effort="' . $effort . '"');
        }

        // Show full reasoning summaries in JSON output
        // "detailed" gives the most comprehensive thinking blocks available
        $parts[] = '-c';
        $parts[] = escapeshellarg('model_reasoning_summary="detailed"');

        // Working directory
        $workingDir = $conversation->working_directory ?? base_path();

        // Sync MCP servers from Claude Code config to Codex config.toml
        // This ensures Codex has access to the same MCP servers configured in the PocketDev UI
        $this->syncMcpServersFromClaudeCode($workingDir);

        $parts[] = '-C';
        $parts[] = escapeshellarg($workingDir);

        // Add system prompt via a unique per-run dotfile in the working directory.
        // Codex reads this file via project_doc_fallback_filenames and appends to context.
        // Using a unique filename per run prevents overwriting user files and avoids
        // collisions between concurrent sessions.
        if (!empty($options['system'])) {
            $uniqueId = bin2hex(random_bytes(8));
            $filename = ".pocketdev-system-prompt-{$uniqueId}.md";
            $this->systemPromptFile = rtrim($workingDir, '/') . '/' . $filename;
            $this->writePocketDevInstructionsFile($this->systemPromptFile, $options['system']);
            $parts[] = '-c';
            $parts[] = escapeshellarg("project_doc_fallback_filenames=[\"{$filename}\"]");
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

    protected function buildEnvironment(Conversation $conversation, array $options): array
    {
        $env = parent::buildEnvironment($conversation, $options);

        // Note: Reasoning effort is passed via -c flag in buildCliCommand(),
        // not via environment variable (unlike Claude Code's MAX_THINKING_TOKENS).
        // This override exists for parity and future Codex-specific env vars.

        return $env;
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
            // Compaction detection: Codex auto-compaction is completely silent in
            // JSONL output (no events emitted). For OpenAI models, it calls a
            // server-side responses/compact endpoint; for local models, it sends a
            // summarization prompt. The only observable signal is a significant drop
            // in input_tokens between turn.completed events. We track the previous
            // turn's input_tokens to detect this.
            'previousTurnInputTokens' => 0,
            'compactionDetected' => false,
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
        //
        // Note: auto-compaction is silent in JSONL output (no events emitted).
        // Compaction is detected via token count drops in turn.completed instead.
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
            // Note: cached_input_tokens represents cache READS (hits), not creation
            yield StreamEvent::usage(
                $state['inputTokens'],
                $state['outputTokens'],
                null,  // cacheCreation - Codex doesn't report this separately
                $state['cachedTokens'] > 0 ? $state['cachedTokens'] : null  // cacheRead
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
                    // Close any open text/thinking blocks before starting tool use
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
                    // Text response (or compaction summary)
                    $text = $item['text'] ?? '';
                    if ($text !== '') {
                        // Regular text response
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

                    if ($state['toolUseStarted']) {
                        yield StreamEvent::toolUseStop($state['blockIndex']);
                        $state['toolUseStarted'] = false;
                    }
                    yield StreamEvent::toolResult($toolId, $output, $exitCode !== 0);
                    $state['blockIndex']++;
                }
                break;

            case 'turn.completed':
                // Codex CLI reports token usage - just use values directly like ClaudeCodeProvider
                // The input_tokens represents current context usage (what's in the context window)
                $usage = $data['usage'] ?? [];
                $currentInputTokens = $usage['input_tokens'] ?? 0;
                $state['outputTokens'] = $usage['output_tokens'] ?? 0;
                $state['cachedTokens'] = $usage['cached_input_tokens'] ?? 0;

                // Detect auto-compaction: Codex compaction is silent in JSONL output
                // (no events emitted). The only signal is a significant drop in
                // input_tokens between turns. Compaction triggers at 90% of context
                // window and replaces history with a summary, causing a large token drop.
                $prevTokens = $state['previousTurnInputTokens'];
                if ($prevTokens > 0 && $currentInputTokens > 0 && $currentInputTokens < $prevTokens * 0.7) {
                    $state['compactionDetected'] = true;
                    $reduction = round((1 - $currentInputTokens / $prevTokens) * 100, 1);
                    Log::channel('api')->warning('CodexProvider: Auto-compaction detected (token drop)', [
                        'previous_input_tokens' => $prevTokens,
                        'current_input_tokens' => $currentInputTokens,
                        'reduction_percent' => $reduction,
                    ]);
                }

                $state['inputTokens'] = $currentInputTokens;
                $state['previousTurnInputTokens'] = $currentInputTokens;

                Log::channel('api')->info('CodexProvider: Turn completed', [
                    'input_tokens' => $state['inputTokens'],
                    'output_tokens' => $state['outputTokens'],
                    'cached_tokens' => $state['cachedTokens'],
                    'compaction_detected' => $state['compactionDetected'],
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
    // MCP server synchronization
    // ========================================================================

    /**
     * Sync MCP servers from Claude Code config (~/.claude.json) to Codex config (~/.codex/config.toml).
     *
     * Claude Code and Codex use different config formats:
     * - Claude: JSON in ~/.claude.json under "mcpServers" (global) and "projects.*.mcpServers" (per-project)
     * - Codex: TOML in ~/.codex/config.toml under [mcp_servers.*]
     *
     * This method reads both global and project-specific MCP servers from Claude's config
     * and writes them to Codex's config.toml, preserving any existing Codex-only settings.
     */
    private function syncMcpServersFromClaudeCode(string $workingDir): void
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $claudeConfigPath = $home . '/.claude.json';
        $codexConfigPath = $home . '/.codex/config.toml';

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
                    // Support both formats: array with 'name' field, or object keyed by name
                    $name = $this->sanitizeMcpServerName(
                        isset($server['name']) ? (string) $server['name'] : (string) $key
                    );
                    $mcpServers[$name] = [
                        'command' => $server['command'],
                        'args' => $server['args'] ?? [],
                        'env' => $server['env'] ?? [],
                    ];
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
                            $safeName = $this->sanitizeMcpServerName($name);
                            // Project-level overrides global
                            $mcpServers[$safeName] = [
                                'command' => $server['command'],
                                'args' => $server['args'] ?? [],
                                'env' => $server['env'] ?? [],
                            ];
                        }
                    }
                }
            }

            if (empty($mcpServers)) {
                return; // No MCP servers to sync
            }

            // 3. Read existing Codex config (preserve non-MCP settings)
            $nonMcpLines = [];
            if (is_readable($codexConfigPath)) {
                $existingToml = file_get_contents($codexConfigPath);
                // Extract lines that are NOT part of [mcp_servers.*] sections
                $lines = explode("\n", $existingToml);
                $inMcpSection = false;
                foreach ($lines as $line) {
                    if (preg_match('/^\[mcp_servers\b/', $line)) {
                        $inMcpSection = true;
                        continue;
                    }
                    if ($inMcpSection && preg_match('/^\[(?!mcp_servers)/', $line)) {
                        $inMcpSection = false;
                    }
                    if (!$inMcpSection) {
                        $nonMcpLines[] = $line;
                    }
                }
            }

            // 4. Build TOML content for MCP servers
            $tomlParts = [];

            // Keep non-MCP config
            $nonMcpContent = trim(implode("\n", $nonMcpLines));
            if (!empty($nonMcpContent)) {
                $tomlParts[] = $nonMcpContent;
            }

            // Add MCP servers
            foreach ($mcpServers as $name => $server) {
                $section = "[mcp_servers.{$name}]";
                $section .= "\ncommand = " . $this->toTomlString($server['command']);

                if (!empty($server['args'])) {
                    $argsToml = array_map(fn($a) => $this->toTomlString((string) $a), $server['args']);
                    $section .= "\nargs = [" . implode(', ', $argsToml) . "]";
                }

                if (!empty($server['env'])) {
                    $section .= "\n\n[mcp_servers.{$name}.env]";
                    foreach ($server['env'] as $key => $value) {
                        $section .= "\n{$key} = " . $this->toTomlString((string) $value);
                    }
                }

                $tomlParts[] = $section;
            }

            // 5. Write config.toml
            $dir = dirname($codexConfigPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $content = implode("\n\n", $tomlParts) . "\n";
            if (file_put_contents($codexConfigPath, $content, LOCK_EX) === false) {
                Log::channel('api')->error('CodexProvider: Failed to write Codex MCP config', [
                    'file' => $codexConfigPath,
                ]);
                return;
            }

            Log::channel('api')->info('CodexProvider: Synced MCP servers from Claude Code', [
                'server_count' => count($mcpServers),
                'servers' => array_keys($mcpServers),
            ]);

        } catch (\Throwable $e) {
            Log::channel('api')->warning('CodexProvider: Failed to sync MCP servers', [
                'error' => $e->getMessage(),
            ]);
            // Non-fatal: Codex can still work without MCP servers
        }
    }

    /**
     * Sanitize an MCP server name for use as a TOML key.
     * TOML bare keys allow: A-Za-z0-9, dash, underscore.
     */
    private function sanitizeMcpServerName(string $name): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
        $sanitized = trim($sanitized, '-');
        return $sanitized !== '' ? $sanitized : 'unnamed-server';
    }

    /**
     * Escape a string value for TOML format.
     */
    private function toTomlString(string $value): string
    {
        // Use basic string (double quotes) with escaping
        $escaped = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $value);
        return '"' . $escaped . '"';
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Write system prompt to a unique per-run dotfile.
     * Codex reads this via project_doc_fallback_filenames config.
     *
     * Uses a unique filename per run (e.g., .pocketdev-system-prompt-a1b2c3d4e5f6.md)
     * to prevent overwriting user files and avoid collisions between concurrent sessions.
     * Users should add ".pocketdev-system-prompt-*" to their .gitignore.
     *
     * @param string $file Full path to the unique system prompt file
     * @param string $content The system prompt content
     * @throws \RuntimeException if content exceeds PROJECT_DOC_MAX_BYTES or write fails
     */
    private function writePocketDevInstructionsFile(string $file, string $content): void
    {
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
            Log::channel('api')->error('CodexProvider: Failed to write system prompt file', [
                'file' => $file,
            ]);
            throw new \RuntimeException("Failed to write Codex system prompt file: {$file}");
        }
    }

    /**
     * Remove the temporary system prompt file after Codex has read it.
     * Only removes the file created by this run (tracked via $systemPromptFile).
     */
    private function cleanupPocketDevInstructionsFile(string $workingDir): void
    {
        $file = $this->systemPromptFile;

        if ($file !== null && file_exists($file)) {
            @unlink($file);
            $this->systemPromptFile = null;
        }
    }
}
