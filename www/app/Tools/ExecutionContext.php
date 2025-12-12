<?php

namespace App\Tools;

class ExecutionContext
{
    public function __construct(
        public string $workingDirectory,
    ) {}

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
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
     */
    public function isPathAllowed(string $path): bool
    {
        $realPath = realpath($path);
        $realWorkDir = realpath($this->workingDirectory);

        if ($realPath === false || $realWorkDir === false) {
            return false;
        }

        return str_starts_with($realPath, $realWorkDir . '/')
            || $realPath === $realWorkDir;
    }

    /**
     * Check if a path structure is within the working directory.
     * Used for security checks on paths that may not exist yet (e.g., new files).
     *
     * This validates the resolved path starts with the working directory,
     * preventing path traversal attacks (../) and absolute path escapes.
     */
    public function isPathStructureAllowed(string $resolvedPath): bool
    {
        $realWorkDir = realpath($this->workingDirectory);

        if ($realWorkDir === false) {
            return false;
        }

        // Normalize the resolved path to handle .. and . segments
        $normalizedPath = $this->normalizePath($resolvedPath);

        // Check if normalized path starts with working directory
        return str_starts_with($normalizedPath, $realWorkDir . '/')
            || $normalizedPath === $realWorkDir;
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
