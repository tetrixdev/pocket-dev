<?php

namespace App\Services;

use App\Exceptions\ClaudeCode\{
    CLINotFoundException,
    ClaudeCodeException,
    JSONDecodeException,
    ProcessFailedException,
    TimeoutException
};
use Illuminate\Support\Facades\Log;

class ClaudeCodeService
{
    protected string $cliPath;
    protected array $defaultOptions;
    protected ?int $timeout;

    public function __construct()
    {
        $this->cliPath = config('claude.cli_path', 'claude');
        $this->timeout = config('claude.timeout');
        $this->defaultOptions = [
            'model' => config('claude.default_model'),
            'allowed_tools' => config('claude.allowed_tools'),
            'permission_mode' => config('claude.permission_mode'),
            'max_turns' => config('claude.max_turns'),
            'cwd' => config('claude.working_directory'),
        ];

        $this->verifyCLIExists();
    }

    /**
     * Verify that the Claude Code CLI is installed and accessible.
     *
     * @throws CLINotFoundException
     */
    protected function verifyCLIExists(): void
    {
        $command = $this->cliPath === 'claude' ? 'which claude' : "test -f {$this->cliPath}";
        exec($command . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new CLINotFoundException();
        }
    }

    /**
     * Execute a synchronous query to Claude Code.
     *
     * @param string $prompt The prompt to send to Claude
     * @param array $options Additional options to override defaults
     * @return array The parsed response from Claude
     * @throws ClaudeCodeException
     */
    public function query(string $prompt, array $options = []): array
    {
        $this->log('info', 'Starting Claude Code query', ['prompt' => $prompt]);

        $mergedOptions = array_merge($this->defaultOptions, $options);
        $result = $this->execute(
            $prompt,
            $mergedOptions,
            $options['sessionId'] ?? null,
            $options['isFirstMessage'] ?? true,
            $options['thinking_level'] ?? 0
        );

        $this->log('info', 'Claude Code query completed successfully');

        return $result;
    }

    /**
     * Execute a streaming query to Claude Code.
     *
     * @param string $prompt The prompt to send to Claude
     * @param callable $callback Callback function to handle each message chunk
     * @param array $options Additional options to override defaults
     * @return void
     * @throws ClaudeCodeException
     */
    public function streamQuery(string $prompt, callable $callback, array $options = []): void
    {
        $this->log('info', 'Starting Claude Code streaming query', ['prompt' => $prompt]);

        $mergedOptions = array_merge($this->defaultOptions, $options);
        $this->executeStreaming(
            $prompt,
            $callback,
            $mergedOptions,
            $options['sessionId'] ?? null,
            $options['isFirstMessage'] ?? true,
            $options['thinking_level'] ?? 0
        );

        // Clean up PID file if it exists
        if (isset($options['sessionId'])) {
            $this->removePidFile($options['sessionId']);
        }

        $this->log('info', 'Claude Code streaming query completed');
    }

    /**
     * Build CLI command flags from options.
     *
     * @param array $options
     * @return string CLI flags
     */
    protected function buildCommandFlags(array $options): string
    {
        $flags = '';

        // Add model flag
        if (!empty($options['model'])) {
            $flags .= " --model {$options['model']}";
        }

        // Add permission mode flag
        if (!empty($options['permission_mode'])) {
            $flags .= " --permission-mode {$options['permission_mode']}";
        }

        // Add allowed tools flag
        if (!empty($options['allowed_tools']) && is_array($options['allowed_tools'])) {
            $tools = implode(',', $options['allowed_tools']);
            $flags .= " --allowed-tools {$tools}";
        }

        // Add max turns flag
        if (!empty($options['max_turns'])) {
            $flags .= " --max-turns {$options['max_turns']}";
        }

        return $flags;
    }

