<?php

namespace App\Console\Commands;

use App\Models\ServerApplication;
use App\Models\ServerConnection;
use App\Models\Workspace;
use App\Support\SshConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ServerAppCommand extends Command
{
    protected $signature = 'server:app
        {action : The action to perform (list, add, deploy, start, stop, restart, logs, remove, update-env, add-domain, request-ssl, scan-repos, get-deploy-config, read-env-key, update-env-key)}
        {--workspace= : Workspace ID or name}
        {--server= : Server ID (for list, add)}
        {--id= : Application ID (for deploy, start, stop, restart, logs, remove, update-env, add-domain, request-ssl, read-env-key, update-env-key)}
        {--name= : Application name (for add)}
        {--slug= : Application slug (for add, auto-generated from name if not provided)}
        {--compose= : Path to compose.yml file or content (for add, deploy)}
        {--env= : Path to .env file or content (for add, update-env)}
        {--domain= : Domain name (for add-domain, request-ssl)}
        {--upstream= : Upstream container name for proxy (for add-domain)}
        {--redirect= : Redirect target URL (for add-domain with redirect type)}
        {--lines=100 : Number of log lines (for logs)}
        {--owner= : GitHub owner/org (for scan-repos, get-deploy-config)}
        {--repo= : GitHub repo name (for get-deploy-config)}
        {--key= : Environment variable key (for read-env-key, update-env-key)}
        {--value= : New value (for update-env-key)}
        {--full : Show full value instead of masked (for read-env-key - DO NOT use for secrets)}';

    protected $description = 'Manage applications on servers';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listApps(),
            'add' => $this->addApp(),
            'deploy' => $this->deployApp(),
            'start' => $this->controlApp('start'),
            'stop' => $this->controlApp('stop'),
            'restart' => $this->controlApp('restart'),
            'logs' => $this->viewLogs(),
            'remove' => $this->removeApp(),
            'update-env' => $this->updateEnv(),
            'add-domain' => $this->addDomain(),
            'request-ssl' => $this->requestSsl(),
            'scan-repos' => $this->scanGitHubRepos(),
            'get-deploy-config' => $this->getDeployConfig(),
            'read-env-key' => $this->readEnvKey(),
            'update-env-key' => $this->updateEnvKey(),
            default => $this->invalidAction($action),
        };
    }

    private function listApps(): int
    {
        $workspaceId = $this->resolveWorkspaceId($this->option('workspace'));
        $serverId = $this->option('server');

        if (!$workspaceId && !$serverId) {
            return $this->errorResponse('Either --workspace (UUID or name) or --server is required');
        }

        $query = ServerApplication::with('server');

        if ($serverId) {
            $query->onServer($serverId);
        } elseif ($workspaceId) {
            $query->forWorkspace($workspaceId);
        }

        $apps = $query->orderBy('name')->get();

        if ($apps->isEmpty()) {
            $this->outputJson([
                'output' => 'No applications found.',
                'applications' => [],
                'count' => 0,
            ]);
            return Command::SUCCESS;
        }

        $appList = $apps->map(fn($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'slug' => $a->slug,
            'server' => $a->server->name,
            'server_id' => $a->server_connection_id,
            'status' => $a->status,
            'status_label' => $a->status_label,
            'domains' => $a->domains ?? [],
            'primary_domain' => $a->primary_domain,
            'ssl_enabled' => $a->ssl_enabled,
            'deploy_path' => $a->full_deploy_path,
            'last_deployed_at' => $a->last_deployed_at?->toIso8601String(),
        ])->toArray();

        $this->outputJson([
            'output' => 'Found ' . count($appList) . ' application(s).',
            'applications' => $appList,
            'count' => count($appList),
        ]);

        return Command::SUCCESS;
    }

    private function addApp(): int
    {
        $workspaceId = $this->resolveWorkspaceId($this->option('workspace'));
        $serverId = $this->option('server');
        $name = $this->option('name');

        if (!$workspaceId) {
            return $this->errorResponse('--workspace is required (UUID or name like "default")');
        }
        if (!$serverId) {
            return $this->errorResponse('--server is required');
        }
        if (!$name) {
            return $this->errorResponse('--name is required');
        }

        $server = ServerConnection::find($serverId);
        if (!$server) {
            return $this->errorResponse('Server not found');
        }

        if ($server->workspace_id !== $workspaceId) {
            return $this->errorResponse('Server does not belong to this workspace');
        }

        $slug = $this->option('slug') ?: Str::slug($name);

        // Check if slug already exists on this server
        if (ServerApplication::where('server_connection_id', $serverId)->where('slug', $slug)->exists()) {
            return $this->errorResponse("Application with slug '{$slug}' already exists on this server");
        }

        $composeContent = $this->option('compose');
        $envContent = $this->option('env');

        // Read from file if path is provided
        if ($composeContent && file_exists($composeContent)) {
            $composeContent = file_get_contents($composeContent);
        }
        if ($envContent && file_exists($envContent)) {
            $envContent = file_get_contents($envContent);
        }

        $app = new ServerApplication([
            'workspace_id' => $workspaceId,
            'server_connection_id' => $serverId,
            'name' => $name,
            'slug' => $slug,
            'compose_content' => $composeContent,
            'deploy_path' => "/home/{$server->ssh_user}/docker-apps/{$slug}",
            'status' => 'stopped',
        ]);

        if ($envContent) {
            $app->setEnvContent($envContent);
        }

        $app->save();

        $this->outputJson([
            'output' => "Application '{$name}' added. Run 'server:app deploy --id={$app->id}' to deploy.",
            'application' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'server' => $server->name,
                'deploy_path' => $app->full_deploy_path,
            ],
        ]);

        return Command::SUCCESS;
    }

    private function deployApp(): int
    {
        $app = $this->getApp();
        if (!$app) {
            return Command::FAILURE;
        }

        if (!$app->compose_content) {
            return $this->errorResponse('No compose.yml content configured. Use update with --compose first.');
        }

        try {
            $ssh = $this->getSshConnection($app->server);

            if (!$ssh->test()) {
                $app->markFailed('Cannot connect to server');
                return $this->errorResponse('Cannot connect to server');
            }

            $app->markDeploying();
            $deployPath = $app->full_deploy_path;

            // Create directory
            $ssh->run("mkdir -p " . escapeshellarg($deployPath));

            // Write compose.yml
            $composePath = "{$deployPath}/compose.yml";
            $composeContent = base64_encode($app->compose_content);
            $ssh->run("echo " . escapeshellarg($composeContent) . " | base64 -d > " . escapeshellarg($composePath));

            // Write .env if exists
            $envContent = $app->getEnvDecrypted();
            if ($envContent) {
                $envPath = "{$deployPath}/.env";
                $envEncoded = base64_encode($envContent);
                $ssh->run("echo " . escapeshellarg($envEncoded) . " | base64 -d > " . escapeshellarg($envPath));
            }

            // Pull and start containers
            $result = $ssh->run("cd " . escapeshellarg($deployPath) . " && docker compose pull && docker compose up -d", 300);

            if ($result->successful()) {
                $app->markDeployed();
                $this->outputJson([
                    'output' => "Application '{$app->name}' deployed successfully.",
                    'success' => true,
                    'deploy_output' => $result->output(),
                ]);
            } else {
                $app->markFailed($result->errorOutput());
                $this->outputJson([
                    'output' => "Deployment failed for '{$app->name}'.",
                    'success' => false,
                    'error' => $result->errorOutput(),
                ]);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $app->markFailed($e->getMessage());
            return $this->errorResponse("Deployment failed: " . $e->getMessage());
        }
    }

    private function controlApp(string $action): int
    {
        $app = $this->getApp();
        if (!$app) {
            return Command::FAILURE;
        }

        try {
            $ssh = $this->getSshConnection($app->server);
            $deployPath = $app->full_deploy_path;

            $command = match ($action) {
                'start' => "docker compose up -d",
                'stop' => "docker compose down",
                'restart' => "docker compose restart",
            };

            $result = $ssh->run("cd " . escapeshellarg($deployPath) . " && {$command}", 120);

            if ($result->successful()) {
                $newStatus = match ($action) {
                    'start', 'restart' => 'running',
                    'stop' => 'stopped',
                };
                $app->update(['status' => $newStatus, 'last_error' => null]);

                $this->outputJson([
                    'output' => "Application '{$app->name}' {$action} successful.",
                    'success' => true,
                    'status' => $newStatus,
                ]);
            } else {
                $this->outputJson([
                    'output' => "Failed to {$action} '{$app->name}'.",
                    'success' => false,
                    'error' => $result->errorOutput(),
                ]);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->errorResponse("{$action} failed: " . $e->getMessage());
        }
    }

    private function viewLogs(): int
    {
        $app = $this->getApp();
        if (!$app) {
            return Command::FAILURE;
        }

        $lines = (int) $this->option('lines');

        try {
            $ssh = $this->getSshConnection($app->server);
            $deployPath = $app->full_deploy_path;

            $result = $ssh->run("cd " . escapeshellarg($deployPath) . " && docker compose logs --tail={$lines}", 60);

            $this->outputJson([
                'output' => "Logs for '{$app->name}' (last {$lines} lines):",
                'logs' => $result->output(),
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->errorResponse("Failed to get logs: " . $e->getMessage());
        }
    }

    private function removeApp(): int
    {
        $app = $this->getApp();
        if (!$app) {
            return Command::FAILURE;
        }

        try {
            $ssh = $this->getSshConnection($app->server);
            $deployPath = $app->full_deploy_path;

            // Stop containers
            $ssh->run("cd " . escapeshellarg($deployPath) . " && docker compose down 2>/dev/null || true", 60);

            // Note: We don't delete the directory on the server - user can do that manually if needed

            $appName = $app->name;
            $app->delete();

            $this->outputJson([
                'output' => "Application '{$appName}' removed from PocketDev. Files on server at {$deployPath} were not deleted.",
                'deleted' => true,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->errorResponse("Remove failed: " . $e->getMessage());
        }
    }

    private function updateEnv(): int
    {
        $app = $this->getApp();
        if (!$app) {
            return Command::FAILURE;
        }

        $envContent = $this->option('env');
        if (!$envContent) {
            return $this->errorResponse('--env is required (path to file or content)');
        }

        // Read from file if path is provided
        if (file_exists($envContent)) {
            $envContent = file_get_contents($envContent);
        }

        $app->setEnvContent($envContent);
        $app->save();

        // Update on server if deployed
        if ($app->status !== 'stopped') {
            try {
                $ssh = $this->getSshConnection($app->server);
                $envPath = "{$app->full_deploy_path}/.env";
                $envEncoded = base64_encode($envContent);
                $ssh->run("echo " . escapeshellarg($envEncoded) . " | base64 -d > " . escapeshellarg($envPath));
            } catch (\Exception $e) {
                $this->outputJson([
                    'output' => "Environment updated in database but failed to sync to server: " . $e->getMessage(),
                    'synced_to_server' => false,
                ]);
                return Command::SUCCESS;
            }
        }

        $this->outputJson([
            'output' => "Environment updated for '{$app->name}'.",
            'synced_to_server' => $app->status !== 'stopped',
        ]);

        return Command::SUCCESS;
    }

    private function addDomain(): int
    {
        $app = $this->getApp();
        if (!$app) {
            return Command::FAILURE;
        }

        $domain = $this->option('domain');
        $upstream = $this->option('upstream');
        $redirect = $this->option('redirect');

        if (!$domain) {
            return $this->errorResponse('--domain is required');
        }

        // Add domain to app's domain list
        $domains = $app->domains ?? [];
        if (!in_array($domain, $domains)) {
            $domains[] = $domain;
            $app->domains = $domains;
        }

        if ($upstream && !$app->upstream_container) {
            $app->upstream_container = $upstream;
        }

        $app->save();

        // If server has proxy-nginx, update the config
        if ($app->server->has_proxy_nginx) {
            try {
                $ssh = $this->getSshConnection($app->server);
                $this->updateProxyConfig($ssh, $app, $domain, $redirect);
            } catch (\Exception $e) {
                return $this->errorResponse("Failed to update proxy config: " . $e->getMessage());
            }
        }

        $this->outputJson([
            'output' => "Domain '{$domain}' added to '{$app->name}'. Run 'server:app request-ssl' to enable HTTPS.",
            'domains' => $app->domains,
        ]);

        return Command::SUCCESS;
    }

    private function requestSsl(): int
    {
        $app = $this->getApp();
        if (!$app) {
            return Command::FAILURE;
        }

        $domain = $this->option('domain');
        if (!$domain) {
            // Use primary domain if not specified
            $domain = $app->primary_domain;
            if (!$domain) {
                return $this->errorResponse('No domain configured. Add a domain first or specify --domain');
            }
        }

        if (!$app->server->has_proxy_nginx) {
            return $this->errorResponse('Server does not have proxy-nginx installed');
        }

        try {
            $ssh = $this->getSshConnection($app->server);

            // Run certbot
            $result = $ssh->run("docker exec proxy-nginx certbot --nginx -d " . escapeshellarg($domain) . " --non-interactive --agree-tos --register-unsafely-without-email", 120);

            if ($result->successful() || str_contains($result->output(), 'Successfully')) {
                // Get certificate expiry
                $expiryOutput = $ssh->exec("docker exec proxy-nginx certbot certificates -d " . escapeshellarg($domain) . " 2>/dev/null | grep 'Expiry Date' | head -1");
                $expiryDate = null;
                if ($expiryOutput && preg_match('/Expiry Date: (\d{4}-\d{2}-\d{2})/', $expiryOutput, $matches)) {
                    $expiryDate = $matches[1];
                }

                $app->update([
                    'ssl_enabled' => true,
                    'ssl_expires_at' => $expiryDate ? \Carbon\Carbon::parse($expiryDate) : null,
                ]);

                $this->outputJson([
                    'output' => "SSL certificate obtained for '{$domain}'.",
                    'success' => true,
                    'ssl_expires_at' => $expiryDate,
                ]);
            } else {
                $this->outputJson([
                    'output' => "SSL certificate request failed for '{$domain}'.",
                    'success' => false,
                    'error' => $result->errorOutput() ?: $result->output(),
                ]);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->errorResponse("SSL request failed: " . $e->getMessage());
        }
    }

    // Helper methods

    private function getApp(): ?ServerApplication
    {
        $id = $this->option('id');
        if (!$id) {
            $this->errorResponse('--id is required');
            return null;
        }

        $app = ServerApplication::with('server')->find($id);
        if (!$app) {
            $this->errorResponse('Application not found');
            return null;
        }

        return $app;
    }

    private function getSshConnection(ServerConnection $server): SshConnection
    {
        $keyPath = "/var/www/.pocketdev/ssh/{$server->workspace_id}/id_ed25519";

        return new SshConnection([
            'ssh_host' => $server->host,
            'ssh_user' => $server->ssh_user,
            'ssh_port' => $server->ssh_port,
            'ssh_key_path' => file_exists($keyPath) ? $keyPath : null,
            'server_name' => $server->name,
        ]);
    }

    private function updateProxyConfig(SshConnection $ssh, ServerApplication $app, string $domain, ?string $redirect): void
    {
        $configPath = "/home/{$app->server->ssh_user}/docker-apps/proxy-nginx/default.conf";
        $upstream = $app->upstream_container ?: "{$app->slug}-nginx";

        if ($redirect) {
            // Redirect server block
            $block = <<<NGINX

# {$app->name} - Redirect
server {
    server_name {$domain};
    return 308 {$redirect}\$request_uri;
    listen 80;
}
NGINX;
        } else {
            // Normal server block
            $block = <<<NGINX

# {$app->name}
server {
    server_name {$domain};
    client_max_body_size 256M;
    root /var/www/html;
    ssl_buffer_size 1400;

    location / {
        set \$upstream http://{$upstream};
        resolver 127.0.0.11 valid=30s;
        proxy_pass \$upstream;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Host \$host;
        proxy_redirect off;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_read_timeout 600s;
        proxy_send_timeout 600s;
        proxy_intercept_errors on;
        error_page 502 503 /maintenance.html;
    }

    location = /maintenance.html {
        internal;
        root /var/www/html;
    }

    listen 80;
}
NGINX;
        }

        // Append to config
        $escapedBlock = base64_encode($block);
        $ssh->run("echo " . escapeshellarg($escapedBlock) . " | base64 -d >> " . escapeshellarg($configPath));

        // Reload nginx
        $ssh->run("docker exec proxy-nginx nginx -s reload");
    }

    private function scanGitHubRepos(): int
    {
        $ghToken = $this->getGitHubToken();
        if (!$ghToken) {
            return $this->errorResponse('GH_TOKEN credential not configured. Add it in Settings > Credentials.');
        }

        // Get owners to scan - default to common org
        $ownerOption = $this->option('owner');
        $owners = $ownerOption ? [$ownerOption] : ['tetrixdev', 'jfbauer'];
        $apps = [];

        foreach ($owners as $owner) {
            $reposJson = $this->runGh("repo list {$owner} --json name,description,updatedAt,visibility --limit 100", $ghToken);
            if (!$reposJson) {
                continue;
            }

            $repos = json_decode($reposJson, true) ?: [];

            foreach ($repos as $repo) {
                $repoName = $repo['name'];
                $fullName = "{$owner}/{$repoName}";

                // Check for slim-docker-laravel-setup structure (deploy/compose.yml)
                $checkOutput = $this->runGh("api repos/{$fullName}/contents/deploy --jq '.[].name' 2>/dev/null", $ghToken);

                if ($checkOutput && str_contains($checkOutput, 'compose.yml')) {
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

        $this->outputJson([
            'output' => 'Found ' . count($apps) . ' deployable repository(s).',
            'apps' => $apps,
            'count' => count($apps),
        ]);

        return Command::SUCCESS;
    }

    private function getDeployConfig(): int
    {
        $owner = $this->option('owner');
        $repo = $this->option('repo');

        if (!$owner || !$repo) {
            return $this->errorResponse('--owner and --repo are required');
        }

        $ghToken = $this->getGitHubToken();
        if (!$ghToken) {
            return $this->errorResponse('GH_TOKEN credential not configured. Add it in Settings > Credentials.');
        }

        // Fetch deploy/compose.yml
        $composeB64 = $this->runGh("api repos/{$owner}/{$repo}/contents/deploy/compose.yml --jq '.content'", $ghToken);
        if (!$composeB64) {
            return $this->errorResponse('Could not fetch deploy/compose.yml from repository');
        }
        // GitHub API returns base64 with newlines, need to clean before decode
        $cleanB64 = str_replace(["\n", "\r", " "], '', trim($composeB64));
        $compose = base64_decode($cleanB64, true); // strict mode
        if ($compose === false) {
            return $this->errorResponse('Failed to decode compose.yml content');
        }

        // Fetch deploy/.env.example (optional)
        $envB64 = $this->runGh("api repos/{$owner}/{$repo}/contents/deploy/.env.example --jq '.content' 2>/dev/null", $ghToken);
        $envExample = '';
        if ($envB64) {
            // GitHub API returns base64 with newlines, need to clean before decode
            $cleanB64 = str_replace(["\n", "\r", " "], '', trim($envB64));
            $decoded = base64_decode($cleanB64, true); // strict mode
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                $envExample = $decoded;
            }
        }

        // Parse env vars from .env.example
        $envVars = [];
        if ($envExample) {
            foreach (explode("\n", $envExample) as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[trim($key)] = trim($value);
                }
            }
        }

        // Check slim-docker-laravel-setup version
        $versionCheck = $this->checkSlimDockerVersion($owner, $repo, $ghToken);

        $output = "Fetched deploy config for {$owner}/{$repo}.";
        if ($versionCheck['warning']) {
            $output .= "\n\n⚠️  WARNING: " . $versionCheck['warning'];
        }

        $this->outputJson([
            'output' => $output,
            'compose' => $compose,
            'env_example' => $envExample,
            'env_vars' => $envVars,
            'slim_docker_version' => $versionCheck,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Check if repository's slim-docker-laravel-setup is up to date.
     */
    private function checkSlimDockerVersion(string $owner, string $repo, string $ghToken): array
    {
        $result = [
            'current_version' => null,
            'latest_version' => null,
            'is_outdated' => false,
            'warning' => null,
        ];

        // Fetch .slim-docker-version from the repo
        $versionB64 = $this->runGh("api repos/{$owner}/{$repo}/contents/.slim-docker-version --jq '.content' 2>/dev/null", $ghToken);
        if ($versionB64) {
            $cleanB64 = str_replace(["\n", "\r", " "], '', trim($versionB64));
            $decoded = base64_decode($cleanB64, true);
            if ($decoded !== false) {
                $result['current_version'] = trim($decoded);
            }
        }

        // Fetch latest commit from slim-docker-laravel-setup main branch
        $latestJson = $this->runGh("api repos/tetrixdev/slim-docker-laravel-setup/commits/main --jq '.sha'", $ghToken);
        if ($latestJson) {
            $result['latest_version'] = trim($latestJson);
        }

        // Determine if outdated
        if (!$result['current_version']) {
            $result['is_outdated'] = true;
            $result['warning'] = "Repository is missing .slim-docker-version file, indicating it uses an older slim-docker-laravel-setup. Update required before deploying.";
        } elseif ($result['latest_version'] && $result['current_version'] !== $result['latest_version']) {
            $result['is_outdated'] = true;
            $result['warning'] = "Repository's slim-docker-laravel-setup is outdated (current: " . substr($result['current_version'], 0, 7) . ", latest: " . substr($result['latest_version'], 0, 7) . "). Update required before deploying.";
        }

        return $result;
    }

    private function readEnvKey(): int
    {
        $app = $this->getApp();
        if (!$app) {
            return Command::FAILURE;
        }

        $key = $this->option('key');
        if (!$key) {
            return $this->errorResponse('--key is required');
        }

        // Validate key format to prevent regex injection and ensure valid env key
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/i', $key)) {
            return $this->errorResponse('Key must start with a letter and contain only letters, numbers, and underscores');
        }

        $showFull = $this->option('full');

        try {
            $ssh = $this->getSshConnection($app->server);
            $envPath = "{$app->full_deploy_path}/.env";

            // Read just the specific key using grep -F for fixed string match (safer than regex)
            $result = $ssh->run("grep -F '" . $key . "=' " . escapeshellarg($envPath) . " 2>/dev/null | grep -E '^" . $key . "=' | head -1");

            if (!$result->successful() || empty(trim($result->output()))) {
                $this->outputJson([
                    'output' => "Key '{$key}' not found in .env",
                    'key' => $key,
                    'found' => false,
                ]);
                return Command::SUCCESS;
            }

            $line = trim($result->output());
            $value = '';
            if (str_contains($line, '=')) {
                [, $value] = explode('=', $line, 2);
            }

            // Determine if this looks like a secret
            $secretPatterns = ['PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'PRIVATE', 'CREDENTIAL', 'AUTH'];
            $isLikelySecret = false;
            foreach ($secretPatterns as $pattern) {
                if (stripos($key, $pattern) !== false) {
                    $isLikelySecret = true;
                    break;
                }
            }

            // Mask the value if it's likely a secret and --full wasn't specified
            $displayValue = $value;
            $masked = false;
            if ($isLikelySecret && !$showFull && strlen($value) > 6) {
                $displayValue = substr($value, 0, 3) . '...' . substr($value, -3);
                $masked = true;
            }

            $this->outputJson([
                'output' => $masked
                    ? "Key '{$key}' value (masked - use --full to see complete value, but DO NOT use --full for secrets)"
                    : "Key '{$key}' value:",
                'key' => $key,
                'value' => $displayValue,
                'found' => true,
                'masked' => $masked,
                'is_likely_secret' => $isLikelySecret,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->errorResponse("Failed to read env key: " . $e->getMessage());
        }
    }

    private function updateEnvKey(): int
    {
        $app = $this->getApp();
        if (!$app) {
            return Command::FAILURE;
        }

        $key = $this->option('key');
        $value = $this->option('value');

        if (!$key) {
            return $this->errorResponse('--key is required');
        }
        if ($value === null) {
            return $this->errorResponse('--value is required');
        }

        // Validate key format (alphanumeric and underscores only)
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            return $this->errorResponse('Key must be uppercase alphanumeric with underscores (e.g., DB_PASSWORD)');
        }

        try {
            $ssh = $this->getSshConnection($app->server);
            $envPath = "{$app->full_deploy_path}/.env";

            // Check if .env file exists
            $checkResult = $ssh->run("test -f " . escapeshellarg($envPath) . " && echo 'exists'");
            if (trim($checkResult->output()) !== 'exists') {
                return $this->errorResponse('.env file does not exist on server. Deploy the app first.');
            }

            // Check if key exists in file
            $grepResult = $ssh->run("grep -q '^" . $key . "=' " . escapeshellarg($envPath) . " && echo 'found'");
            $keyExists = trim($grepResult->output()) === 'found';

            // Use base64 encoding to safely transmit value (avoids shell escaping issues)
            $line = $key . '=' . $value;
            $encoded = base64_encode($line);

            if ($keyExists) {
                // Read current file, decode, update line, re-encode, write back
                // This is safer than sed which has escaping issues with special chars
                $cmd = "grep -v '^" . $key . "=' " . escapeshellarg($envPath) . " > " . escapeshellarg($envPath) . ".tmp && " .
                       "echo " . escapeshellarg($encoded) . " | base64 -d >> " . escapeshellarg($envPath) . ".tmp && " .
                       "mv " . escapeshellarg($envPath) . ".tmp " . escapeshellarg($envPath);
            } else {
                // Append new key using base64
                $cmd = "echo " . escapeshellarg($encoded) . " | base64 -d >> " . escapeshellarg($envPath);
            }

            $result = $ssh->run($cmd);

            if (!$result->successful()) {
                return $this->errorResponse('Failed to update .env: ' . $result->errorOutput());
            }

            $this->outputJson([
                'output' => $keyExists
                    ? "Updated '{$key}' in .env"
                    : "Added '{$key}' to .env",
                'key' => $key,
                'action' => $keyExists ? 'updated' : 'added',
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->errorResponse("Failed to update env key: " . $e->getMessage());
        }
    }

    private function getGitHubToken(): ?string
    {
        $credential = \App\Models\Credential::findByEnvVar('GH_TOKEN');
        return $credential?->getValue();
    }

    private function runGh(string $command, string $token): ?string
    {
        $env = "GH_TOKEN=" . escapeshellarg($token) . " ";
        $output = shell_exec("{$env}gh {$command} 2>/dev/null");
        return $output ? trim($output) : null;
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
        return $this->errorResponse("Invalid action '{$action}'. Valid: list, add, deploy, start, stop, restart, logs, remove, update-env, add-domain, request-ssl, scan-repos, get-deploy-config, read-env-key, update-env-key");
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
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->output->writeln($json ?: '{"is_error": true, "output": "JSON encoding failed"}');
    }
}
