<?php

namespace App\Panels;

use App\Models\ServerConnection;

class ServerManagerPanel extends Panel
{
    public string $slug = 'server-manager';
    public string $name = 'Server Manager';
    public string $description = 'Manage servers and deployed applications';
    public string $icon = 'fa-solid fa-server';
    public string $category = 'servers';

    public array $parameters = [
        'workspace_id' => [
            'type' => 'string',
            'description' => 'Workspace ID for server isolation',
            'required' => true,
        ],
    ];

    public function render(array $params, array $state, ?string $panelStateId = null): string
    {
        return view('panels.server-manager', [
            'parameters' => $params,
            'state' => $state,
            'panelStateId' => $panelStateId,
        ])->render();
    }

    public function peek(array $params, array $state): string
    {
        $workspaceId = $params['workspace_id'] ?? null;
        if (!$workspaceId) {
            return "Server Manager: No workspace selected.";
        }

        $servers = ServerConnection::where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->get();

        if ($servers->isEmpty()) {
            return "Server Manager: No servers configured.";
        }

        $serverData = [];
        foreach ($servers as $server) {
            $serverInfo = [
                'id' => $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'ssh_user' => $server->ssh_user,
                'ssh_port' => $server->ssh_port,
                'status' => $server->status,
                'status_label' => $server->status_label,
                'has_vps_setup' => $server->has_vps_setup,
                'vps_setup_mode' => $server->vps_setup_mode,
                'has_proxy_nginx' => $server->has_proxy_nginx,
                'proxy_nginx_version' => $server->proxy_nginx_version,
                'last_checked_at' => $server->last_checked_at?->toIso8601String(),
                'last_connection_error' => $server->last_connection_error,
            ];
            $serverData[] = $serverInfo;
        }

        return json_encode([
            'data' => [
                'output' => "Found {$servers->count()} server(s).",
                'servers' => $serverData,
                'count' => $servers->count(),
                'is_error' => false,
            ],
        ], JSON_PRETTY_PRINT);
    }

    public function handleAction(string $action, array $params, array $state, array $panelParams = []): array
    {
        $workspaceId = $params['workspace_id'] ?? $panelParams['workspace_id'] ?? null;

        return match ($action) {
            'listServers' => $this->listServers($workspaceId),
            'addServer' => $this->addServer($workspaceId, $params),
            'testServer' => $this->testServer($params['server_id'] ?? null),
            'detectServer' => $this->detectServer($params['server_id'] ?? null),
            'removeServer' => $this->removeServer($params['server_id'] ?? null),
            'installVpsSetup' => $this->installVpsSetup($params['server_id'] ?? null, $params['mode'] ?? 'public'),
            'installProxy' => $this->installProxy($params['server_id'] ?? null),
            'generateSshKey' => $this->generateSshKey($workspaceId),
            'showPublicKey' => $this->showPublicKey($workspaceId),
            'listApps' => $this->listApps($params['server_id'] ?? null),
            'appStart' => $this->controlApp($params['app_id'] ?? null, 'start'),
            'appStop' => $this->controlApp($params['app_id'] ?? null, 'stop'),
            'appRestart' => $this->controlApp($params['app_id'] ?? null, 'restart'),
            'appLogs' => $this->appLogs($params['app_id'] ?? null, $params['lines'] ?? 100),
            'scanGitHubApps' => $this->scanGitHubApps(),
            'getDeployConfig' => $this->getDeployConfig($params['owner'] ?? null, $params['repo'] ?? null),
            'deployApp' => $this->deployApp($workspaceId, $params),
            'addDomain' => $this->addDomain($params),
            'requestSsl' => $this->requestSsl($params),
            default => parent::handleAction($action, $params, $state, $panelParams),
        };
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Opens an interactive Server Manager panel for managing server connections and deployed applications.

## When to Use
- When managing VPS servers and deployed applications
- When setting up new servers with VPS setup script or proxy-nginx
- When viewing or controlling Docker applications on servers

## Parameters
- workspace_id (required): The workspace ID for server isolation

## Features
- Add/remove server connections
- Test SSH connectivity
- Detect installed software (VPS setup, proxy-nginx, Tailscale)
- Install VPS setup script (public or private mode)
- Install proxy-nginx
- View deployed applications per server
- Start/stop/restart applications
- View application logs
- SSH key management per workspace

## Deployment

**For deployment workflows**, retrieve and use the `/deploy` skill:
```bash
pd memory:query --schema=<your-schema> --sql="SELECT instructions FROM memory_<your-schema>.skills WHERE name = 'deploy'"
```

The deploy skill contains the complete, up-to-date deployment workflow including:
- Step-by-step deployment process
- Version checking and update handling
- Environment variable best practices
- Prerequisites checklist

## CLI Example
```bash
pd tool:run server-manager -- --workspace_id=your-workspace-uuid
```

Use `pd panel:peek server-manager` to see current state after user interacts.
PROMPT;
    }

