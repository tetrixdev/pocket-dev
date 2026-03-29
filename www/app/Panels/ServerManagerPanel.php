<?php

namespace App\Panels;

use App\Models\Credential;
use App\Models\ServerApplication;
use App\Models\ServerConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

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

        $output = "Found {$servers->count()} server(s).\n\n";

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

## Deployment Prerequisites Checklist

Before deploying an application, verify ALL of these prerequisites. Do NOT proceed until each is confirmed:

### 1. Repository Setup
- [ ] Repository uses slim-docker-laravel-setup (check for `deploy/compose.yml`)
- [ ] GitHub Actions workflow exists (`.github/workflows/docker-laravel.yml`)
- [ ] A release exists with built Docker images on ghcr.io
- [ ] `www/bootstrap/app.php` has `trustProxies` middleware configured

### 2. Server Setup
- [ ] Server added to Server Manager (use panel or `server:connection add`)
- [ ] VPS setup installed (`has_vps_setup = true`)
- [ ] proxy-nginx installed (`has_proxy_nginx = true`)
- [ ] SSH connection working (`status = ready`)
- [ ] main-network exists (`docker network create main-network`)

### 3. DNS
- [ ] A record pointing domain to server IP (use your DNS provider's tools or manual configuration)

### 4. Application Deployment (in order)
1. `server:app add --name="App" --compose=path/to/compose.yml --env=path/to/.env`
2. `server:app deploy --id=<app-id>`
3. `server:app add-domain --id=<app-id> --domain=example.com --upstream=appname-nginx`
4. `server:app request-ssl --id=<app-id> --domain=example.com`

## Important Rules
- **NEVER manually SSH** to configure things - use the `server:app` commands
- **NEVER skip prerequisites** - each step depends on previous ones
- **Always verify** the repository has a recent release before deploying
- **Always use tools** - if a tool exists for the task, use it instead of manual commands

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
        $ghToken = $this->getGitHubToken();
        if (!$ghToken) {
            return ['error' => 'GH_TOKEN credential not configured. Add it in Settings > Credentials.'];
        }

        $owners = ['tetrixdev', 'jfbauer'];
        $apps = [];

        foreach ($owners as $owner) {
            $repos = $this->runGh("repo list {$owner} --json name,description,updatedAt,visibility --limit 100", $ghToken);
            if (!$repos) {
                continue;
            }

            foreach ($repos as $repo) {
                $repoName = $repo['name'];
                $fullName = "{$owner}/{$repoName}";

                // Check for docker-laravel structure
                $env = "GH_TOKEN=" . escapeshellarg($ghToken) . " ";
                $checkCmd = "{$env}gh api repos/{$fullName}/contents/docker-laravel 2>/dev/null | jq -r '.[].name' 2>/dev/null";
                $dirContents = shell_exec($checkCmd);

                if ($dirContents &&
                    str_contains($dirContents, 'local') &&
                    str_contains($dirContents, 'production') &&
                    str_contains($dirContents, 'shared')) {

                    $apps[] = [
                        'owner' => $owner,
                        'repo' => $repoName,
                        'full_name' => $fullName,
                        'description' => $repo['description'] ?? '',
                        'updated_at' => $repo['updatedAt'] ?? null,
                        'visibility' => $repo['visibility'] ?? 'private',
                    ];
                }
            }
        }

        return ['data' => ['apps' => $apps, 'count' => count($apps)]];
    }

    protected function getDeployConfig(?string $owner, ?string $repo): array
    {
        if (!$owner || !$repo) {
            return ['error' => 'owner and repo are required'];
        }

        $ghToken = $this->getGitHubToken();
        $env = $ghToken ? "GH_TOKEN=" . escapeshellarg($ghToken) . " " : "";

        // Fetch production compose.yml
        $composeB64 = shell_exec("{$env}gh api repos/{$owner}/{$repo}/contents/docker-laravel/production/compose.yml --jq '.content' 2>/dev/null");
        if (!$composeB64) {
            return ['error' => 'Could not fetch deployment config'];
        }
        $compose = base64_decode(trim($composeB64));

        // Fetch .env.example
        $envB64 = shell_exec("{$env}gh api repos/{$owner}/{$repo}/contents/docker-laravel/production/.env.example --jq '.content' 2>/dev/null");
        $envExample = $envB64 ? base64_decode(trim($envB64)) : '';

        // Parse env vars from .env.example
        $envVars = [];
        if ($envExample) {
            foreach (explode("\n", $envExample) as $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === '#') {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[trim($key)] = trim($value);
                }
            }
        }

        return [
            'data' => [
                'compose' => $compose,
                'env_example' => $envExample,
                'env_vars' => $envVars,
            ],
        ];
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

        $ghToken = $this->getGitHubToken();

        // Fetch compose.yml from GitHub
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

        if (isset($result['is_error']) && $result['is_error']) {
            @unlink($composeFile);
            @unlink($envFile);
            return ['data' => $result];
        }

        $appId = $result['application']['id'] ?? null;
        if (!$appId) {
            @unlink($composeFile);
            @unlink($envFile);
            return ['error' => 'Failed to create app'];
        }

        // Deploy the app
        $deployResult = $this->runArtisan("server:app deploy --id={$appId}");

        // Add domain if provided
        if ($domain && !($deployResult['is_error'] ?? false)) {
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
        return $result ?: [];
    }

    protected function runGh(string $command, string $token): ?array
    {
        $env = $token ? "GH_TOKEN=" . escapeshellarg($token) . " " : "";
        $output = shell_exec("{$env}gh {$command} 2>/dev/null");
        if (!$output) {
            return null;
        }
        return json_decode($output, true);
    }

    protected function getGitHubToken(): ?string
    {
        $credential = Credential::findByEnvVar('GH_TOKEN');
        return $credential?->getValue();
    }
}
