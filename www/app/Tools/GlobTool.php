<?php

namespace App\Tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Find files matching glob patterns.
 */
class GlobTool extends Tool
{
    public string $name = 'Glob';

    public string $description = 'Find files matching a glob pattern. Returns file paths sorted by modification time (newest first).';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'pattern' => [
                'type' => 'string',
                'description' => 'Glob pattern (e.g., "**/*.php", "src/**/*.ts", "*.json")',
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Base directory to search from. Defaults to working directory.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of files to return. Default: 100',
            ],
        ],
        'required' => ['pattern'],
    ];

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $pattern = $input['pattern'] ?? '';
        $path = $input['path'] ?? null;
        $limit = min(1000, max(1, $input['limit'] ?? 100));

        if (empty($pattern)) {
            return ToolResult::error('pattern is required');
        }

        $basePath = $path !== null
            ? $context->resolvePath($path)
            : $context->getWorkingDirectory();

        if (!is_dir($basePath)) {
            return ToolResult::error("Directory not found: {$basePath}");
        }

        // Convert glob pattern to regex
        $regex = $this->globToRegex($pattern);

        try {
            $files = $this->findFiles($basePath, $regex);
        } catch (\Throwable $e) {
            return ToolResult::error("Search failed: {$e->getMessage()}");
        }

        if (empty($files)) {
            return ToolResult::success('No files found matching pattern');
        }

        // Sort by modification time (newest first)
        usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        // Apply limit
        $files = array_slice($files, 0, $limit);

        // Format output - show relative paths
        $output = [];
        foreach ($files as $file) {
            $relativePath = $this->getRelativePath($file['path'], $basePath);
            $output[] = $relativePath;
        }

        $result = implode("\n", $output);

        if (count($files) === $limit) {
            $result .= "\n\n[Limited to {$limit} results]";
        }

        return ToolResult::success($result);
    }

    /**
     * Convert glob pattern to regex.
     */
    private function globToRegex(string $pattern): string
    {
        // Handle ** (matches any depth)
        // Handle * (matches within directory)
        // Handle ? (matches single char)

        $regex = preg_quote($pattern, '#');

        // ** matches any path segment (including /)
        $regex = str_replace('\\*\\*', '.*', $regex);

        // * matches anything except /
        $regex = str_replace('\\*', '[^/]*', $regex);

        // ? matches single char except /
        $regex = str_replace('\\?', '[^/]', $regex);

        return '#' . $regex . '$#';
    }

    /**
     * Find files matching the regex pattern.
     */
    private function findFiles(string $basePath, string $regex): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $basePath,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            // Skip directories
            if ($file->isDir()) {
                continue;
            }

            // Get path relative to base for matching
            $relativePath = $this->getRelativePath($file->getPathname(), $basePath);

            // Check if matches pattern
            if (preg_match($regex, $relativePath)) {
                $files[] = [
                    'path' => $file->getPathname(),
                    'mtime' => $file->getMTime(),
                ];
            }

            // Safety limit to prevent hanging on huge directories
            if (count($files) >= 10000) {
                break;
            }
        }

        return $files;
    }

    /**
     * Get relative path from base path.
     */
    private function getRelativePath(string $fullPath, string $basePath): string
    {
        $basePath = rtrim($basePath, '/') . '/';

        if (str_starts_with($fullPath, $basePath)) {
            return substr($fullPath, strlen($basePath));
        }

        return $fullPath;
    }
}
