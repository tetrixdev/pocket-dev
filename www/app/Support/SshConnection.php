<?php

namespace App\Support;

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Stateless SSH command execution utility.
 *
 * Each run() call builds and executes a fresh SSH command via Laravel's Process facade.
 * Supports password auth (via sshpass -e) and key-based auth (default ~/.ssh/ keys).
 */
class SshConnection
{
    public readonly string $host;
    public readonly string $user;
    public readonly int $port;
    protected ?string $password;
    protected ?string $keyPath;
    public readonly ?string $serverName;

    public function __construct(array $config)
    {
        if (empty($config['ssh_host'])) {
            throw new \InvalidArgumentException('ssh_host is required');
        }

        $this->host = $config['ssh_host'];
        $this->user = $config['ssh_user'] ?? 'root';
        $this->port = (int) ($config['ssh_port'] ?? 22);
        $this->password = !empty($config['ssh_password']) ? $config['ssh_password'] : null;
        $this->keyPath = !empty($config['ssh_key_path']) ? $config['ssh_key_path'] : null;

        if ($this->keyPath !== null && !is_file($this->keyPath)) {
            throw new \InvalidArgumentException("SSH key file not found: {$this->keyPath}");
        }

        $this->serverName = !empty($config['server_name']) ? $config['server_name'] : null;
    }

    /**
     * Factory: create from panel params if SSH is configured, null otherwise.
     */
    public static function fromPanelParams(array $panelParams): ?self
    {
        if (empty($panelParams['ssh_host'])) {
            return null;
        }

        return new self($panelParams);
    }

    /**
     * Execute a command on the remote host.
     */
    public function run(string $command, int $timeout = 30, ?string $workingDir = null): ProcessResult
    {
        $sshCmd = $this->buildSshCommand($command, $workingDir);

        if ($this->password) {
            return Process::env(['SSHPASS' => $this->password])
                ->timeout($timeout)
                ->run($sshCmd);
        }

        return Process::timeout($timeout)->run($sshCmd);
    }

    /**
     * Execute a command and return stdout, or null on failure.
     */
    public function exec(string $command, int $timeout = 30, ?string $workingDir = null): ?string
    {
        $result = $this->run($command, $timeout, $workingDir);

        if ($result->failed()) {
            return null;
        }

        return $result->output();
    }

    /**
     * Execute a command and return stdout even if the command fails.
     *
     * Useful for commands that may produce partial output before timeout/failure
     * (e.g., `timeout 5 du -sbx ...` may output some lines before being killed).
     */
    public function execPartial(string $command, int $timeout = 30, ?string $workingDir = null): ?string
    {
        $result = $this->run($command, $timeout, $workingDir);
        $output = $result->output();

        return ($output !== null && trim($output) !== '') ? $output : null;
    }

    /**
     * Check if the remote host is reachable (quick connectivity test).
     */
    public function test(): bool
    {
        $result = $this->run('echo ok', 10);

        return $result->successful() && str_contains($result->output(), 'ok');
    }

    /**
     * Get display label for UI (e.g. "user@host [My Server]").
     */
    public function getLabel(): string
    {
        $label = "{$this->user}@{$this->host}";

        if ($this->port !== 22) {
            $label .= ":{$this->port}";
        }

        if ($this->serverName) {
            $label .= " [{$this->serverName}]";
        }

        return $label;
    }

    /**
     * Build the full SSH command string.
     *
     * Uses sshpass -e (env var mode) for password auth to avoid exposing
     * the password in /proc. For key-based auth, uses -i if a key path
     * is provided, otherwise lets SSH try default keys from ~/.ssh/.
     */
    protected function buildSshCommand(string $remoteCommand, ?string $workingDir = null): string
    {
        // If a working directory is specified, prepend cd
        if ($workingDir !== null) {
            $escapedDir = escapeshellarg($workingDir);
            $remoteCommand = "cd {$escapedDir} && {$remoteCommand}";
        }

        // Security note: Host key verification is intentionally disabled.
        // This tool runs in an ephemeral container environment where managing
        // known_hosts is impractical. The SSH credentials themselves (password
        // or key) already authenticate the connection. MITM risk is accepted
        // as a trade-off for automation usability.
        $sshArgs = [
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=10',
            '-o', 'LogLevel=ERROR',
            '-p', (string) $this->port,
        ];

        // Key-based auth
        if ($this->keyPath) {
            $sshArgs[] = '-i';
            $sshArgs[] = $this->keyPath;
        }

        // Disable batch mode when using password (sshpass needs interactive-ish mode)
        if (!$this->password) {
            $sshArgs[] = '-o';
            $sshArgs[] = 'BatchMode=yes';
        }

        $sshArgsStr = implode(' ', array_map('escapeshellarg', $sshArgs));
        $target = escapeshellarg("{$this->user}@{$this->host}");
        $escapedRemoteCmd = escapeshellarg($remoteCommand);

        // Password auth via sshpass -e (reads from SSHPASS env var)
        if ($this->password) {
            return "sshpass -e ssh {$sshArgsStr} {$target} {$escapedRemoteCmd}";
        }

        // Key or default key auth
        return "ssh {$sshArgsStr} {$target} {$escapedRemoteCmd}";
    }
}
