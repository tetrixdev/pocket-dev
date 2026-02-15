<?php

namespace App\Panels;

use Illuminate\Support\Facades\Process;

class DockerContainersPanel extends Panel
{
    public string $slug = 'docker-containers';
    public string $name = 'Docker Containers';
    public string $description = 'View and manage Docker containers grouped by Compose project';
    public string $icon = 'fa-brands fa-docker';
    public string $category = 'development';

    public array $parameters = [];

    public function render(array $params, array $state, ?string $panelStateId = null): string
    {
        return view('panels.docker-containers', [
            'state' => $state,
            'panelStateId' => $panelStateId,
        ])->render();
    }

    /**
     * Handle panel actions for container management.
     */
    public function handleAction(string $action, array $params, array $state, array $panelParams = []): array
    {
        return match ($action) {
            'refresh' => $this->getContainers(),
            'start' => $this->startProject($params),
            'stop' => $this->stopProject($params),
            'restart' => $this->restartProject($params),
            'logs' => $this->getLogs($params),
            default => parent::handleAction($action, $params, $state, $panelParams),
        };
    }

    /**
     * Get all containers with their status and metadata.
     */
    protected function getContainers(): array
    {
        $format = '{{.ID}}~{{.Names}}~{{.Image}}~{{.Status}}~{{.CreatedAt}}~{{.Ports}}~{{.Networks}}~{{.Mounts}}~{{.Label "com.docker.compose.project"}}~{{.Label "com.docker.compose.project.working_dir"}}~{{.Label "com.docker.compose.service"}}';

        $result = Process::timeout(30)->run("docker ps -a --format '{$format}' 2>/dev/null");

        if ($result->failed()) {
            return ['error' => 'Failed to get container list'];
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
    protected function startProject(array $params): array
    {
        $project = $params['project'] ?? '';
        $workingDir = $params['working_dir'] ?? '';

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

        // Start containers
        $result = Process::timeout(120)
            ->path($workingDir)
            ->env(['HOME' => '/tmp', 'PATH' => getenv('PATH')])
            ->run('/usr/libexec/docker/cli-plugins/docker-compose up -d --force-recreate 2>&1');

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
    protected function stopProject(array $params): array
    {
        $project = $params['project'] ?? '';
        $workingDir = $params['working_dir'] ?? '';

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
    protected function restartProject(array $params): array
    {
        $project = $params['project'] ?? '';
        $workingDir = $params['working_dir'] ?? '';

        if (str_starts_with($project, 'pocket-dev')) {
            return ['error' => 'Cannot modify PocketDev containers'];
        }

        if (empty($workingDir) || !is_dir($workingDir)) {
            return ['error' => "Working directory not found: '{$workingDir}'"];
        }

        // Stop first
        $stopResult = Process::timeout(60)
            ->path($workingDir)
            ->env(['HOME' => '/tmp', 'PATH' => getenv('PATH')])
            ->run('/usr/libexec/docker/cli-plugins/docker-compose stop 2>&1');

        // Then start with force-recreate
        $startResult = $this->startProject($params);

        // If stop failed but start succeeded, warn the user
        if ($stopResult->failed() && ($startResult['data']['success'] ?? false)) {
            $startResult['data']['message'] = 'Warning: Stop failed, but start succeeded';
        }

        return $startResult;
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
    protected function getLogs(array $params): array
    {
        $project = $params['project'] ?? '';
        $container = $params['container'] ?? null;
        $workingDir = $params['working_dir'] ?? '';
        $tail = max(1, min((int) ($params['tail'] ?? 100), 1000)); // Default 100, min 1, max 1000 per request

        // For specific container, use docker logs directly
        if ($container) {
            $result = Process::timeout(30)->run(
                "docker logs " . escapeshellarg($container) . " --tail {$tail} 2>&1"
            );

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

        // For project logs, use docker compose
        if (empty($workingDir) || !is_dir($workingDir)) {
            // Fallback: get all containers for this project and combine logs
            $format = '{{.Names}}';
            $filter = escapeshellarg("label=com.docker.compose.project={$project}");
            $psResult = Process::timeout(10)->run(
                "docker ps -a --filter {$filter} --format " . escapeshellarg($format) . " 2>/dev/null"
            );

            if ($psResult->failed() || empty(trim($psResult->output()))) {
                return ['error' => "No containers found for project: {$project}"];
            }

            $containers = array_filter(explode("\n", trim($psResult->output())));
            $allLogs = [];

            foreach ($containers as $containerName) {
                $logResult = Process::timeout(15)->run(
                    "docker logs " . escapeshellarg($containerName) . " --tail " . ceil($tail / count($containers)) . " 2>&1"
                );
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

    public function peek(array $params, array $state): string
    {
        $result = Process::timeout(10)->run("docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null");

        if ($result->failed()) {
            return "## Docker Containers\n\nDocker not available or no containers running.";
        }

        $output = "## Docker Containers\n\n```\n" . trim($result->output()) . "\n```";

        return $output;
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

## CLI Example
```bash
pd tool:run docker-containers
```

## When to Use
- Checking container status and health
- Starting/stopping Docker Compose projects
- Viewing port mappings and container details
- Debugging container issues via logs

Use `pd panel:peek docker-containers` to see current state.
PROMPT;
    }
}
