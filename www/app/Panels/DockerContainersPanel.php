<?php

namespace App\Panels;

use App\Support\SshConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DockerContainersPanel extends Panel
{
    public string $slug = 'docker-containers';
    public string $name = 'Docker Containers';
    public string $description = 'View and manage Docker containers grouped by Compose project';
    public string $icon = 'fa-brands fa-docker';
    public string $category = 'development';

    public array $parameters = [
        'ssh_host' => [
            'type' => 'string',
            'description' => 'SSH host for remote Docker management (omit for local)',
            'default' => null,
        ],
        'ssh_user' => [
            'type' => 'string',
            'description' => 'SSH username',
            'default' => 'root',
        ],
        'ssh_port' => [
            'type' => 'integer',
            'description' => 'SSH port',
            'default' => 22,
        ],
        'ssh_password' => [
            'type' => 'string',
            'description' => 'SSH password (omit for key-based auth)',
            'default' => null,
        ],
        'ssh_key_path' => [
            'type' => 'string',
            'description' => 'Path to SSH private key (default: ~/.ssh/id_rsa or id_ed25519)',
            'default' => null,
        ],
        'server_name' => [
            'type' => 'string',
            'description' => 'Friendly server name shown in the panel header (e.g. "Production", "SGS Main")',
            'default' => null,
        ],
    ];

    /**
     * Create SSH connection from panel params, or null for local mode.
     */
    protected function getSsh(array $panelParams): ?SshConnection
    {
        return SshConnection::fromPanelParams($panelParams);
    }

    public function render(array $params, array $state, ?string $panelStateId = null): string
    {
        $ssh = $this->getSsh($params);

        return view('panels.docker-containers', [
            'state' => $state,
            'panelStateId' => $panelStateId,
            'sshLabel' => $ssh?->getLabel(),
        ])->render();
    }

    /**
     * Handle panel actions for container management.
     */
    public function handleAction(string $action, array $params, array $state, array $panelParams = []): array
    {
        return match ($action) {
            'refresh' => $this->getContainers($panelParams),
            'start' => $this->startProject($params, $panelParams),
            'stop' => $this->stopProject($params, $panelParams),
            'restart' => $this->restartProject($params, $panelParams),
            'logs' => $this->getLogs($params, $panelParams),
            default => parent::handleAction($action, $params, $state, $panelParams),
        };
    }

    /**
     * Get all containers with their status and metadata.
     */
    protected function getContainers(array $panelParams = []): array
    {
        $ssh = $this->getSsh($panelParams);

        $format = '{{.ID}}~{{.Names}}~{{.Image}}~{{.Status}}~{{.CreatedAt}}~{{.Ports}}~{{.Networks}}~{{.Mounts}}~{{.Label "com.docker.compose.project"}}~{{.Label "com.docker.compose.project.working_dir"}}~{{.Label "com.docker.compose.service"}}';

        $cmd = "docker ps -a --format '{$format}' 2>/dev/null";

        if ($ssh) {
            $result = $ssh->run($cmd, 30);
        } else {
            $result = Process::timeout(30)->run($cmd);
        }

        if ($result->failed()) {
            return ['error' => 'Failed to get container list' . ($ssh ? ' via SSH' : '')];
        }

        $containers = [];
        $lines = array_filter(explode("\n", trim($result->output())));

        foreach ($lines as $line) {
            // Limit to 11 parts so ~ in mount paths doesn't break parsing
            $parts = explode('~', $line, 11);
            if (count($parts) < 11) {
                continue;
            }

            [$id, $name, $image, $status, $created, $ports, $networks, $mounts, $project, $workingDir, $service] = $parts;

            // Derive project name if not set via compose labels
            if (empty($project)) {
                $project = preg_replace('/[-_][^-_]+$/', '', $name) ?: 'other';
            }

            if (empty($service)) {
                $service = $name;
            }

            // Parse health status from status string
            $health = null;
            if (str_contains($status, '(healthy)')) {
                $health = 'healthy';
            } elseif (str_contains($status, '(unhealthy)')) {
                $health = 'unhealthy';
            } elseif (str_contains($status, '(health: starting)')) {
                $health = 'starting';
            }

            // Check if running
            $running = str_starts_with($status, 'Up');

            // Parse ports into array
            $portsArray = $ports ? array_map('trim', explode(', ', $ports)) : [];

            // Parse networks and mounts into arrays
            $networksArray = $networks ? array_map('trim', explode(',', $networks)) : [];
            $mountsArray = $mounts ? array_map('trim', explode(',', $mounts)) : [];

            $containers[] = [
                'id' => $id,
                'name' => $name,
                'image' => $image,
                'status' => $status,
                'created' => $created,
                'ports' => $portsArray,
                'networks' => $networksArray,
                'mounts' => $mountsArray,
                'health' => $health,
                'running' => $running,
                'project' => $project,
                'working_dir' => $workingDir,
                'service' => $service,
            ];
        }

        return [
            'data' => ['containers' => $containers],
            'error' => null,
        ];
    }

    /**
     * Start a Docker Compose project.
     */
    protected function startProject(array $params, array $panelParams = []): array
    {
        $ssh = $this->getSsh($panelParams);
        $project = $params['project'] ?? '';
        $workingDir = $params['working_dir'] ?? '';

        if ($ssh) {
            // Remote mode: no pocket-dev protection, no compose:transform
            if (empty($workingDir)) {
                return ['error' => 'Working directory not specified'];
            }

            // Check remote directory exists
            $testDir = $ssh->exec("test -d " . escapeshellarg($workingDir) . " && echo yes", 10);
            if (!$testDir || !str_contains($testDir, 'yes')) {
                return ['error' => "Remote directory not found: '{$workingDir}'"];
            }

            $result = $ssh->run(
                'docker compose up -d --force-recreate 2>&1',
                120,
                $workingDir
            );
        } else {
            // Local mode: existing behavior
            if (str_starts_with($project, 'pocket-dev')) {
                return ['error' => 'Cannot modify PocketDev containers'];
            }

            if (empty($workingDir) || !is_dir($workingDir)) {
                return ['error' => "Working directory not found: '{$workingDir}'"];
            }

            // Ensure compose override exists (for PocketDev volume mounts)
            $composeFile = $this->findComposeFile($workingDir);
            if ($composeFile && !file_exists($workingDir . '/compose.override.yaml')) {
                Process::timeout(60)->run("pd compose:transform --input=" . escapeshellarg($composeFile));
            }

            $result = Process::timeout(120)
                ->path($workingDir)
                ->env(['HOME' => '/tmp', 'PATH' => getenv('PATH')])
                ->run('/usr/libexec/docker/cli-plugins/docker-compose up -d --force-recreate 2>&1');
        }

        if ($result->failed()) {
            $error = trim($result->output());
            $lastLines = implode(' ', array_slice(explode("\n", $error), -3));

            return [
                'data' => ['success' => false, 'message' => "Start failed: {$lastLines}"],
                'error' => null,
            ];
        }

        return [
            'data' => ['success' => true, 'message' => 'Started successfully'],
            'error' => null,
        ];
    }

    /**
     * Stop a Docker Compose project.
     */
    protected function stopProject(array $params, array $panelParams = []): array
    {
        $ssh = $this->getSsh($panelParams);
        $project = $params['project'] ?? '';
        $workingDir = $params['working_dir'] ?? '';

        if ($ssh) {
            // Remote mode
            if (empty($workingDir)) {
                return ['error' => 'Working directory not specified'];
            }

            // Check remote directory exists
            $testDir = $ssh->exec("test -d " . escapeshellarg($workingDir) . " && echo yes", 10);
            if (!$testDir || !str_contains($testDir, 'yes')) {
                return ['error' => "Remote directory not found: '{$workingDir}'"];
            }

            $result = $ssh->run(
                'docker compose stop 2>&1',
                60,
                $workingDir
            );
        } else {
            // Local mode
            if (str_starts_with($project, 'pocket-dev')) {
                return ['error' => 'Cannot stop PocketDev containers'];
            }

            if (empty($workingDir) || !is_dir($workingDir)) {
                return ['error' => "Working directory not found: '{$workingDir}'"];
            }

            $result = Process::timeout(60)
                ->path($workingDir)
                ->env(['HOME' => '/tmp', 'PATH' => getenv('PATH')])
                ->run('/usr/libexec/docker/cli-plugins/docker-compose stop 2>&1');
        }

        if ($result->failed()) {
            $error = trim($result->output());
            $lastLines = implode(' ', array_slice(explode("\n", $error), -3));

            return [
                'data' => ['success' => false, 'message' => "Stop failed: {$lastLines}"],
                'error' => null,
            ];
        }

        return [
            'data' => ['success' => true, 'message' => 'Stopped successfully'],
            'error' => null,
        ];
    }

    /**
     * Restart a Docker Compose project.
     */
    protected function restartProject(array $params, array $panelParams = []): array
    {
        $ssh = $this->getSsh($panelParams);
        $project = $params['project'] ?? '';
        $workingDir = $params['working_dir'] ?? '';

        if ($ssh) {
            // Remote mode
            if (empty($workingDir)) {
                return ['error' => 'Working directory not specified'];
            }

            // Check remote directory exists
            $testDir = $ssh->exec("test -d " . escapeshellarg($workingDir) . " && echo yes", 10);
            if (!$testDir || !str_contains($testDir, 'yes')) {
                return ['error' => "Remote directory not found: '{$workingDir}'"];
            }

            $stopResult = $ssh->run('docker compose stop 2>&1', 60, $workingDir);
            if ($stopResult->failed()) {
                Log::warning('DockerContainersPanel: remote stop failed before restart', [
                    'project' => $project,
                    'output' => substr($stopResult->output(), 0, 200),
                ]);
            }
        } else {
            // Local mode
            if (str_starts_with($project, 'pocket-dev')) {
                return ['error' => 'Cannot modify PocketDev containers'];
            }

            if (empty($workingDir) || !is_dir($workingDir)) {
                return ['error' => "Working directory not found: '{$workingDir}'"];
            }

            $stopResult = Process::timeout(60)
                ->path($workingDir)
                ->env(['HOME' => '/tmp', 'PATH' => getenv('PATH')])
                ->run('/usr/libexec/docker/cli-plugins/docker-compose stop 2>&1');
            if ($stopResult->failed()) {
                Log::warning('DockerContainersPanel: local stop failed before restart', [
                    'project' => $project,
                    'output' => substr($stopResult->output(), 0, 200),
                ]);
            }
        }

        // Then start with force-recreate
        return $this->startProject($params, $panelParams);
    }

    /**
     * Find the compose file in a directory.
     */
    protected function findComposeFile(string $dir): ?string
    {
        foreach (['compose.yml', 'compose.yaml', 'docker-compose.yml', 'docker-compose.yaml'] as $filename) {
            $path = $dir . '/' . $filename;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get logs for a project or specific container.
     */
    protected function getLogs(array $params, array $panelParams = []): array
    {
        $ssh = $this->getSsh($panelParams);
        $project = $params['project'] ?? '';
        $container = $params['container'] ?? null;
        $workingDir = $params['working_dir'] ?? '';
        $tail = max(1, min((int) ($params['tail'] ?? 100), 1000));

        // For specific container, use docker logs directly
        if ($container) {
            $cmd = "docker logs " . escapeshellarg($container) . " --tail {$tail} 2>&1";

            if ($ssh) {
                $result = $ssh->run($cmd, 30);
            } else {
                $result = Process::timeout(30)->run($cmd);
            }

            if ($result->failed()) {
                return ['error' => 'Failed to get container logs: ' . $result->output()];
            }

            $lines = explode("\n", $result->output());

            return [
                'data' => [
                    'logs' => $result->output(),
                    'lines' => count($lines),
                    'tail' => $tail,
                    'container' => $container,
                ],
                'error' => null,
            ];
        }

        // For project logs, try docker compose first
        if ($ssh) {
            // Remote: always try docker compose logs
            if (!empty($workingDir)) {
                $result = $ssh->run(
                    "docker compose logs --tail {$tail} 2>&1",
                    60,
                    $workingDir
                );

                if ($result->successful()) {
                    $lines = explode("\n", $result->output());
                    return [
                        'data' => [
                            'logs' => $result->output(),
                            'lines' => count($lines),
                            'tail' => $tail,
                            'project' => $project,
                        ],
                        'error' => null,
                    ];
                }
            }

            // Fallback: get containers by project label and combine logs
            return $this->getProjectLogsFallback($project, $tail, $ssh);
        }

        // Local: existing behavior
        if (empty($workingDir) || !is_dir($workingDir)) {
            return $this->getProjectLogsFallback($project, $tail, null);
        }

        // Use docker compose logs
        $result = Process::timeout(60)
            ->path($workingDir)
            ->env(['HOME' => '/tmp', 'PATH' => getenv('PATH')])
            ->run("/usr/libexec/docker/cli-plugins/docker-compose logs --tail {$tail} 2>&1");

        if ($result->failed()) {
            return ['error' => 'Failed to get project logs: ' . substr($result->output(), 0, 200)];
        }

        $lines = explode("\n", $result->output());

        return [
            'data' => [
                'logs' => $result->output(),
                'lines' => count($lines),
                'tail' => $tail,
                'project' => $project,
            ],
            'error' => null,
        ];
    }

    /**
     * Fallback log retrieval: get all containers for a project and combine logs.
     */
    protected function getProjectLogsFallback(string $project, int $tail, ?SshConnection $ssh): array
    {
        $format = '{{.Names}}';
        $filter = escapeshellarg("label=com.docker.compose.project={$project}");
        $psCmd = "docker ps -a --filter {$filter} --format " . escapeshellarg($format) . " 2>/dev/null";

        if ($ssh) {
            $psResult = $ssh->run($psCmd, 10);
        } else {
            $psResult = Process::timeout(10)->run($psCmd);
        }

        if ($psResult->failed() || empty(trim($psResult->output()))) {
            return ['error' => "No containers found for project: {$project}"];
        }

        $containers = array_filter(explode("\n", trim($psResult->output())));
        $allLogs = [];
        $perContainerTail = (int) ceil($tail / count($containers));

        foreach ($containers as $containerName) {
            $logCmd = "docker logs " . escapeshellarg($containerName) . " --tail {$perContainerTail} 2>&1";

            if ($ssh) {
                $logResult = $ssh->run($logCmd, 15);
            } else {
                $logResult = Process::timeout(15)->run($logCmd);
            }

            if ($logResult->successful()) {
                $allLogs[] = "=== {$containerName} ===\n" . $logResult->output();
            }
        }

        return [
            'data' => [
                'logs' => implode("\n\n", $allLogs),
                'lines' => count(explode("\n", implode("\n", $allLogs))),
                'tail' => $tail,
                'project' => $project,
            ],
            'error' => null,
        ];
    }

    public function peek(array $params, array $state): string
    {
        $ssh = $this->getSsh($params);

        $cmd = "docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null";

        if ($ssh) {
            $result = $ssh->run($cmd, 10);
        } else {
            $result = Process::timeout(10)->run($cmd);
        }

        if ($result->failed()) {
            $target = $ssh ? " ({$ssh->getLabel()})" : '';
            return "## Docker Containers{$target}\n\nDocker not available or no containers running.";
        }

        $header = $ssh
            ? "## Docker Containers (SSH: {$ssh->getLabel()})\n\n"
            : "## Docker Containers\n\n";

        return $header . "```\n" . trim($result->output()) . "\n```";
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Opens an interactive Docker Containers panel showing all running and stopped containers.

## What It Shows
- Container name, image, and status (running/stopped/restarting)
- Health check status (healthy/unhealthy/starting) if configured
- Port mappings
- Grouped by Docker Compose project

## Features
- Click on a project row to expand/collapse container details
- Start/Stop/Restart controls for each Compose project (not available for PocketDev containers)
- **View Logs** for entire project or individual containers (available for all, including PocketDev)
- Auto-refresh toggle (updates every 5 seconds)
- Manual refresh button

## Log Viewer
- Click the menu (⋮) on any project and select "View Logs" to see combined logs
- Click the log icon on individual containers for container-specific logs
- "Load More" button fetches additional log history (up to 1000 lines)
- Copy button to copy logs to clipboard

## SSH Remote Mode
Connect to a remote Docker host via SSH by passing connection parameters:
- `ssh_host` (required for SSH): Remote hostname or IP
- `ssh_user` (default: root): SSH username
- `ssh_port` (default: 22): SSH port
- `ssh_password`: Password for SSH auth (omit for key-based auth)
- `ssh_key_path`: Path to private key (default: tries ~/.ssh/id_rsa, id_ed25519)

When connected via SSH, PocketDev container protections are disabled (they only apply locally).

## CLI Example
```bash
# Local Docker
pd tool:run docker-containers

# Remote Docker via SSH (password)
pd tool:run docker-containers -- --ssh_host=192.168.1.100 --ssh_user=root --ssh_password=secret

# Remote Docker via SSH (key-based)
pd tool:run docker-containers -- --ssh_host=192.168.1.100 --ssh_user=deploy
```

## When to Use
- Checking container status and health
- Starting/stopping Docker Compose projects
- Viewing port mappings and container details
- Debugging container issues via logs
- Managing Docker on remote servers via SSH

Use `pd panel:peek docker-containers` to see current state.
PROMPT;
    }
}