    // ========================================================================
    // Action Handlers
    // ========================================================================

    protected function listServers(?string $workspaceId): array
    {
        if (!$workspaceId) {
            return ['error' => 'workspace_id is required'];
        }

        return $this->runArtisan("server list --workspace={$workspaceId}");
    }

    protected function addServer(?string $workspaceId, array $params): array
    {
        $name = $params['name'] ?? '';
        $host = $params['host'] ?? '';
        $sshUser = $params['ssh_user'] ?? 'admin';
        $sshPort = $params['ssh_port'] ?? 22;

        if (!$workspaceId || !$name || !$host) {
            return ['error' => 'workspace_id, name, and host are required'];
        }

        $cmd = "server add --workspace={$workspaceId} --name=" . escapeshellarg($name)
            . " --host=" . escapeshellarg($host)
            . " --ssh_user=" . escapeshellarg($sshUser)
            . " --ssh_port={$sshPort}";

        return $this->runArtisan($cmd);
    }

    protected function testServer(?string $serverId): array
    {
        if (!$serverId) {
            return ['error' => 'server_id is required'];
        }

        return $this->runArtisan("server test --id={$serverId}");
    }

    protected function detectServer(?string $serverId): array
    {
        if (!$serverId) {
            return ['error' => 'server_id is required'];
        }

        return $this->runArtisan("server detect --id={$serverId}");
    }

    protected function removeServer(?string $serverId): array
    {
        if (!$serverId) {
            return ['error' => 'server_id is required'];
        }

        return $this->runArtisan("server remove --id={$serverId}");
    }

    protected function installVpsSetup(?string $serverId, string $mode): array
    {
        if (!$serverId) {
            return ['error' => 'server_id is required'];
        }

        return $this->runArtisan("server install-vps-setup --id={$serverId} --mode={$mode}");
    }

    protected function installProxy(?string $serverId): array
    {
        if (!$serverId) {
            return ['error' => 'server_id is required'];
        }

        return $this->runArtisan("server install-proxy --id={$serverId}");
    }

    protected function generateSshKey(?string $workspaceId): array
    {
        if (!$workspaceId) {
            return ['error' => 'workspace_id is required'];
        }

        return $this->runArtisan("server ssh-keygen --workspace={$workspaceId}");
    }

    protected function showPublicKey(?string $workspaceId): array
    {
        if (!$workspaceId) {
            return ['error' => 'workspace_id is required'];
        }

        return $this->runArtisan("server show-public-key --workspace={$workspaceId}");
    }

    protected function listApps(?string $serverId): array
    {
        if (!$serverId) {
            return ['error' => 'server_id is required'];
        }

        return $this->runArtisan("server:app list --server={$serverId}");
    }

    protected function controlApp(?string $appId, string $action): array
    {
        if (!$appId) {
            return ['error' => 'app_id is required'];
        }

        return $this->runArtisan("server:app {$action} --id={$appId}");
    }

    protected function appLogs(?string $appId, int $lines): array
    {
        if (!$appId) {
            return ['error' => 'app_id is required'];
        }

        return $this->runArtisan("server:app logs --id={$appId} --lines={$lines}");
    }

    protected function scanGitHubApps(): array
    {
        // Delegate to artisan command - keeps logic in one place for AI and panel
        return $this->runArtisan("server:app scan-repos");
    }

