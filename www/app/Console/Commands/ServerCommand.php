<?php

namespace App\Console\Commands;

use App\Models\ServerConnection;
use App\Models\Workspace;
use App\Support\SshConnection;
use Illuminate\Console\Command;

class ServerCommand extends Command
{
    protected $signature = 'server
        {action : The action to perform (list, add, test, detect, install-vps-setup, install-proxy, remove, ssh-keygen, show-public-key)}
        {--workspace= : Workspace ID (required for most actions)}
        {--id= : Server ID (for test, detect, install-*, remove)}
        {--name= : Server name (for add)}
        {--host= : Server IP or hostname (for add)}
        {--ssh_user=admin : SSH username (for add)}
        {--ssh_port=22 : SSH port (for add)}
        {--notes= : Notes (for add)}
        {--mode=public : VPS setup mode: public or private (for install-vps-setup)}';

    protected $description = 'Manage server connections';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listServers(),
            'add' => $this->addServer(),
            'test' => $this->testConnection(),
            'detect' => $this->detectSoftware(),
            'install-vps-setup' => $this->installVpsSetup(),
            'install-proxy' => $this->installProxyNginx(),
            'remove' => $this->removeServer(),
            'ssh-keygen' => $this->generateSshKey(),
            'show-public-key' => $this->showPublicKey(),
            default => $this->invalidAction($action),
        };
    }

    private function listServers(): int
    {
        $workspaceId = $this->resolveWorkspaceId($this->option('workspace'));
        if (!$workspaceId) {
            return $this->errorResponse('--workspace is required (UUID or name like "default")');
        }

        $servers = ServerConnection::forWorkspace($workspaceId)
            ->orderBy('name')
            ->get();

        if ($servers->isEmpty()) {
            $this->outputJson([
                'output' => 'No servers configured for this workspace.',
                'servers' => [],
                'count' => 0,
            ]);
            return Command::SUCCESS;
        }

        $serverList = $servers->map(fn($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'host' => $s->host,
            'ssh_user' => $s->ssh_user,
            'ssh_port' => $s->ssh_port,
            'status' => $s->status,
            'status_label' => $s->status_label,
            'has_vps_setup' => $s->has_vps_setup,
            'vps_setup_mode' => $s->vps_setup_mode,
            'has_proxy_nginx' => $s->has_proxy_nginx,
            'proxy_nginx_version' => $s->proxy_nginx_version,
            'last_checked_at' => $s->last_checked_at?->toIso8601String(),
            'last_connection_error' => $s->last_connection_error,
        ])->toArray();

        $this->outputJson([
            'output' => 'Found ' . count($serverList) . ' server(s).',
            'servers' => $serverList,
            'count' => count($serverList),
        ]);

        return Command::SUCCESS;
    }

    private function addServer(): int
    {
        $workspaceId = $this->resolveWorkspaceId($this->option('workspace'));
        $name = $this->option('name');
        $host = $this->option('host');

        if (!$workspaceId) {
            return $this->errorResponse('--workspace is required (UUID or name like "default")');
        }
        if (!$name) {
            return $this->errorResponse('--name is required');
        }
        if (!$host) {
            return $this->errorResponse('--host is required');
        }

        // Check workspace exists
        if (!Workspace::find($workspaceId)) {
            return $this->errorResponse('Workspace not found');
        }

        // Check if host already exists in this workspace
        if (ServerConnection::findByHost($host, $workspaceId)) {
            return $this->errorResponse("Server with host '{$host}' already exists in this workspace");
        }

        $server = ServerConnection::create([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'host' => $host,
            'ssh_user' => $this->option('ssh_user'),
            'ssh_port' => (int) $this->option('ssh_port'),
            'notes' => $this->option('notes'),
        ]);

        $this->outputJson([
            'output' => "Server '{$name}' added. Run 'server test --id={$server->id}' to verify connection.",
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'ssh_connection' => $server->ssh_connection_string,
            ],
        ]);

        return Command::SUCCESS;
    }

    private function testConnection(): int
    {
        $server = $this->getServer();
        if (!$server) {
            return Command::FAILURE;
        }

        try {
            $ssh = $this->getSshConnection($server);
            $success = $ssh->test();

            if ($success) {
                $server->markConnectionSuccess();
                $this->outputJson([
                    'output' => "Connection to '{$server->name}' ({$server->host}) successful.",
                    'success' => true,
                ]);
            } else {
                $server->markConnectionFailed('Connection test failed');
                $this->outputJson([
                    'output' => "Connection to '{$server->name}' ({$server->host}) failed.",
                    'success' => false,
                ]);
            }
        } catch (\Exception $e) {
            $server->markConnectionFailed($e->getMessage());
            return $this->errorResponse("Connection failed: " . $e->getMessage());
        }

        return Command::SUCCESS;
    }

    private function detectSoftware(): int
    {
        $server = $this->getServer();
        if (!$server) {
            return Command::FAILURE;
        }

        try {
            $ssh = $this->getSshConnection($server);

            // Test connection first
            if (!$ssh->test()) {
                $server->markConnectionFailed('Connection failed');
                return $this->errorResponse('Cannot connect to server');
            }

            $server->markConnectionSuccess();

            $detected = [
                'has_vps_setup' => false,
                'vps_setup_mode' => null,
                'has_proxy_nginx' => false,
                'proxy_nginx_version' => null,
                'has_tailscale' => false,
                'tailscale_ip' => null,
            ];

            // Check VPS setup
            $vpsMode = $ssh->exec('cat /etc/vps-setup-mode 2>/dev/null');
            if ($vpsMode && in_array(trim($vpsMode), ['public', 'private'])) {
                $detected['has_vps_setup'] = true;
                $detected['vps_setup_mode'] = trim($vpsMode);
            }

            // Check proxy-nginx
            $proxyRunning = $ssh->exec('docker ps --format "{{.Names}}" | grep -q "^proxy-nginx$" && echo "yes" || echo "no"');
            if (trim($proxyRunning) === 'yes') {
                $detected['has_proxy_nginx'] = true;

                // Try to get version from compose.yml
                $version = $ssh->exec('grep -oP "ghcr.io/tetrixdev/proxy-nginx:\K[0-9.]+" ~/docker-apps/proxy-nginx/compose.yml 2>/dev/null || echo ""');
                if ($version && trim($version)) {
                    $detected['proxy_nginx_version'] = trim($version);
                }
            }

            // Check Tailscale
            $tailscaleStatus = $ssh->exec('tailscale ip -4 2>/dev/null || echo ""');
            if ($tailscaleStatus && trim($tailscaleStatus)) {
                $detected['has_tailscale'] = true;
                $detected['tailscale_ip'] = trim($tailscaleStatus);
            }

            $server->updateDetectedStatus($detected);

            $this->outputJson([
                'output' => "Detection complete for '{$server->name}'.",
                'detected' => $detected,
                'server' => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'status' => $server->fresh()->status,
                ],
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->errorResponse("Detection failed: " . $e->getMessage());
        }
    }

    private function installVpsSetup(): int
    {
        $server = $this->getServer();
        if (!$server) {
            return Command::FAILURE;
        }

        $mode = $this->option('mode');
        if (!in_array($mode, ['public', 'private'])) {
            return $this->errorResponse("Invalid mode '{$mode}'. Use 'public' or 'private'.");
        }

        try {
            $ssh = $this->getSshConnection($server);

            if (!$ssh->test()) {
                return $this->errorResponse('Cannot connect to server');
            }

            // Download and run vps-setup
            $command = "curl -fsSL https://raw.githubusercontent.com/tetrixdev/vps-setup/main/setup.sh -o /tmp/vps-setup.sh && chmod +x /tmp/vps-setup.sh && sudo /tmp/vps-setup.sh --mode={$mode}";

            $this->outputJson([
                'output' => "Installing VPS setup (mode: {$mode}) on '{$server->name}'...\nThis may take a few minutes.",
                'status' => 'running',
                'command' => $command,
            ]);

            // Run with extended timeout (10 minutes)
            $result = $ssh->run($command, 600);

            if ($result->successful()) {
                // Re-detect after install
                $server->update([
                    'has_vps_setup' => true,
                    'vps_setup_mode' => $mode,
                    'last_checked_at' => now(),
                ]);

                $this->outputJson([
                    'output' => "VPS setup completed successfully on '{$server->name}'.",
                    'success' => true,
                    'install_output' => $result->output(),
                ]);
            } else {
                $this->outputJson([
                    'output' => "VPS setup failed on '{$server->name}'.",
                    'success' => false,
                    'error' => $result->errorOutput(),
                ]);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->errorResponse("Installation failed: " . $e->getMessage());
        }
    }

    private function installProxyNginx(): int
    {
        $server = $this->getServer();
        if (!$server) {
            return Command::FAILURE;
        }

        try {
            $ssh = $this->getSshConnection($server);

            if (!$ssh->test()) {
                return $this->errorResponse('Cannot connect to server');
            }

            // Run one-line installer
            $command = 'curl -fsSL https://raw.githubusercontent.com/tetrixdev/proxy-nginx/main/install.sh | bash';

            $this->outputJson([
                'output' => "Installing proxy-nginx on '{$server->name}'...",
                'status' => 'running',
            ]);

            // Run with extended timeout (5 minutes)
            $result = $ssh->run($command, 300);

            if ($result->successful()) {
                // Re-detect after install
                $versionOutput = $ssh->exec('grep -oP "ghcr.io/tetrixdev/proxy-nginx:\K[0-9.]+" ~/docker-apps/proxy-nginx/compose.yml 2>/dev/null || echo ""');

                $server->update([
                    'has_proxy_nginx' => true,
                    'proxy_nginx_version' => trim($versionOutput) ?: null,
                    'last_checked_at' => now(),
                ]);

                $this->outputJson([
                    'output' => "Proxy-nginx installed successfully on '{$server->name}'.",
                    'success' => true,
                    'install_output' => $result->output(),
                ]);
            } else {
                $this->outputJson([
                    'output' => "Proxy-nginx installation failed on '{$server->name}'.",
                    'success' => false,
                    'error' => $result->errorOutput(),
                ]);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->errorResponse("Installation failed: " . $e->getMessage());
        }
    }

    private function removeServer(): int
    {
        $server = $this->getServer();
        if (!$server) {
            return Command::FAILURE;
        }

        $appCount = $server->applications()->count();
        if ($appCount > 0) {
            return $this->errorResponse("Cannot remove server '{$server->name}': {$appCount} application(s) are deployed. Remove applications first.");
        }

        $serverName = $server->name;
        $server->delete();

        $this->outputJson([
            'output' => "Server '{$serverName}' removed.",
            'deleted' => true,
        ]);

        return Command::SUCCESS;
    }

    private function generateSshKey(): int
    {
        $workspaceId = $this->resolveWorkspaceId($this->option('workspace'));
        if (!$workspaceId) {
            return $this->errorResponse('--workspace is required (UUID or name like "default")');
        }

        $workspace = Workspace::find($workspaceId);
        if (!$workspace) {
            return $this->errorResponse('Workspace not found');
        }

        $keyDir = $this->getWorkspaceSshDir($workspaceId);
        $keyPath = "{$keyDir}/id_ed25519";
        $pubKeyPath = "{$keyPath}.pub";

        // Check if key already exists
        if (file_exists($keyPath)) {
            $publicKey = file_get_contents($pubKeyPath);
            $this->outputJson([
                'output' => 'SSH keypair already exists for this workspace.',
                'exists' => true,
                'public_key' => trim($publicKey),
                'key_path' => $keyPath,
            ]);
            return Command::SUCCESS;
        }

        // Create directory structure with www-data ownership
        // This ensures the web server can access keys for panel actions
        $this->ensureSshDirectory($keyDir);

        // Generate key
        $comment = "pocketdev-{$workspace->slug}";
        $result = \Illuminate\Support\Facades\Process::run(
            "ssh-keygen -t ed25519 -f " . escapeshellarg($keyPath) . " -N '' -C " . escapeshellarg($comment)
        );

        if ($result->failed()) {
            return $this->errorResponse('Failed to generate SSH key: ' . $result->errorOutput());
        }

        // Set permissions and ownership (TARGET_UID:www-data pattern)
        // Private key: 640 allows group (www-data) to read, which is needed for PHP-FPM
        // SSH only requires that "others" cannot read (last digit must be 0)
        chmod($keyPath, 0640);
        chmod($pubKeyPath, 0644);
        $this->ensureWwwDataAccess($keyPath);
        $this->ensureWwwDataAccess($pubKeyPath);

        $publicKey = file_get_contents($pubKeyPath);

        $this->outputJson([
            'output' => 'SSH keypair generated successfully.',
            'created' => true,
            'public_key' => trim($publicKey),
            'key_path' => $keyPath,
            'instructions' => 'Add this public key to your servers: ~/.ssh/authorized_keys',
        ]);

        return Command::SUCCESS;
    }

    private function showPublicKey(): int
    {
        $workspaceId = $this->resolveWorkspaceId($this->option('workspace'));
        if (!$workspaceId) {
            return $this->errorResponse('--workspace is required (UUID or name like "default")');
        }

        $keyDir = $this->getWorkspaceSshDir($workspaceId);
        $pubKeyPath = "{$keyDir}/id_ed25519.pub";

        if (!file_exists($pubKeyPath)) {
            $this->outputJson([
                'output' => 'No SSH key found for this workspace. Run ssh-keygen first.',
                'exists' => false,
            ]);
            return Command::SUCCESS;
        }

        $publicKey = file_get_contents($pubKeyPath);

        $this->outputJson([
            'output' => 'SSH public key for this workspace:',
            'exists' => true,
            'public_key' => trim($publicKey),
        ]);

        return Command::SUCCESS;
    }

    // Helper methods

    private function getServer(): ?ServerConnection
    {
        $id = $this->option('id');
        if (!$id) {
            $this->errorResponse('--id is required');
            return null;
        }

        $server = ServerConnection::find($id);
        if (!$server) {
            $this->errorResponse('Server not found');
            return null;
        }

        return $server;
    }

    private function getSshConnection(ServerConnection $server): SshConnection
    {
        $keyPath = $this->getWorkspaceSshDir($server->workspace_id) . '/id_ed25519';

        return new SshConnection([
            'ssh_host' => $server->host,
            'ssh_user' => $server->ssh_user,
            'ssh_port' => $server->ssh_port,
            'ssh_key_path' => file_exists($keyPath) ? $keyPath : null,
            'server_name' => $server->name,
        ]);
    }

    private function getWorkspaceSshDir(string $workspaceId): string
    {
        return "/var/www/.pocketdev/ssh/{$workspaceId}";
    }

    /**
     * Ensure SSH directory exists with correct ownership for www-data.
     * Creates the full path: /var/www/.pocketdev/ssh/{workspaceId}
     *
     * Also fixes permissions on existing directories to ensure web server access.
     */
    private function ensureSshDirectory(string $keyDir): void
    {
        $baseDir = '/var/www/.pocketdev';
        $sshDir = "{$baseDir}/ssh";

        // Create and fix base .pocketdev directory
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0700, true);
        }
        $this->ensureWwwDataAccess($baseDir);

        // Create and fix ssh subdirectory
        if (!is_dir($sshDir)) {
            mkdir($sshDir, 0700);
        }
        $this->ensureWwwDataAccess($sshDir);

        // Create and fix workspace-specific directory
        if (!is_dir($keyDir)) {
            mkdir($keyDir, 0700);
        }
        $this->ensureWwwDataAccess($keyDir);
    }

    /**
     * Ensure both appuser and www-data can access a file/directory.
     *
     * Uses the PocketDev cross-group ownership model:
     * - Owner: TARGET_UID (appuser in dev, can vary in prod)
     * - Group: 33 (www-data) - allows PHP-FPM access
     * - Directories: 750 (owner rwx, group rx)
     * - Files: preserves existing mode (set before calling this)
     *
     * This pattern matches .claude directory permissions and works
     * across local dev and production environments.
     */
    private function ensureWwwDataAccess(string $path): void
    {
        // Get target UID from environment (set by entrypoint) or fallback to current user
        $targetUid = (int) ($_ENV['PD_USER_ID'] ?? $_ENV['PD_TARGET_UID'] ?? getmyuid());
        $wwwDataGid = 33; // www-data group ID (standard on Debian/Ubuntu)

        @chown($path, $targetUid);
        @chgrp($path, $wwwDataGid);

        // Only set directory permissions - file permissions are set by caller
        if (is_dir($path)) {
            @chmod($path, 0750);
        }
    }

    /**
     * Resolve workspace option to UUID.
     *
     * Accepts either a UUID or a workspace name (case-insensitive).
     */
    private function resolveWorkspaceId(?string $input): ?string
    {
        if (!$input) {
            return null;
        }

        // Check if it's already a valid UUID format
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input)) {
            return $input;
        }

        // Try to find workspace by name (case-insensitive)
        $workspace = Workspace::whereRaw('LOWER(name) = ?', [strtolower($input)])->first();
        return $workspace?->id;
    }

    private function invalidAction(string $action): int
    {
        return $this->errorResponse("Invalid action '{$action}'. Valid: list, add, test, detect, install-vps-setup, install-proxy, remove, ssh-keygen, show-public-key");
    }

    private function errorResponse(string $message): int
    {
        $this->outputJson([
            'output' => $message,
            'is_error' => true,
        ]);
        return Command::FAILURE;
    }

    private function outputJson(array $data): void
    {
        $data['is_error'] = $data['is_error'] ?? false;
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
