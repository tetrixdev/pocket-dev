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
     * Used for security checks.
     */
    public function isPathAllowed(string $path): bool
    {
        $realPath = realpath($path);
        $realWorkDir = realpath($this->workingDirectory);

        if ($realPath === false || $realWorkDir === false) {
            return false;
        }

        return str_starts_with($realPath, $realWorkDir);
    }
}
