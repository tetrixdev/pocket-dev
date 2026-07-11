<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VersionService
{
    private const CACHE_KEY_LATEST_RELEASE = 'pocketdev:latest_release';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Check if running in local development mode.
     */
    public function isLocalEnvironment(): bool
    {
        return app()->environment('local');
    }

    /**
     * Get current version information.
     */
    public function getCurrentVersion(): array
    {
        if ($this->isLocalEnvironment()) {
            return $this->getLocalVersion();
        }

        return $this->getProductionVersion();
    }

    /**
     * Get version info for local development (git-based).
     */
    private function getLocalVersion(): array
    {
        $projectPath = $this->getProjectPath();

        if (!$projectPath || !is_dir($projectPath . '/.git')) {
            return [
                'mode' => 'local',
                'branch' => null,
                'commit' => null,
                'error' => 'Git repository not found',
            ];
        }

        // Get current branch
        $branch = trim(shell_exec("cd {$projectPath} && git branch --show-current 2>/dev/null") ?? '');

        // Get current commit hash
        $commit = trim(shell_exec("cd {$projectPath} && git rev-parse --short HEAD 2>/dev/null") ?? '');

        // Check for uncommitted changes
        $status = trim(shell_exec("cd {$projectPath} && git status --porcelain 2>/dev/null") ?? '');
        $hasChanges = !empty($status);

        return [
            'mode' => 'local',
            'branch' => $branch ?: null,
            'commit' => $commit ?: null,
            'has_changes' => $hasChanges,
        ];
    }

    /**
     * Get version info for production (version.json-based).
     */
    private function getProductionVersion(): array
    {
        $versionFile = base_path('version.json');

        if (!file_exists($versionFile)) {
            return [
                'mode' => 'production',
                'tag' => null,
                'commit' => null,
                'build_date' => null,
                'error' => 'version.json not found (pre-v0.50.10 build)',
            ];
        }

        $data = json_decode(file_get_contents($versionFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'mode' => 'production',
                'tag' => null,
                'commit' => null,
                'build_date' => null,
                'error' => 'Invalid version.json format: ' . json_last_error_msg(),
            ];
        }

        return [
            'mode' => 'production',
            'tag' => $data['tag'] ?? null,
            'commit' => $data['commit'] ?? null,
            'build_date' => $data['build_date'] ?? null,
            'prerelease' => $data['prerelease'] ?? false,
        ];
    }

    /**
     * Check for available updates.
     */
    public function checkForUpdates(bool $forceRefresh = false): array
    {
        $current = $this->getCurrentVersion();

        if ($this->isLocalEnvironment()) {
            return $this->checkLocalUpdates($current, $forceRefresh);
        }

        return $this->checkProductionUpdates($current, $forceRefresh);
    }

    /**
     * Check for updates in local development (git-based).
     */
    private function checkLocalUpdates(array $current, bool $forceRefresh = false): array
    {
        $projectPath = $this->getProjectPath();

        if (!$projectPath || !is_dir($projectPath . '/.git')) {
            return [
                'update_available' => false,
                'current' => $current,
                'error' => 'Git repository not found',
            ];
        }

        // Fetch latest from origin
        if ($forceRefresh) {
            shell_exec("cd {$projectPath} && git fetch origin main 2>/dev/null");
        }

        $branch = $current['branch'] ?? '';

        // Count commits behind origin/main
        $behindCount = 0;
        if ($branch === 'main') {
            $behindCount = (int) trim(shell_exec("cd {$projectPath} && git rev-list HEAD..origin/main --count 2>/dev/null") ?? '0');
        }

        // Check if on a feature branch
        $isFeatureBranch = $branch !== 'main' && $branch !== '';

        return [
            'update_available' => $behindCount > 0,
            'current' => $current,
            'commits_behind' => $behindCount,
            'is_feature_branch' => $isFeatureBranch,
            'can_auto_update' => $branch === 'main' && !($current['has_changes'] ?? false),
        ];
    }

    /**
     * Check for updates in production (GitHub releases).
     */
    private function checkProductionUpdates(array $current, bool $forceRefresh = false): array
    {
        $latest = $this->getLatestRelease($forceRefresh);

        if (!$latest) {
            return [
                'update_available' => false,
                'current' => $current,
                'error' => 'Could not fetch latest release',
            ];
        }

        $currentTag = $current['tag'] ?? null;
        $latestTag = $latest['tag'] ?? null;

        // Compare versions
        $updateAvailable = $currentTag !== $latestTag && $latestTag !== null;

        return [
            'update_available' => $updateAvailable,
            'current' => $current,
            'latest' => $latest,
        ];
    }

    /**
     * Get latest release from GitHub API.
     */
    public function getLatestRelease(bool $forceRefresh = false): ?array
    {
        if (!$forceRefresh) {
            $cached = Cache::get(self::CACHE_KEY_LATEST_RELEASE);
            if ($cached) {
                return $cached;
            }
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'PocketDev',
            ])->timeout(10)->get('https://api.github.com/repos/tetrixdev/pocket-dev/releases/latest');

            if (!$response->successful()) {
                Log::warning('Failed to fetch latest PocketDev release', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();

            $result = [
                'tag' => $data['tag_name'] ?? null,
                'name' => $data['name'] ?? null,
                'published_at' => $data['published_at'] ?? null,
                'prerelease' => $data['prerelease'] ?? false,
                'html_url' => $data['html_url'] ?? null,
                'body' => $data['body'] ?? null,
            ];

            Cache::put(self::CACHE_KEY_LATEST_RELEASE, $result, self::CACHE_TTL);

            return $result;
        } catch (\Exception $e) {
            Log::error('Error fetching latest PocketDev release', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Pull latest changes from main branch (local only).
     */
    public function pullFromMain(): array
    {
        if (!$this->isLocalEnvironment()) {
            return ['success' => false, 'error' => 'Only available in local environment'];
        }

        $projectPath = $this->getProjectPath();

        if (!$projectPath) {
            return ['success' => false, 'error' => 'Project path not found'];
        }

        // Check current branch
        $branch = trim(shell_exec("cd {$projectPath} && git branch --show-current 2>/dev/null") ?? '');

        if ($branch !== 'main') {
            return [
                'success' => false,
                'error' => "Cannot auto-update: currently on branch '{$branch}'. Switch to main first.",
            ];
        }

        // Check for uncommitted changes
        $status = trim(shell_exec("cd {$projectPath} && git status --porcelain 2>/dev/null") ?? '');
        if (!empty($status)) {
            return [
                'success' => false,
                'error' => 'Cannot auto-update: uncommitted changes present.',
            ];
        }

        // Pull from main and capture exit code
        $output = [];
        $exitCode = 0;
        exec("cd {$projectPath} && git pull origin main 2>&1", $output, $exitCode);
        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'error' => 'Git pull failed: ' . $outputStr,
            ];
        }

        return [
            'success' => true,
            'output' => $outputStr,
        ];
    }

    /**
     * Get a short version label for display (branch name or version tag).
     */
    public function getVersionLabel(): string
    {
        $version = $this->getCurrentVersion();

        if ($this->isLocalEnvironment()) {
            $label = $version['branch'] ?? 'unknown';
            if ($version['has_changes'] ?? false) {
                $label .= '*';
            }
            return $label;
        }

        return $version['tag'] ?? 'unknown';
    }

    /**
     * Check if update is available (cached, for badge display).
     */
    public function isUpdateAvailable(): bool
    {
        $cacheKey = 'pocketdev:update_available';

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $result = $this->checkForUpdates();
            return $result['update_available'] ?? false;
        });
    }

    /**
     * Get the host project path for git operations.
     */
    private function getProjectPath(): ?string
    {
        // For local development, use the mounted pocketdev-source
        if (is_dir('/pocketdev-source/.git')) {
            return '/pocketdev-source';
        }

        // Fallback to host project path from environment
        $hostPath = env('PD_HOST_PROJECT_PATH');
        if ($hostPath && is_dir($hostPath . '/.git')) {
            return $hostPath;
        }

        return null;
    }
}
