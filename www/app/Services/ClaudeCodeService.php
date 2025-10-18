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

        $input = $this->prepareInput($prompt, $options);
        $result = $this->execute($input, $options['cwd'] ?? null);

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

        $input = $this->prepareInput($prompt, $options);
        $this->executeStreaming($input, $callback, $options['cwd'] ?? null);

        $this->log('info', 'Claude Code streaming query completed');
    }

    /**
     * Prepare the input JSON for Claude Code CLI.
     *
     * @param string $prompt
     * @param array $options
     * @return string JSON-encoded input
     */
    protected function prepareInput(string $prompt, array $options): string
    {
        $mergedOptions = array_merge($this->defaultOptions, $options);

        $input = [
            'prompt' => $prompt,
            'options' => array_filter($mergedOptions, fn($value) => $value !== null)
        ];

        return json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Execute Claude Code CLI synchronously.
     *
     * @param string $input JSON input
     * @param string|null $cwd Working directory
     * @return array Parsed JSON response
     * @throws ClaudeCodeException
     */
    protected function execute(string $input, ?string $cwd = null): array
    {
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $command = "{$this->cliPath} --print --output-format json";
        $cwd = $cwd ?? config('claude.working_directory');

        $this->log('debug', 'Executing Claude Code', [
            'command' => $command,
            'cwd' => $cwd
        ]);

        $process = proc_open($command, $descriptorspec, $pipes, $cwd);

        if (!is_resource($process)) {
            throw new ClaudeCodeException('Failed to start Claude Code process');
        }

        // Write input
        fwrite($pipes[0], $input);
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
     * @param string $input JSON input
     * @param callable $callback Callback to handle each chunk
     * @param string|null $cwd Working directory
     * @return void
     * @throws ClaudeCodeException
     */
    protected function executeStreaming(string $input, callable $callback, ?string $cwd = null): void
    {
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $command = "{$this->cliPath} --print --output-format stream-json";
        $cwd = $cwd ?? config('claude.working_directory');

        $this->log('debug', 'Executing Claude Code (streaming)', [
            'command' => $command,
            'cwd' => $cwd
        ]);

        $process = proc_open($command, $descriptorspec, $pipes, $cwd);

        if (!is_resource($process)) {
            throw new ClaudeCodeException('Failed to start Claude Code process');
        }

        // Write input
        fwrite($pipes[0], $input);
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

        if ($returnCode !== 0) {
            throw new ProcessFailedException($returnCode, $stderr);
        }
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
