<?php

namespace App\Panels;

use Illuminate\Support\Facades\File;

class FileExplorerPanel extends Panel
{
    public string $slug = 'file-explorer';
    public string $name = 'File Explorer';
    public string $description = 'Browse files and directories interactively';
    public string $icon = 'fa-solid fa-folder-tree';

    public array $parameters = [
        'path' => [
            'type' => 'string',
            'description' => 'Root directory to explore',
            'default' => '/workspace/default',
        ],
    ];

    /**
     * Validate that a path is within allowed directories.
     *
     * @param string $path The path to validate
     * @return string|null The real path if valid, null otherwise
     */
    private function validatePath(string $path): ?string
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            return null;
        }

        $allowedPrefixes = ['/workspace/', '/pocketdev-source/', '/home/appuser/', '/tmp/'];
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($realPath, $prefix) || $realPath === rtrim($prefix, '/')) {
                return $realPath;
            }
        }

        return null;
    }

    public function render(array $params, array $state, ?string $panelStateId = null): string
    {
        $rootPath = $params['path'] ?? '/workspace/default';

        // Validate root path is within allowed directories
        $validatedPath = $this->validatePath($rootPath);
        if ($validatedPath === null) {
            return view('panels.file-explorer', [
                'rootPath' => $rootPath,
                'tree' => [],
                'expanded' => [],
                'selected' => null,
                'loadedPaths' => [],
                'panelStateId' => $panelStateId,
                'error' => 'Access denied: path not within allowed directories',
            ])->render();
        }
        $rootPath = $validatedPath;

        $expanded = $state['expanded'] ?? [];
        $selected = $state['selected'] ?? null;
        $loadedPaths = $state['loadedPaths'] ?? [];

        // Root path is always loaded (first level is rendered on page load)
        if (!in_array($rootPath, $loadedPaths)) {
            $loadedPaths[] = $rootPath;
        }

        // Ensure all expanded paths are also in loadedPaths so their children render
        foreach ($expanded as $expandedPath) {
            if (!in_array($expandedPath, $loadedPaths)) {
                $loadedPaths[] = $expandedPath;
            }
        }

        // Build the file tree - only first level + expanded paths
        // Children are lazy-loaded via actions
        $tree = $this->buildTreeLazy($rootPath, $expanded, $loadedPaths);

        return view('panels.file-explorer', [
            'rootPath' => $rootPath,
            'tree' => $tree,
            'expanded' => $expanded,
            'selected' => $selected,
            'loadedPaths' => $loadedPaths,
            'panelStateId' => $panelStateId,
        ])->render();
    }

    /**
     * Handle panel actions for lazy loading.
     */
    public function handleAction(string $action, array $params, array $state, array $panelParams = []): array
    {
        if ($action === 'loadChildren') {
            $path = $params['path'] ?? '';
            $depth = $params['depth'] ?? 1;

            // Get root path from panel parameters or state
            $rootPath = $panelParams['path'] ?? $state['rootPath'] ?? '/workspace/default';

            // Validate path is within allowed directories
            $realPath = $this->validatePath($path);
            if ($realPath === null) {
                return ['error' => 'Access denied: path not within allowed directories'];
            }

            // Validate root path as well
            $realRoot = $this->validatePath($rootPath);
            if ($realRoot === null) {
                return ['error' => 'Access denied: root path not within allowed directories'];
            }

            // Ensure requested path is within the configured root
            if (!str_starts_with($realPath . '/', $realRoot . '/') && $realPath !== $realRoot) {
                return ['error' => 'Access denied: path outside allowed root'];
            }

            if (!File::isDirectory($realPath)) {
                return ['error' => 'Invalid path'];
            }

            // Build children for this path (use validated real path)
            $children = $this->buildDirectoryContents($realPath);

            // Render the children HTML
            $html = view('panels.partials.file-tree-children', [
                'children' => $children,
                'depth' => $depth,
            ])->render();

            // Track loaded paths in state (use validated real path)
            $loadedPaths = $state['loadedPaths'] ?? [];
            if (!in_array($realPath, $loadedPaths)) {
                $loadedPaths[] = $realPath;
            }

            return [
                'html' => $html,
                'state' => ['loadedPaths' => $loadedPaths],
                'data' => null,
                'error' => null,
            ];
        }

        return parent::handleAction($action, $params, $state, $panelParams);
    }

    public function peek(array $params, array $state): string
    {
        $rootPath = $params['path'] ?? '/workspace/default';
        $expanded = $state['expanded'] ?? [];

        // Validate path is within allowed directories
        $validatedPath = $this->validatePath($rootPath);
        if ($validatedPath === null) {
            return "## File Explorer: Error\n\nAccess denied: path not within allowed directories: {$rootPath}";
        }
        $rootPath = $validatedPath;

        $output = "## File Explorer: {$rootPath}\n\n";
        $output .= $this->buildPeekTree($rootPath, $expanded, 0);

        // Count visible items
        $counts = $this->countVisible($rootPath, $expanded);
        $output .= "\n*{$counts['total']} items visible ({$counts['dirs']} directories, {$counts['files']} files)*";

        return $output;
    }

    /**
     * Build tree lazily - only renders first level + already loaded paths.
     * Children are loaded on-demand via actions.
     */
    protected function buildTreeLazy(string $path, array $expanded, array $loadedPaths): array
    {
        if (!File::isDirectory($path)) {
            return [];
        }

        $items = [];

        try {
            // Directories first
            $entries = File::directories($path);
            sort($entries);

            foreach ($entries as $dir) {
                $name = basename($dir);
                if (str_starts_with($name, '.')) continue;

                $isExpanded = in_array($dir, $expanded);
                $isLoaded = in_array($dir, $loadedPaths);

                // Only include children if this path has been loaded
                $children = [];
                if ($isLoaded) {
                    $children = $this->buildTreeLazy($dir, $expanded, $loadedPaths);
                }

                $items[] = [
                    'type' => 'directory',
                    'name' => $name,
                    'path' => $dir,
                    'expanded' => $isExpanded,
                    'loaded' => $isLoaded,
                    'children' => $children,
                ];
            }

            // Then files
            $files = File::files($path);
            usort($files, fn($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

            foreach ($files as $file) {
                $name = $file->getFilename();
                if (str_starts_with($name, '.')) continue;

                $items[] = [
                    'type' => 'file',
                    'name' => $name,
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'extension' => $file->getExtension(),
                ];
            }
        } catch (\Exception $e) {
            // Permission denied or other error
        }

        return $items;
    }

    /**
     * Build contents for a single directory (used by loadChildren action).
     */
    protected function buildDirectoryContents(string $path): array
    {
        if (!File::isDirectory($path)) {
            return [];
        }

        $items = [];

        try {
            // Directories first
            $entries = File::directories($path);
            sort($entries);

            foreach ($entries as $dir) {
                $name = basename($dir);
                if (str_starts_with($name, '.')) continue;

                $items[] = [
                    'type' => 'directory',
                    'name' => $name,
                    'path' => $dir,
                    'expanded' => false,
                    'loaded' => false,
                    'children' => [],
                ];
            }

            // Then files
            $files = File::files($path);
            usort($files, fn($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

            foreach ($files as $file) {
                $name = $file->getFilename();
                if (str_starts_with($name, '.')) continue;

                $items[] = [
                    'type' => 'file',
                    'name' => $name,
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'extension' => $file->getExtension(),
                ];
            }
        } catch (\Exception $e) {
            // Permission denied or other error
        }

        return $items;
    }

    protected function buildPeekTree(string $path, array $expanded, int $indent): string
    {
        if (!File::isDirectory($path)) {
            return '';
        }

        $output = '';
        $prefix = str_repeat('   ', $indent);

        try {
            // Directories first
            $dirs = File::directories($path);
            sort($dirs);

            foreach ($dirs as $dir) {
                $name = basename($dir);
                if (str_starts_with($name, '.')) continue;

                $isExpanded = in_array($dir, $expanded);
                $marker = $isExpanded ? '(expanded)' : '(collapsed)';
                $output .= "{$prefix}[DIR] {$name}/ {$marker}\n";

                if ($isExpanded) {
                    $output .= $this->buildPeekTree($dir, $expanded, $indent + 1);
                }
            }

            // Then files
            $files = File::files($path);
            foreach ($files as $file) {
                $name = $file->getFilename();
                if (str_starts_with($name, '.')) continue;

                $size = $this->formatSize($file->getSize());
                $output .= "{$prefix}[FILE] {$name} ({$size})\n";
            }
        } catch (\Exception $e) {
            $output .= "{$prefix}[!] Cannot read directory\n";
        }

        return $output;
    }

    protected function countVisible(string $path, array $expanded): array
    {
        $counts = ['dirs' => 0, 'files' => 0, 'total' => 0];

        if (!File::isDirectory($path)) {
            return $counts;
        }

        try {
            $dirs = File::directories($path);
            foreach ($dirs as $dir) {
                if (str_starts_with(basename($dir), '.')) continue;
                $counts['dirs']++;
                $counts['total']++;

                if (in_array($dir, $expanded)) {
                    $subCounts = $this->countVisible($dir, $expanded);
                    $counts['dirs'] += $subCounts['dirs'];
                    $counts['files'] += $subCounts['files'];
                    $counts['total'] += $subCounts['total'];
                }
            }

            $files = File::files($path);
            foreach ($files as $file) {
                if (str_starts_with($file->getFilename(), '.')) continue;
                $counts['files']++;
                $counts['total']++;
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $counts;
    }

    protected function formatSize(int $bytes): string
    {
        return self::formatSizeStatic($bytes);
    }

    public static function formatSizeStatic(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Opens an interactive file explorer panel.

## CLI Example
```bash
pd tool:run file-explorer -- --path=/workspace/default
```

## Parameters
- path: Root directory to explore (default: /workspace/default)

## What You See
When you open this panel, the user sees an interactive file tree. You receive a peek showing:
- Directories marked [DIR] with (expanded) or (collapsed)
- Files marked [FILE] with their sizes
- Only visible items (collapsed folders hide their contents)

Use `pd panel:peek file-explorer` to see current state after user navigates.
PROMPT;
    }
}