    /**
     * Execute Claude Code CLI synchronously.
     *
     * @param string $prompt Plain text prompt
     * @param array $options Merged options array
     * @param string|null $sessionId Claude session UUID
     * @param bool $isFirstMessage Whether this is the first message in the session
     * @param int $thinkingLevel Thinking mode level (0-4)
     * @return array Parsed JSON response
     * @throws ClaudeCodeException
     */
    protected function execute(string $prompt, array $options, ?string $sessionId = null, bool $isFirstMessage = true, int $thinkingLevel = 0): array
    {
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $command = "{$this->cliPath} --print --output-format json";

        // Add session management flags
        if ($sessionId) {
            if ($isFirstMessage) {
                $command .= " --session-id {$sessionId}";
            } else {
                $command .= " --resume {$sessionId}";
            }
        }

        // Add option flags
        $command .= $this->buildCommandFlags($options);

        $cwd = $options['cwd'] ?? config('claude.working_directory');

        // Build environment variables
        $env = $this->buildEnvironment($thinkingLevel);

        $this->log('debug', 'Executing Claude Code', [
            'command' => $command,
            'cwd' => $cwd,
            'sessionId' => $sessionId,
            'isFirstMessage' => $isFirstMessage,
            'prompt_length' => strlen($prompt),
            'thinking_level' => $thinkingLevel,
            'max_thinking_tokens' => is_array($env) ? ($env['MAX_THINKING_TOKENS'] ?? 0) : 0
        ]);

        // Pass null to inherit parent environment if no custom env needed
        $process = proc_open($command, $descriptorspec, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            throw new ClaudeCodeException('Failed to start Claude Code process');
        }

        // Write plain text prompt to stdin
        fwrite($pipes[0], $prompt);
        fclose($pipes[0]);

        // Apply timeout if configured
        if ($this->timeout) {
            stream_set_timeout($pipes[1], $this->timeout);
            stream_set_timeout($pipes[2], $this->timeout);
        }

        // Read output
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        // Check for timeout
        $info = stream_get_meta_data($pipes[1]);
        if ($info['timed_out']) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($process);
            proc_close($process);

            throw new TimeoutException($this->timeout);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            $this->log('error', 'Claude Code process failed', ['stdout' => $output, 
                'exit_code' => $returnCode,
                'stderr' => $stderr
            ]);

            throw new ProcessFailedException($returnCode, $stderr);
        }

