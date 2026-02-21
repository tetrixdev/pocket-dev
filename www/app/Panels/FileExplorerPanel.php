<?php

namespace App\Panels;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FileExplorerPanel extends Panel
{
    public string $slug = 'file-explorer';
    public string $name = 'File Explorer';
    public string $description = 'Browse files and directories interactively';
    public string $icon = 'fa-solid fa-folder-tree';
    public string $category = 'files';

    public array $parameters = [
        'path' => [
            'type' => 'string',
            'description' => 'Root directory to explore',
            'default' => '/workspace/default',
        ],
    ];

    /**
     * Image file extensions (displayed as inline preview).
     */
    protected const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'bmp'];

    /**
     * Binary file extensions (not displayed, just show placeholder).
     */
    protected const BINARY_EXTENSIONS = [
        'zip', 'tar', 'gz', 'bz2', 'xz', '7z', 'rar',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'exe', 'bin', 'so', 'dll', 'dylib',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'mp3', 'mp4', 'avi', 'mov', 'mkv', 'wav', 'flac', 'ogg',
        'sqlite', 'db',
        'o', 'a', 'class', 'pyc', 'pyo',
    ];

    /**
     * Max text file size to display (512 KB).
     */
    protected const MAX_TEXT_SIZE = 512 * 1024;

    /**
     * Max image file size to display (5 MB).
     */
    protected const MAX_IMAGE_SIZE = 5 * 1024 * 1024;

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
                'viewingFile' => null,
                'settings' => null,
                'error' => 'Access denied: path not within allowed directories',
            ])->render();
        }
        $rootPath = $validatedPath;

        $expanded = $state['expanded'] ?? [];
        $selected = $state['selected'] ?? null;
        $loadedPaths = $state['loadedPaths'] ?? [];
        $viewingFile = $state['viewingFile'] ?? null;
        $settings = $state['settings'] ?? null;

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
            'viewingFile' => $viewingFile,
            'settings' => $settings,
        ])->render();
    }

    /**
     * Handle panel actions for lazy loading and file reading.
     */
    public function handleAction(string $action, array $params, array $state, array $panelParams = []): array
    {
        if ($action === 'loadChildren') {
            return $this->handleLoadChildren($params, $state, $panelParams);
        }

        if ($action === 'readFile') {
            return $this->handleReadFile($params, $state, $panelParams);
        }

        if ($action === 'writeFile') {
            return $this->handleWriteFile($params, $state, $panelParams);
        }

        if ($action === 'downloadFile') {
            return $this->handleDownloadFile($params, $state, $panelParams);
        }

        return parent::handleAction($action, $params, $state, $panelParams);
    }

    /**
     * Handle loadChildren action for lazy directory loading.
     */
    protected function handleLoadChildren(array $params, array $state, array $panelParams): array
    {
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

    /**
     * Handle readFile action for viewing file contents.
     */
    protected function handleReadFile(array $params, array $state, array $panelParams): array
    {
        $filePath = $params['path'] ?? '';
        $rootPath = $panelParams['path'] ?? $state['rootPath'] ?? '/workspace/default';

        // Validate file path
        $realPath = $this->validatePath($filePath);
        if ($realPath === null) {
            return ['error' => 'Access denied: path not within allowed directories'];
        }

        // Validate root path
        $realRoot = $this->validatePath($rootPath);
        if ($realRoot === null) {
            return ['error' => 'Access denied: root path not within allowed directories'];
        }

        // Ensure file is within the configured root
        if (!str_starts_with($realPath . '/', $realRoot . '/') && $realPath !== $realRoot) {
            return ['error' => 'Access denied: path outside allowed root'];
        }

        if (!is_file($realPath)) {
            return ['error' => 'Not a file or file not found'];
        }

        $size = filesize($realPath);
        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $name = basename($realPath);

        // Handle image files
        if (in_array($extension, self::IMAGE_EXTENSIONS)) {
            if ($size > self::MAX_IMAGE_SIZE) {
                return [
                    'data' => [
                        'type' => 'binary',
                        'size' => $size,
                        'sizeFormatted' => self::formatSizeStatic($size),
                        'name' => $name,
                        'extension' => $extension,
                        'message' => 'Image too large to preview (>' . self::formatSizeStatic(self::MAX_IMAGE_SIZE) . ')',
                    ],
                ];
            }

            $mime = mime_content_type($realPath) ?: 'application/octet-stream';
            $base64 = base64_encode(file_get_contents($realPath));

            return [
                'data' => [
                    'type' => 'image',
                    'mime' => $mime,
                    'base64' => $base64,
                    'size' => $size,
                    'sizeFormatted' => self::formatSizeStatic($size),
                    'name' => $name,
                    'extension' => $extension,
                ],
            ];
        }

        // Handle binary files
        if (in_array($extension, self::BINARY_EXTENSIONS)) {
            return [
                'data' => [
                    'type' => 'binary',
                    'size' => $size,
                    'sizeFormatted' => self::formatSizeStatic($size),
                    'name' => $name,
                    'extension' => $extension,
                ],
            ];
        }

        // Handle text files — check if content looks binary
        $content = file_get_contents($realPath, false, null, 0, min($size, self::MAX_TEXT_SIZE));
        $truncated = $size > self::MAX_TEXT_SIZE;

        // Quick binary check: look for null bytes in the first 8KB
        $sampleSize = min(strlen($content), 8192);
        $sample = substr($content, 0, $sampleSize);
        if ($sampleSize > 0 && substr_count($sample, "\x00") > 0) {
            return [
                'data' => [
                    'type' => 'binary',
                    'size' => $size,
                    'sizeFormatted' => self::formatSizeStatic($size),
                    'name' => $name,
                    'extension' => $extension,
                    'message' => 'File appears to contain binary data',
                ],
            ];
        }

        // Map special file extensions for highlight.js language detection
        $hljsLanguage = $this->getHighlightLanguage($name, $extension);

        return [
            'data' => [
                'type' => 'text',
                'content' => $content,
                'truncated' => $truncated,
                'size' => $size,
                'sizeFormatted' => self::formatSizeStatic($size),
                'name' => $name,
                'extension' => $extension,
                'language' => $hljsLanguage,
            ],
        ];
    }

    /**
     * Handle writeFile action for saving edited file contents.
     */
    protected function handleWriteFile(array $params, array $state, array $panelParams): array
    {
        $filePath = $params['path'] ?? '';
        $content = $params['content'] ?? null;
        $rootPath = $panelParams['path'] ?? $state['rootPath'] ?? '/workspace/default';

        if ($content === null) {
            return ['error' => 'No content provided'];
        }

        // Validate file path
        $realPath = $this->validatePath($filePath);
        if ($realPath === null) {
            return ['error' => 'Access denied: path not within allowed directories'];
        }

        // Validate root path
        $realRoot = $this->validatePath($rootPath);
        if ($realRoot === null) {
            return ['error' => 'Access denied: root path not within allowed directories'];
        }

        // Ensure file is within the configured root
        if (!str_starts_with($realPath . '/', $realRoot . '/') && $realPath !== $realRoot) {
            return ['error' => 'Access denied: path outside allowed root'];
        }

        if (!is_file($realPath)) {
            return ['error' => 'Not a file or file not found'];
        }

        // Check if file is writable
        if (!is_writable($realPath)) {
            return ['error' => 'File is not writable (permission denied)'];
        }

        try {
            $bytesWritten = file_put_contents($realPath, $content);
            if ($bytesWritten === false) {
                return ['error' => 'Failed to write file'];
            }

            return [
                'data' => [
                    'success' => true,
                    'bytesWritten' => $bytesWritten,
                    'sizeFormatted' => self::formatSizeStatic($bytesWritten),
                ],
            ];
        } catch (\Exception $e) {
            return ['error' => 'Write failed: ' . $e->getMessage()];
        }
    }

    /**
     * Handle downloadFile action for downloading file contents as base64.
     */
    protected function handleDownloadFile(array $params, array $state, array $panelParams): array
    {
        $filePath = $params['path'] ?? '';
        $rootPath = $panelParams['path'] ?? $state['rootPath'] ?? '/workspace/default';

        // Validate file path
        $realPath = $this->validatePath($filePath);
        if ($realPath === null) {
            return ['error' => 'Access denied: path not within allowed directories'];
        }

        // Validate root path
        $realRoot = $this->validatePath($rootPath);
        if ($realRoot === null) {
            return ['error' => 'Access denied: root path not within allowed directories'];
        }

        // Ensure file is within the configured root
        if (!str_starts_with($realPath . '/', $realRoot . '/') && $realPath !== $realRoot) {
            return ['error' => 'Access denied: path outside allowed root'];
        }

        if (!is_file($realPath)) {
            return ['error' => 'Not a file or file not found'];
        }

        $size = filesize($realPath);
        $maxDownload = 50 * 1024 * 1024; // 50 MB limit
        if ($size > $maxDownload) {
            return ['error' => 'File too large to download (>' . self::formatSizeStatic($maxDownload) . ')'];
        }

        $mime = mime_content_type($realPath) ?: 'application/octet-stream';
        $base64 = base64_encode(file_get_contents($realPath));

        return [
            'data' => [
                'base64' => $base64,
                'mime' => $mime,
                'name' => basename($realPath),
                'size' => $size,
            ],
        ];
    }

    /**
     * Map file extension/name to highlight.js language identifier.
     */
    protected function getHighlightLanguage(string $name, string $extension): string
    {
        // Special filename matches
        if (str_ends_with($name, '.blade.php')) {
            return 'php';  // blade templates are best highlighted as PHP
        }

        // Extension to hljs language map
        return match ($extension) {
            'php' => 'php',
            'js', 'mjs', 'cjs' => 'javascript',
            'ts', 'mts', 'cts' => 'typescript',
            'jsx' => 'javascript',
            'tsx' => 'typescript',
            'vue' => 'xml',
            'json' => 'json',
            'md', 'markdown' => 'markdown',
            'css' => 'css',
            'scss' => 'scss',
            'less' => 'less',
            'html', 'htm' => 'xml',
            'xml', 'xsl', 'xslt' => 'xml',
            'py' => 'python',
            'rb' => 'ruby',
            'rs' => 'rust',
            'go' => 'go',
            'java' => 'java',
            'kt', 'kts' => 'kotlin',
            'swift' => 'swift',
            'c', 'h' => 'c',
            'cpp', 'cc', 'cxx', 'hpp' => 'cpp',
            'cs' => 'csharp',
            'sh', 'bash', 'zsh' => 'bash',
            'sql' => 'sql',
            'yml', 'yaml' => 'yaml',
            'toml' => 'ini',
            'ini', 'cfg', 'conf' => 'ini',
            'env' => 'ini',
            'dockerfile' => 'dockerfile',
            'makefile' => 'makefile',
            'lua' => 'lua',
            'r' => 'r',
            'pl', 'pm' => 'perl',
            'dart' => 'dart',
            'diff', 'patch' => 'diff',
            'graphql', 'gql' => 'graphql',
            'nginx' => 'nginx',
            default => 'plaintext',
        };
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
     * Get all directories in a path, including hidden (dot) directories.
     * Laravel's File::directories() uses glob() which skips dotfiles.
     */
    protected function getDirectories(string $path): array
    {
        $dirs = [];
        $entries = @scandir($path);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $path . '/' . $entry;
            if (is_dir($fullPath)) {
                $dirs[] = $fullPath;
            }
        }

        sort($dirs);
        return $dirs;
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
            // Directories first (non-hidden sorted, then hidden sorted)
            $entries = $this->getDirectories($path);

            // Batch-get directory sizes in a single du call
            $dirSizes = self::getDirSizes($entries);

            $visible = [];
            $hidden = [];

            foreach ($entries as $dir) {
                $name = basename($dir);
                $isHidden = str_starts_with($name, '.');
                $isExpanded = in_array($dir, $expanded);
                $isLoaded = in_array($dir, $loadedPaths);

                // Only include children if this path has been loaded
                $children = [];
                if ($isLoaded) {
                    $children = $this->buildTreeLazy($dir, $expanded, $loadedPaths);
                }

                $meta = self::getPathMetadata($dir);
                $item = [
                    'type' => 'directory',
                    'name' => $name,
                    'path' => $dir,
                    'expanded' => $isExpanded,
                    'loaded' => $isLoaded,
                    'children' => $children,
                    'isHidden' => $isHidden,
                    'size' => $dirSizes[$dir] ?? 0,
                    'mtime' => $meta['mtime'],
                    'mtimeFormatted' => $meta['mtimeFormatted'],
                    'owner' => $meta['owner'],
                    'group' => $meta['group'],
                    'permissions' => $meta['permissions'],
                ];

                if ($isHidden) {
                    $hidden[] = $item;
                } else {
                    $visible[] = $item;
                }
            }

            // Non-hidden directories first, then hidden
            $items = array_merge($visible, $hidden);

            // Then files (non-hidden sorted, then hidden sorted)
            // Pass true to include hidden (dot) files
            $files = File::files($path, true);
            usort($files, fn($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

            $visibleFiles = [];
            $hiddenFiles = [];

            foreach ($files as $file) {
                $name = $file->getFilename();
                $isHidden = str_starts_with($name, '.');

                $meta = self::getPathMetadata($file->getPathname());
                $fileItem = [
                    'type' => 'file',
                    'name' => $name,
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'extension' => $file->getExtension(),
                    'isHidden' => $isHidden,
                    'mtime' => $meta['mtime'],
                    'mtimeFormatted' => $meta['mtimeFormatted'],
                    'owner' => $meta['owner'],
                    'group' => $meta['group'],
                    'permissions' => $meta['permissions'],
                ];

                if ($isHidden) {
                    $hiddenFiles[] = $fileItem;
                } else {
                    $visibleFiles[] = $fileItem;
                }
            }

            $items = array_merge($items, $visibleFiles, $hiddenFiles);
        } catch (\Exception $e) {
            Log::warning('FileExplorerPanel: failed to read directory', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
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
            // Directories first (non-hidden, then hidden)
            $entries = $this->getDirectories($path);

            // Batch-get directory sizes in a single du call
            $dirSizes = self::getDirSizes($entries);

            $visible = [];
            $hidden = [];

            foreach ($entries as $dir) {
                $name = basename($dir);
                $isHidden = str_starts_with($name, '.');

                $meta = self::getPathMetadata($dir);
                $item = [
                    'type' => 'directory',
                    'name' => $name,
                    'path' => $dir,
                    'expanded' => false,
                    'loaded' => false,
                    'children' => [],
                    'isHidden' => $isHidden,
                    'size' => $dirSizes[$dir] ?? 0,
                    'mtime' => $meta['mtime'],
                    'mtimeFormatted' => $meta['mtimeFormatted'],
                    'owner' => $meta['owner'],
                    'group' => $meta['group'],
                    'permissions' => $meta['permissions'],
                ];

                if ($isHidden) {
                    $hidden[] = $item;
                } else {
                    $visible[] = $item;
                }
            }

            $items = array_merge($visible, $hidden);

            // Then files (non-hidden, then hidden)
            // Pass true to include hidden (dot) files
            $files = File::files($path, true);
            usort($files, fn($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

            $visibleFiles = [];
            $hiddenFiles = [];

            foreach ($files as $file) {
                $name = $file->getFilename();
                $isHidden = str_starts_with($name, '.');

                $meta = self::getPathMetadata($file->getPathname());
                $fileItem = [
                    'type' => 'file',
                    'name' => $name,
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'extension' => $file->getExtension(),
                    'isHidden' => $isHidden,
                    'mtime' => $meta['mtime'],
                    'mtimeFormatted' => $meta['mtimeFormatted'],
                    'owner' => $meta['owner'],
                    'group' => $meta['group'],
                    'permissions' => $meta['permissions'],
                ];

                if ($isHidden) {
                    $hiddenFiles[] = $fileItem;
                } else {
                    $visibleFiles[] = $fileItem;
                }
            }

            $items = array_merge($items, $visibleFiles, $hiddenFiles);
        } catch (\Exception $e) {
            Log::warning('FileExplorerPanel: failed to read directory', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
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
            // Directories first (including hidden)
            $dirs = $this->getDirectories($path);

            foreach ($dirs as $dir) {
                $name = basename($dir);

                $isExpanded = in_array($dir, $expanded);
                $marker = $isExpanded ? '(expanded)' : '(collapsed)';
                $output .= "{$prefix}[DIR] {$name}/ {$marker}\n";

                if ($isExpanded) {
                    $output .= $this->buildPeekTree($dir, $expanded, $indent + 1);
                }
            }

            // Then files (including hidden)
            $files = File::files($path, true);
            foreach ($files as $file) {
                $name = $file->getFilename();

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
            $dirs = $this->getDirectories($path);
            foreach ($dirs as $dir) {
                $counts['dirs']++;
                $counts['total']++;

                if (in_array($dir, $expanded)) {
                    $subCounts = $this->countVisible($dir, $expanded);
                    $counts['dirs'] += $subCounts['dirs'];
                    $counts['files'] += $subCounts['files'];
                    $counts['total'] += $subCounts['total'];
                }
            }

            $files = File::files($path, true);
            foreach ($files as $file) {
                $counts['files']++;
                $counts['total']++;
            }
        } catch (\Exception $e) {
            Log::warning('FileExplorerPanel: failed to count directory contents', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
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

    /**
     * Format modification time like ls -l: recent shows time, older shows year.
     */
    public static function formatModTime(int $mtime): string
    {
        if ($mtime === 0) return '';

        $sixMonthsAgo = time() - (180 * 24 * 3600);
        if ($mtime > $sixMonthsAgo) {
            return date('M j H:i', $mtime);
        }
        return date('M j Y', $mtime);
    }

    /**
     * Get total sizes for multiple directories in a single du call.
     * Much more efficient than calling du per-directory.
     */
    public static function getDirSizes(array $dirPaths): array
    {
        if (empty($dirPaths)) {
            return [];
        }

        $escaped = array_map('escapeshellarg', $dirPaths);
        $cmd = 'du -sb ' . implode(' ', $escaped) . ' 2>/dev/null';
        $output = @shell_exec($cmd);

        $sizes = [];
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                if (preg_match('/^(\d+)\t(.+)$/', $line, $m)) {
                    $sizes[$m[2]] = (int) $m[1];
                }
            }
        }

        return $sizes;
    }

    /**
     * Get file/directory metadata (permissions, owner, group, mtime).
     */
    public static function getPathMetadata(string $path): array
    {
        try {
            $stat = @stat($path);
            if (!$stat) {
                return ['mtime' => 0, 'mtimeFormatted' => '', 'owner' => '?', 'group' => '?', 'permissions' => '????'];
            }

            $owner = (string) $stat['uid'];
            $group = (string) $stat['gid'];

            if (function_exists('posix_getpwuid')) {
                $pwuid = @posix_getpwuid($stat['uid']);
                if ($pwuid) $owner = $pwuid['name'];
            }
            if (function_exists('posix_getgrgid')) {
                $grgid = @posix_getgrgid($stat['gid']);
                if ($grgid) $group = $grgid['name'];
            }

            return [
                'mtime' => $stat['mtime'],
                'mtimeFormatted' => self::formatModTime($stat['mtime']),
                'owner' => $owner,
                'group' => $group,
                'permissions' => substr(sprintf('%o', $stat['mode']), -4),
            ];
        } catch (\Exception $e) {
            return ['mtime' => 0, 'mtimeFormatted' => '', 'owner' => '?', 'group' => '?', 'permissions' => '????'];
        }
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
