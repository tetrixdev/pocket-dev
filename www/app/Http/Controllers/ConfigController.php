<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ConfigController extends Controller
{
    /**
     * Configuration registry - defines all editable configs
     */
    protected function getConfigs(): array
    {
        return [
            'claude' => [
                'title' => 'CLAUDE.md',
                'local_path' => '/home/appuser/.claude/CLAUDE.md',
                'container' => 'pocket-dev-php',
                'container_path' => '/home/appuser/.claude/CLAUDE.md',
                'syntax' => 'markdown',
                'validate' => false,
                'reload_cmd' => null,
            ],
            'settings' => [
                'title' => 'Claude Settings',
                'local_path' => '/home/appuser/.claude/settings.json',
                'container' => 'pocket-dev-php',
                'container_path' => '/home/appuser/.claude/settings.json',
                'syntax' => 'json',
                'validate' => false,
                'reload_cmd' => null,
            ],
            'nginx' => [
                'title' => 'Nginx Proxy Config',
                'local_path' => '/etc/nginx-proxy-config/nginx.conf.template',
                'container' => 'pocket-dev-proxy',
                'container_path' => '/etc/nginx-proxy-config/nginx.conf.template',
                'syntax' => 'nginx',
                'validate' => true,
                'reload_cmd' => 'sh -c "envsubst \'\$IP_ALLOWED \$AUTH_ENABLED \$DEFAULT_SERVER \$DOMAIN_NAME\' < /etc/nginx-proxy-config/nginx.conf.template > /etc/nginx/nginx.conf && nginx -s reload"',
            ],
        ];
    }

    /**
     * Display the config editor page
     */
    public function index()
    {
        $configs = $this->getConfigs();

        return view('config.index', [
            'configs' => $configs,
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * Read a specific config file
     */
    public function read(string $id): JsonResponse
    {
        $configs = $this->getConfigs();

        if (!isset($configs[$id])) {
            return response()->json(['error' => 'Config not found'], 404);
        }

        $config = $configs[$id];

        try {
            $content = $this->readFromLocalPath($config['local_path']);

            return response()->json([
                'success' => true,
                'content' => $content,
                'config' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to read config {$id}", [
                'error' => $e->getMessage(),
                'local_path' => $config['local_path'],
            ]);

            return response()->json([
                'error' => 'Failed to read config: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save a specific config file
     */
    public function save(Request $request, string $id): JsonResponse
    {
        $configs = $this->getConfigs();

        if (!isset($configs[$id])) {
            return response()->json(['error' => 'Config not found'], 404);
        }

        $config = $configs[$id];
        $content = $request->input('content');

        if ($content === null) {
            return response()->json(['error' => 'Content is required'], 400);
        }

        try {
            // Validate if needed (nginx only for now)
            if ($config['validate']) {
                $this->validateNginxConfig($content);
            }

            // Save the config to local mounted path
            $this->writeToLocalPath($config['local_path'], $content);

            // Reload if needed (this still requires docker exec)
            if ($config['reload_cmd']) {
                $this->execInContainer($config['container'], $config['reload_cmd']);
            }

            return response()->json([
                'success' => true,
                'message' => "{$config['title']} saved successfully" .
                            ($config['reload_cmd'] ? ' and service reloaded' : ''),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to save config {$id}", [
                'error' => $e->getMessage(),
                'local_path' => $config['local_path'],
            ]);

            return response()->json([
                'error' => 'Failed to save config: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Read file from local mounted path
     */
    protected function readFromLocalPath(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }

        return $content;
    }

    /**
     * Write file to local mounted path
     */
    protected function writeToLocalPath(string $path, string $content): void
    {
        $result = file_put_contents($path, $content);

        if ($result === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }

    /**
     * Execute command in docker container
     */
    protected function execInContainer(string $container, string $command): void
    {
        // Run docker directly - www-data user is in hostdocker group (GID 1001)
        // which grants access to the docker socket
        $fullCommand = "docker exec {$container} {$command} 2>&1";

        exec($fullCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Command failed: ' . implode("\n", $output));
        }
    }

    /**
     * Validate nginx configuration
     */
    protected function validateNginxConfig(string $content): void
    {
        // For now, just do basic syntax checking
        // In the future, could write to temp file and run nginx -t

        if (empty(trim($content))) {
            throw new \RuntimeException('Nginx config cannot be empty');
        }

        // Basic validation: check for required blocks
        if (!str_contains($content, 'http {') && !str_contains($content, 'events {')) {
            throw new \RuntimeException('Invalid nginx config: missing required blocks');
        }
    }
}