        return $this->parseJsonOutput($output);
    }

    /**
     * Execute Claude Code CLI with streaming output.
     *
     * @param string $prompt Plain text prompt
     * @param callable $callback Callback to handle each chunk
     * @param array $options Merged options array
     * @param string|null $sessionId Claude session UUID
     * @param bool $isFirstMessage Whether this is the first message in the session
     * @param int $thinkingLevel Thinking mode level (0-4)
     * @return void
     * @throws ClaudeCodeException
     */
    protected function executeStreaming(string $prompt, callable $callback, array $options, ?string $sessionId = null, bool $isFirstMessage = true, int $thinkingLevel = 0): void
    {
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        // --include-partial-messages enables token-by-token streaming from Claude API
        $command = "{$this->cliPath} --print --output-format stream-json --verbose --include-partial-messages";

        // Add session management flags
        if ($sessionId) {
            if ($isFirstMessage) {
                $command .= " --session-id {$sessionId}";
            } else {
                $command .= " --resume {$sessionId}";
            }
        }

        // Add option flags
        $command .= $this->buildCommandFlags($options);

        $cwd = $options['cwd'] ?? config('claude.working_directory');

        // Build environment variables
        $env = $this->buildEnvironment($thinkingLevel);

        \Log::info('[ClaudeCodeService] Executing Claude Code (streaming)', [
            'command' => $command,
            'cwd' => $cwd,
            'sessionId' => $sessionId,
            'isFirstMessage' => $isFirstMessage,
            'prompt_length' => strlen($prompt),
            'thinking_level' => $thinkingLevel,
            'max_thinking_tokens' => is_array($env) ? ($env['MAX_THINKING_TOKENS'] ?? 0) : 0
        ]);

        // Pass null to inherit parent environment if no custom env needed
        $process = proc_open($command, $descriptorspec, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            throw new ClaudeCodeException('Failed to start Claude Code process');
        }

        // Write PID file for cancellation support
        if ($sessionId) {
            $status = proc_get_status($process);
            if ($status && isset($status['pid'])) {
                $this->writePidFile($sessionId, $status['pid']);
                $this->log('debug', 'Wrote PID file for session', ['pid' => $status['pid']]);
            }
        }

        // Write plain text prompt to stdin
        fwrite($pipes[0], $prompt);
        fclose($pipes[0]);

        // Set non-blocking mode for streaming
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startTime = time();
        $buffer = '';

        // Stream output line by line
        while (!feof($pipes[1])) {
            // Check timeout
            if ($this->timeout && (time() - $startTime) > $this->timeout) {
                proc_terminate($process);
                throw new TimeoutException($this->timeout);
            }

            $line = fgets($pipes[1]);

            if ($line === false) {
                usleep(10000); // 10ms sleep to prevent CPU spinning
                continue;
            }

            $buffer .= $line;

            // Process complete JSON objects (one per line)
            if (substr(trim($line), -1) === '}') {
                try {
                    $data = $this->parseJsonOutput($buffer);
                    $callback($data);
                    $buffer = '';
                } catch (JSONDecodeException $e) {
                    // Continue buffering if JSON is incomplete
                    continue;
                }
            }
        }

        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        \Log::info('[ClaudeCodeService] Process finished', [
            'return_code' => $returnCode,
            'has_stderr' => !empty($stderr),
            'stderr_length' => strlen($stderr)
        ]);

        if (!empty($stderr)) {
            \Log::warning('[ClaudeCodeService] Process stderr output', [
                'stderr' => substr($stderr, 0, 500)
            ]);
        }

        if ($returnCode !== 0) {
            \Log::error('[ClaudeCodeService] Process failed with non-zero exit code', [
                'return_code' => $returnCode,
                'stderr' => $stderr
            ]);
            throw new ProcessFailedException($returnCode, $stderr);
        }
    }

    /**
     * Build environment variables for Claude CLI process.
     *
     * @param int $thinkingLevel Thinking mode level (0-4)
     * @return array|null Environment variables (null to inherit parent environment)
     */
    protected function buildEnvironment(int $thinkingLevel): ?array
    {
        // Get thinking mode configuration
        $thinkingModes = config('claude.thinking_modes', []);
        $thinkingConfig = $thinkingModes[$thinkingLevel] ?? $thinkingModes[0];

        // If thinking is disabled, don't pass custom environment
        // This allows inheriting the parent environment completely
        if ($thinkingConfig['tokens'] === 0) {
            return null;
        }

        // Build environment array for proc_open
        // We need to explicitly build this because we're adding MAX_THINKING_TOKENS
        $env = [];

        // Copy essential environment variables
        $essentialVars = ['PATH', 'HOME', 'USER', 'SHELL', 'TERM', 'LANG', 'LC_ALL'];
        foreach ($essentialVars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $env[$var] = $value;
            }
        }

        // Ensure HOME is set for Claude CLI credentials
        if (!isset($env['HOME'])) {
            $env['HOME'] = '/var/www';
        }

        // Set MAX_THINKING_TOKENS based on thinking level
        $env['MAX_THINKING_TOKENS'] = (string) $thinkingConfig['tokens'];

        return $env;
    }

    /**
     * Parse JSON output from Claude Code.
     *
     * @param string $output Raw output
     * @return array Parsed JSON
     * @throws JSONDecodeException
     */
    protected function parseJsonOutput(string $output): array
    {
        $decoded = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JSONDecodeException($output, json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Log a message if logging is enabled.
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (config('claude.logging.enabled')) {
            Log::channel(config('claude.logging.channel'))
                ->{$level}("[Claude Code] {$message}", $context);
        }
    }

    /**
     * Write interruption marker to session file.
     * This mimics what the native Claude CLI does when interrupted with Ctrl+C.
     *
     * @param string|null $sessionId The session UUID
     * @param string $cwd Working directory
     * @return void
     */
    public function writeInterruptionMarker(?string $sessionId, string $cwd): void
    {
        if (!$sessionId) {
            $this->log('debug', 'No session ID, skipping interruption marker');
            return;
        }

        try {
            // Build session file path
            // Claude CLI stores sessions in ~/.claude/projects/<encoded-dir>/<session-id>.jsonl
            $home = getenv('HOME') ?: '/var/www';
            $encodedDir = str_replace('/', '-', ltrim($cwd, '/'));
            // Root directory "/" becomes just "-"
            if ($encodedDir === '') {
                $encodedDir = '-';
            }
            $sessionFile = "{$home}/.claude/projects/{$encodedDir}/{$sessionId}.jsonl";

            if (!file_exists($sessionFile)) {
                $this->log('warning', 'Session file not found, cannot write interruption marker', [
                    'session_file' => $sessionFile
                ]);
                return;
            }

            // Check if we have write permissions (we may not if file is owned by different user)
            if (!is_writable($sessionFile)) {
                $this->log('info', 'Session file not writable, SIGINT should have handled marker', [
                    'session_file' => $sessionFile
                ]);
                return;
            }

            // Read last line to get parent UUID
            $lines = file($sessionFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (empty($lines)) {
                $this->log('warning', 'Session file is empty, cannot write interruption marker');
                return;
            }

            $lastLine = end($lines);
            $lastMessage = json_decode($lastLine, true);

            if (!$lastMessage || !isset($lastMessage['uuid'])) {
                $this->log('warning', 'Cannot parse last message to get parent UUID');
                return;
            }

            // Check if last message is already an interruption marker
            if (isset($lastMessage['message']['content'][0]['text']) &&
                $lastMessage['message']['content'][0]['text'] === '[Request interrupted by user]') {
                $this->log('debug', 'Interruption marker already exists');
                return;
            }

            // Construct interruption marker (matches native CLI format)
            $marker = [
                'parentUuid' => $lastMessage['uuid'],
                'isSidechain' => $lastMessage['isSidechain'] ?? false,
                'userType' => 'external',
                'cwd' => $cwd,
                'sessionId' => $sessionId,
                'version' => '2.0.20', // Match CLI version
                'gitBranch' => $lastMessage['gitBranch'] ?? '',
                'type' => 'user',
                'message' => [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => '[Request interrupted by user]'
                        ]
                    ]
                ],
                'uuid' => $this->generateUuid(),
                'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z')
            ];

            // Append to session file
            file_put_contents($sessionFile, json_encode($marker) . "\n", FILE_APPEND | LOCK_EX);

            $this->log('info', 'Wrote interruption marker to session file', [
                'session_id' => $sessionId,
                'session_file' => $sessionFile
            ]);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to write interruption marker', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate a UUID v4.
     *
     * @return string
     */
    protected function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Write PID file for a session.
     *
     * @param string $sessionId Claude session UUID
     * @param int $pid Process ID
     * @return void
     */
    protected function writePidFile(string $sessionId, int $pid): void
    {
        $pidFile = "/tmp/claude-session-{$sessionId}.pid";
        file_put_contents($pidFile, $pid);
    }

    /**
     * Read PID from file.
     *
     * @param string $sessionId Claude session UUID
     * @return int|null Process ID or null if file doesn't exist
     */
    public function readPidFile(string $sessionId): ?int
    {
        $pidFile = "/tmp/claude-session-{$sessionId}.pid";
        if (!file_exists($pidFile)) {
            return null;
        }

        $pid = (int) file_get_contents($pidFile);
        return $pid > 0 ? $pid : null;
    }

    /**
     * Remove PID file for a session.
     *
     * @param string $sessionId Claude session UUID
     * @return void
     */
    protected function removePidFile(string $sessionId): void
    {
        $pidFile = "/tmp/claude-session-{$sessionId}.pid";
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
    }

    /**
     * Kill a Claude CLI process by PID.
     *
     * @param int $pid Process ID
     * @return bool Whether the process was killed successfully
     */
    public function killProcess(int $pid): bool
    {
        // Check if process exists
        if (!posix_kill($pid, 0)) {
            $this->log('debug', 'Process does not exist', ['pid' => $pid]);
            return false;
        }

        // Send SIGTERM (signal 15)
        $this->log('info', 'Killing Claude process', ['pid' => $pid]);
        posix_kill($pid, 15);

        // Wait up to 2 seconds for process to die
        for ($i = 0; $i < 20; $i++) {
            usleep(100000); // 100ms
            if (!posix_kill($pid, 0)) {
                $this->log('info', 'Process terminated successfully', ['pid' => $pid]);
                return true;
            }
        }

        // Force kill if still running (SIGKILL = signal 9)
        $this->log('warning', 'Process still running, sending SIGKILL', ['pid' => $pid]);
        posix_kill($pid, 9);
        usleep(100000); // Wait 100ms

        return true;
    }

    /**
     * Check if a process is still alive without killing it.
     *
     * @param int $pid Process ID to check
     * @return bool True if process is alive, false otherwise
     */
    public function isProcessAlive(int $pid): bool
    {
        // First check if PID exists
        if (!posix_kill($pid, 0)) {
            return false;
        }

        // PID exists, but check if it's a zombie process
        // Zombies still have PIDs but are not actually running
        $statusFile = "/proc/{$pid}/status";
        if (file_exists($statusFile)) {
            $status = @file_get_contents($statusFile);
            if ($status !== false) {
                // Look for "State: Z (zombie)" line
                if (preg_match('/State:\s+Z\s+\(zombie\)/i', $status)) {
                    \Log::debug("[ClaudeCodeService] Process is zombie", [
                        'pid' => $pid,
                        'state' => 'zombie'
                    ]);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Start Claude CLI process in background (detached mode).
     *
     * @param string $prompt The prompt to send
     * @param array $options Execution options
     * @param string|null $sessionId Claude session UUID
     * @param bool $isFirstMessage First message flag
     * @param int $thinkingLevel Thinking mode level
     * @return int Process PID
     * @throws ClaudeCodeException
     */
    public function startBackgroundProcess(string $prompt, array $options, ?string $sessionId, bool $isFirstMessage, int $thinkingLevel): int
    {
        $stdoutFile = "/tmp/claude-{$sessionId}-stdout.jsonl";
        $stderrFile = "/tmp/claude-{$sessionId}-stderr.log";

        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["file", $stdoutFile, "w"],  // stdout to file for streaming
            2 => ["file", $stderrFile, "w"]   // stderr to log
        ];

        $command = "{$this->cliPath} --print --output-format stream-json --verbose --include-partial-messages";

        // Add session management flags
        if ($sessionId) {
            if ($isFirstMessage) {
                $command .= " --session-id {$sessionId}";
            } else {
                $command .= " --resume {$sessionId}";
            }
        }

        // Add option flags
        $command .= $this->buildCommandFlags($options);

        $cwd = $options['cwd'] ?? config('claude.working_directory');

        // Build environment variables
        $env = $this->buildEnvironment($thinkingLevel);

        $this->log('debug', 'Starting background Claude process', [
            'command' => $command,
            'cwd' => $cwd,
            'sessionId' => $sessionId,
        ]);

        // Start process in background
        $process = proc_open($command, $descriptorspec, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            throw new ClaudeCodeException('Failed to start Claude Code background process');
        }

        // Write prompt to stdin
        fwrite($pipes[0], $prompt);
        fclose($pipes[0]);

        // Get PID
        $status = proc_get_status($process);
        $pid = $status['pid'];

        $this->writePidFile($sessionId, $pid);
        $this->log('info', 'Background process started', ['pid' => $pid, 'sessionId' => $sessionId]);

        // Don't close the process handle - let it run in background
        // proc_close() would wait for process to finish

        return $pid;
    }

    /**
     * Get path to session's .jsonl file.
     *
     * @param string $sessionId Claude session UUID
     * @param string $cwd Working directory
     * @return string Path to .jsonl file
     */
    public function getJsonlPath(string $sessionId, string $cwd): string
    {
        $home = getenv('HOME') ?: '/home/appuser';
        $encodedDir = str_replace('/', '-', ltrim($cwd, '/'));
        if ($encodedDir === '') {
            $encodedDir = '-';
        }
        return "{$home}/.claude/projects/{$encodedDir}/{$sessionId}.jsonl";
    }

    /**
     * Get path to stdout streaming file.
     *
     * @param string $sessionId Claude session UUID
     * @return string Path to stdout file
     */
    public function getStdoutPath(string $sessionId): string
    {
        return "/tmp/claude-{$sessionId}-stdout.jsonl";
    }

    /**
     * Tail .jsonl file from a given message index.
     *
     * @param string $jsonlPath Path to .jsonl file
     * @param int $fromIndex Start reading from this message index
     * @return array New messages since fromIndex
     */
    public function tailJsonlFile(string $jsonlPath, int $fromIndex): array
    {
        if (!file_exists($jsonlPath)) {
            return [];
        }

        $lines = file($jsonlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        // Get only new messages (from index onwards)
        $newLines = array_slice($lines, $fromIndex);
        $messages = [];

        foreach ($newLines as $line) {
            $message = json_decode($line, true);
            if ($message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Count total messages in .jsonl file.
     *
     * @param string $jsonlPath Path to .jsonl file
     * @return int Total message count
     */
    public function countJsonlMessages(string $jsonlPath): int
    {
        if (!file_exists($jsonlPath)) {
            return 0;
        }

        $lines = file($jsonlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines ? count($lines) : 0;
    }

    /**
     * Get the CLI version for debugging.
     *
     * @return string
     */
    public function getVersion(): string
    {
        exec("{$this->cliPath} --version 2>&1", $output);
        return implode("\n", $output);
    }

    /**
     * Check if the CLI is available and working.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $this->verifyCLIExists();
            return true;
        } catch (CLINotFoundException) {
            return false;
        }
    }
}
