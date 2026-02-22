<?php

namespace App\Support;

/**
 * Validates that paths are within allowed directories.
 *
 * Used for security to prevent path traversal attacks and restrict
 * file operations to specific directories.
 */
class PathValidator
{
    /**
     * Validate that a path is within allowed directories.
     *
     * @param string $path The path to validate
     * @return string|null The resolved real path if valid, null otherwise
     */
    public static function validate(string $path): ?string
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            return null;
        }

        $allowedPrefixes = config('pocketdev.allowed_paths', []);

        foreach ($allowedPrefixes as $prefix) {
            // Match paths within the directory OR the directory itself (exact match)
            if (str_starts_with($realPath, $prefix) || $realPath === rtrim($prefix, '/')) {
                return $realPath;
            }
        }

        return null;
    }

    /**
     * Check if a path is valid without returning the resolved path.
     *
     * @param string $path The path to check
     * @return bool True if path is within allowed directories
     */
    public static function isValid(string $path): bool
    {
        return self::validate($path) !== null;
    }

    /**
     * Get the list of allowed path prefixes.
     *
     * @return array<string>
     */
    public static function getAllowedPrefixes(): array
    {
        return config('pocketdev.allowed_paths', []);
    }
}