    protected function getDeployConfig(?string $owner, ?string $repo): array
    {
        if (!$owner || !$repo) {
            return ['error' => 'owner and repo are required'];
        }

        // Delegate to artisan command
        return $this->runArtisan("server:app get-deploy-config --owner=" . escapeshellarg($owner) . " --repo=" . escapeshellarg($repo));
    }

    protected function deployApp(?string $workspaceId, array $params): array
    {
        $serverId = $params['server_id'] ?? '';
        $owner = $params['owner'] ?? '';
        $repo = $params['repo'] ?? '';
        $envContent = $params['env_content'] ?? '';
        $domain = $params['domain'] ?? '';

        if (!$workspaceId || !$serverId || !$repo) {
            return ['error' => 'workspace_id, server_id, and repo are required'];
        }

        // Fetch compose.yml from GitHub (via artisan command)
        $configResult = $this->getDeployConfig($owner, $repo);
        if (isset($configResult['error']) || !isset($configResult['data']['compose'])) {
            return ['error' => 'Could not fetch compose.yml from GitHub'];
        }

        $compose = $configResult['data']['compose'];

        // Write compose and env to temp files
        $composeFile = "/tmp/deploy-{$repo}-compose.yml";
        $envFile = "/tmp/deploy-{$repo}.env";
        file_put_contents($composeFile, $compose);
        if ($envContent) {
            file_put_contents($envFile, $envContent);
        }

        // Add app via artisan
        $cmd = "server:app add --workspace={$workspaceId} --server={$serverId} --name=" . escapeshellarg($repo);
        $cmd .= " --compose=" . escapeshellarg($composeFile);
        if ($envContent) {
            $cmd .= " --env=" . escapeshellarg($envFile);
        }
        $result = $this->runArtisan($cmd);

        if (isset($result['data']['is_error']) && $result['data']['is_error']) {
            @unlink($composeFile);
            @unlink($envFile);
            return $result;
        }

        $appId = $result['data']['application']['id'] ?? null;
        if (!$appId) {
            @unlink($composeFile);
            @unlink($envFile);
            return ['error' => 'Failed to create app'];
        }

        // Deploy the app
        $deployResult = $this->runArtisan("server:app deploy --id={$appId}");

        // Add domain if provided
        if ($domain && !($deployResult['data']['is_error'] ?? false)) {
            $upstream = "{$repo}-nginx";
            $this->runArtisan("server:app add-domain --id={$appId} --domain=" . escapeshellarg($domain) . " --upstream=" . escapeshellarg($upstream));
        }

        // Clean up temp files
        @unlink($composeFile);
        @unlink($envFile);

        return ['data' => $deployResult];
    }

    protected function addDomain(array $params): array
    {
        $appId = $params['app_id'] ?? '';
        $domain = $params['domain'] ?? '';
        $upstream = $params['upstream'] ?? '';

        if (!$appId || !$domain) {
            return ['error' => 'app_id and domain are required'];
        }

        $cmd = "server:app add-domain --id={$appId} --domain=" . escapeshellarg($domain);
        if ($upstream) {
            $cmd .= " --upstream=" . escapeshellarg($upstream);
        }

        return $this->runArtisan($cmd);
    }

    protected function requestSsl(array $params): array
    {
        $appId = $params['app_id'] ?? '';
        $domain = $params['domain'] ?? '';

        if (!$appId) {
            return ['error' => 'app_id is required'];
        }

        $cmd = "server:app request-ssl --id={$appId}";
        if ($domain) {
            $cmd .= " --domain=" . escapeshellarg($domain);
        }

        return $this->runArtisan($cmd);
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    protected function runArtisan(string $command): array
    {
        $output = [];
        $exitCode = 0;
        exec("php /var/www/artisan {$command} 2>&1", $output, $exitCode);
        $json = implode("\n", $output);
        $result = json_decode($json, true);
        if ($result === null && !empty($json)) {
            return ['error' => $json, 'raw' => true];
        }
        // Wrap in 'data' key for PanelController response format
        return ['data' => $result ?: []];
    }
}
