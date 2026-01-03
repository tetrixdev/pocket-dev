<?php

namespace App\Tools;

use App\Models\Agent;
use App\Models\MemoryDatabase;
use App\Models\Workspace;

class ExecutionContext
{
    public function __construct(
        public string $workingDirectory,
        public ?Workspace $workspace = null,
        public ?MemoryDatabase $memoryDatabase = null,
        public ?Agent $agent = null,
    ) {}

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    /**
     * Get the agent for this context.
     */
    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    /**
     * Get the workspace for this context.
     * Falls back to agent's workspace if not explicitly set.
     */
    public function getWorkspace(): ?Workspace
    {
        if ($this->workspace !== null) {
            return $this->workspace;
        }

        // Try to get from agent
        if ($this->agent !== null) {
            return $this->agent->workspace;
        }

        return null;
    }

    /**
     * Get the memory database to use for this context.
     * Falls back to workspace's default memory database if not explicitly set.
     */
    public function getMemoryDatabase(): ?MemoryDatabase
    {
        if ($this->memoryDatabase !== null) {
            return $this->memoryDatabase;
        }

        // Try to get default from workspace
        $workspace = $this->getWorkspace();
        if ($workspace !== null) {
            return $workspace->getDefaultMemoryDatabase();
        }

        return null;
    }

    /**
     * Resolve a path relative to the working directory.
     * If the path is absolute, return it as-is.
     * If relative, prepend the working directory.
     */
    public function resolvePath(string $path): string
    {
        // Already absolute
        if (str_starts_with($path, '/')) {
            return $path;
        }

        // Relative path - prepend working directory
        return rtrim($this->workingDirectory, '/') . '/' . $path;
    }

    /**
     * Check if a path is within the working directory.
     * Used for security checks on existing files.
     *
     * @deprecated This method and the entire Tools system will be replaced in a future update.
     *             Currently disabled to allow dogfooding access to /pocketdev-source.
     *             Will be cleaned up before production release.
     */
    public function isPathAllowed(string $path): bool
    {
        return true;
    }

    /**
     * Check if a path structure is within the working directory.
     * Used for security checks on paths that may not exist yet (e.g., new files).
     *
     * @deprecated This method and the entire Tools system will be replaced in a future update.
     *             Currently disabled to allow dogfooding access to /pocketdev-source.
     *             Will be cleaned up before production release.
     */
    public function isPathStructureAllowed(string $resolvedPath): bool
    {
        return true;
    }

    /**
     * Normalize a path by resolving . and .. segments without requiring the path to exist.
     */
    private function normalizePath(string $path): string
    {
        $parts = explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        return '/' . implode('/', $normalized);
    }
}
