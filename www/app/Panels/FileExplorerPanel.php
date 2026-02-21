<?php

namespace App\Panels;

use App\Support\SshConnection;
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
        'ssh_host' => [
            'type' => 'string',
            'description' => 'SSH host for remote file browsing (omit for local)',
            'default' => null,
        ],
        'ssh_user' => [
            'type' => 'string',
            'description' => 'SSH username',
            'default' => 'root',
        ],
        'ssh_port' => [
            'type' => 'integer',
            'description' => 'SSH port',
            'default' => 22,
        ],
        'ssh_password' => [
            'type' => 'string',
            'description' => 'SSH password (omit for key-based auth)',
            'default' => null,
        ],
        'ssh_key_path' => [
            'type' => 'string',
            'description' => 'Path to SSH private key (default: ~/.ssh/id_rsa or id_ed25519)',
            'default' => null,
        ],
        'server_name' => [
            'type' => 'string',
            'description' => 'Friendly server name shown in the panel header (e.g. "Production", "SGS Main")',
            'default' => null,
        ],
    ];

    public array $panelDependencies = [
        [
            'type' => 'stylesheet',
            'url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github-dark.min.css',
            'crossorigin' => 'anonymous',
        ],
        [
            'type' => 'script',
            'url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/highlight.min.js',
            'crossorigin' => 'anonymous',
            'defer' => true,
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

    /**
     * Max file size for download via base64 (128 MB).
     */
    protected const MAX_DOWNLOAD_SIZE = 128 * 1024 * 1024;

    /**
     * Max directories to calculate sizes for in a single du call.
     */
    protected const MAX_DIR_SIZE_COUNT = 50;

    /**
     * Create SSH connection from panel params, or null for local mode.
     */
    protected function getSsh(array $panelParams): ?SshConnection
    {
        return SshConnection::fromPanelParams($panelParams);
    }

    // ========================================================================
    // RENDER
    // ========================================================================

    public function render(array $params, array $state, ?string $panelStateId = null): string
    {
        $ssh = $this->getSsh($params);
        $rootPath = $params['path'] ?? '/workspace/default';

        if ($ssh) {
            // Remote mode: skip PathValidator, check remote directory
            if (!$this->isDirectoryRemote($ssh, $rootPath)) {
                return view('panels.file-explorer', [
                    'rootPath' => $rootPath,
                    'tree' => [],
                    'expanded' => [],
                    'selected' => null,
                    'loadedPaths' => [],
                    'panelStateId' => $panelStateId,
                    'viewingFile' => null,
                    'settings' => null,
                    'sshLabel' => $ssh->getLabel(),
                    'error' => "Remote directory not found or not accessible: {$rootPath}",
                ])->render();
            }
        } else {
            // Local mode: validate path
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
                    'sshLabel' => null,
                    'error' => 'Access denied: path not within allowed directories',
                ])->render();
            }
            $rootPath = $validatedPath;
        }

        $expanded = $state['expanded'] ?? [];
        $selected = $state['selected'] ?? null;
        $viewingFile = $state['viewingFile'] ?? null;
        $settings = $state['settings'] ?? null;

        // On page render, only load the root + currently expanded paths.
        // Previously-loaded-but-now-collapsed paths are trimmed to avoid
        // unnecessary SSH calls (or local du calls) on refresh.
        $loadedPaths = array_values(array_unique(array_merge([$rootPath], $expanded)));

        // Build the file tree - only first level + expanded paths
        $tree = $ssh
            ? $this->buildTreeLazyRemote($ssh, $rootPath, $expanded, $loadedPaths)
            : $this->buildTreeLazy($rootPath, $expanded, $loadedPaths);

        return view('panels.file-explorer', [
            'rootPath' => $rootPath,
            'tree' => $tree,
            'expanded' => $expanded,
            'selected' => $selected,
            'loadedPaths' => $loadedPaths,
            'panelStateId' => $panelStateId,
            'viewingFile' => $viewingFile,
            'settings' => $settings,
            'sshLabel' => $ssh?->getLabel(),
        ])->render();
    }

    // ========================================================================
    // ACTION DISPATCH
    // ========================================================================

    /**
     * Handle panel actions for lazy loading and file reading.
     */
    public function handleAction(string $action, array $params, array $state, array $panelParams = []): array
    {
        $ssh = $this->getSsh($panelParams);

        if ($action === 'loadChildren') {
            return $ssh
                ? $this->handleLoadChildrenRemote($ssh, $params, $state, $panelParams)
                : $this->handleLoadChildren($params, $state, $panelParams);
        }

        if ($action === 'readFile') {
            return $ssh
                ? $this->handleReadFileRemote($ssh, $params, $state, $panelParams)
                : $this->handleReadFile($params, $state, $panelParams);
        }

        if ($action === 'writeFile') {
            return $ssh
                ? $this->handleWriteFileRemote($ssh, $params, $state, $panelParams)
                : $this->handleWriteFile($params, $state, $panelParams);
        }

        if ($action === 'downloadFile') {
            return $ssh
                ? $this->handleDownloadFileRemote($ssh, $params, $state, $panelParams)
                : $this->handleDownloadFile($params, $state, $panelParams);
        }

        return parent::handleAction($action, $params, $state, $panelParams);
    }

    // ========================================================================
    // LOCAL ACTION HANDLERS (unchanged)
    // ========================================================================

    /**
     * Handle loadChildren action for lazy directory loading.
     */
    protected function handleLoadChildren(array $params, array $state, array $panelParams): array
    {
        $path = $params['path'] ?? '';
        $depth = $params['depth'] ?? 1;

        // Get root path from panel parameters or state
        $rootPath = $panelParams['path'] ?? '/workspace/default';

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
        $rootPath = $panelParams['path'] ?? '/workspace/default';

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
        if ($size === false) {
            return ['error' => 'Unable to read file size'];
        }
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
            $imageData = file_get_contents($realPath);
            if ($imageData === false) {
                return ['error' => 'Unable to read image file'];
            }
            $base64 = base64_encode($imageData);

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
        if ($content === false) {
            return ['error' => 'Unable to read file contents'];
        }
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
        $rootPath = $panelParams['path'] ?? '/workspace/default';

        if ($content === null) {
            return ['error' => 'No content provided'];
        }

        // Cap write size to match the read limit
        if (strlen($content) > self::MAX_TEXT_SIZE) {
            return ['error' => 'Content too large to save (>' . self::formatSizeStatic(self::MAX_TEXT_SIZE) . ')'];
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
            $bytesWritten = file_put_contents($realPath, $content, LOCK_EX);
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
        $rootPath = $panelParams['path'] ?? '/workspace/default';

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
        if ($size === false) {
            return ['error' => 'Unable to read file size'];
        }
        if ($size > self::MAX_DOWNLOAD_SIZE) {
            return ['error' => 'File too large to download (>' . self::formatSizeStatic(self::MAX_DOWNLOAD_SIZE) . ')'];
        }

        $mime = mime_content_type($realPath) ?: 'application/octet-stream';
        $fileData = file_get_contents($realPath);
        if ($fileData === false) {
            return ['error' => 'Unable to read file for download'];
        }
        $base64 = base64_encode($fileData);

        return [
            'data' => [
                'base64' => $base64,
                'mime' => $mime,
                'name' => basename($realPath),
                'size' => $size,
            ],
        ];
    }

    // ========================================================================
    // REMOTE (SSH) ACTION HANDLERS
    // ========================================================================

    /**
     * Validate that a remote path is within the configured root.
     *
     * Security note: Unlike local PathValidator which resolves symlinks via realpath(),
     * this is a string-prefix check only. A remote symlink inside the root could point
     * outside it. This is an accepted limitation: the rootPath is set by the AI (not
     * end users), and anyone who can create symlinks on the remote server already has
     * SSH access, making the file explorer a non-factor in the threat model.
     */
    protected function validateRemotePath(string $path, string $rootPath): bool
    {
        // Root "/" needs special handling: "/" . "/" = "//" which no path starts with
        if ($rootPath === '/') {
            return str_starts_with($path, '/');
        }

        return str_starts_with($path . '/', $rootPath . '/') || $path === $rootPath;
    }

    /**
     * Handle loadChildren via SSH.
     */
    protected function handleLoadChildrenRemote(SshConnection $ssh, array $params, array $state, array $panelParams): array
    {
        $path = $params['path'] ?? '';
        $depth = $params['depth'] ?? 1;
        $rootPath = $panelParams['path'] ?? '/';

        // Path containment check (no PathValidator for remote)
        if (!$this->validateRemotePath($path, $rootPath)) {
            return ['error' => 'Access denied: path outside allowed root'];
        }

        if (!$this->isDirectoryRemote($ssh, $path)) {
            return ['error' => 'Invalid path'];
        }

        $children = $this->buildDirectoryContentsRemote($ssh, $path);

        $html = view('panels.partials.file-tree-children', [
            'children' => $children,
            'depth' => $depth,
        ])->render();

        $loadedPaths = $state['loadedPaths'] ?? [];
        if (!in_array($path, $loadedPaths)) {
            $loadedPaths[] = $path;
        }

        return [
            'html' => $html,
            'state' => ['loadedPaths' => $loadedPaths],
            'data' => null,
            'error' => null,
        ];
    }

    /**
     * Handle readFile via SSH.
     */
    protected function handleReadFileRemote(SshConnection $ssh, array $params, array $state, array $panelParams): array
    {
        $filePath = $params['path'] ?? '';
        $rootPath = $panelParams['path'] ?? '/';

        if (!$this->validateRemotePath($filePath, $rootPath)) {
            return ['error' => 'Access denied: path outside allowed root'];
        }

        if (!$this->isFileRemote($ssh, $filePath)) {
            return ['error' => 'Not a file or file not found'];
        }

        $size = $this->getFileSizeRemote($ssh, $filePath);
        if ($size === null) {
            return ['error' => 'Unable to read file size'];
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $name = basename($filePath);

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

            $mime = $this->getMimeTypeRemote($ssh, $filePath);
            // Read remote file as base64 directly to avoid binary transfer issues
            $base64 = $this->readFileBase64Remote($ssh, $filePath);
            if ($base64 === null) {
                return ['error' => 'Unable to read image file'];
            }

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

        // Handle text files
        $content = $this->readFileRemote($ssh, $filePath, min($size, self::MAX_TEXT_SIZE));
        if ($content === null) {
            return ['error' => 'Unable to read file contents'];
        }
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
     * Handle writeFile via SSH.
     */
    protected function handleWriteFileRemote(SshConnection $ssh, array $params, array $state, array $panelParams): array
    {
        $filePath = $params['path'] ?? '';
        $content = $params['content'] ?? null;
        $rootPath = $panelParams['path'] ?? '/';

        if ($content === null) {
            return ['error' => 'No content provided'];
        }

        if (strlen($content) > self::MAX_TEXT_SIZE) {
            return ['error' => 'Content too large to save (>' . self::formatSizeStatic(self::MAX_TEXT_SIZE) . ')'];
        }

        if (!$this->validateRemotePath($filePath, $rootPath)) {
            return ['error' => 'Access denied: path outside allowed root'];
        }

        if (!$this->isFileRemote($ssh, $filePath)) {
            return ['error' => 'Not a file or file not found'];
        }

        $bytesWritten = $this->writeFileRemote($ssh, $filePath, $content);
        if ($bytesWritten === false) {
            return ['error' => 'Failed to write file (permission denied or SSH error)'];
        }

        return [
            'data' => [
                'success' => true,
                'bytesWritten' => $bytesWritten,
                'sizeFormatted' => self::formatSizeStatic($bytesWritten),
            ],
        ];
    }

    /**
     * Handle downloadFile via SSH.
     */
    protected function handleDownloadFileRemote(SshConnection $ssh, array $params, array $state, array $panelParams): array
    {
        $filePath = $params['path'] ?? '';
        $rootPath = $panelParams['path'] ?? '/';

        if (!$this->validateRemotePath($filePath, $rootPath)) {
            return ['error' => 'Access denied: path outside allowed root'];
        }

        if (!$this->isFileRemote($ssh, $filePath)) {
            return ['error' => 'Not a file or file not found'];
        }

        $size = $this->getFileSizeRemote($ssh, $filePath);
        if ($size === null) {
            return ['error' => 'Unable to read file size'];
        }
        if ($size > self::MAX_DOWNLOAD_SIZE) {
            return ['error' => 'File too large to download (>' . self::formatSizeStatic(self::MAX_DOWNLOAD_SIZE) . ')'];
        }

        $mime = $this->getMimeTypeRemote($ssh, $filePath);
        $base64 = $this->readFileBase64Remote($ssh, $filePath);
        if ($base64 === null) {
            return ['error' => 'Unable to read file for download'];
        }

        return [
            'data' => [
                'base64' => $base64,
                'mime' => $mime,
                'name' => basename($filePath),
                'size' => $size,
            ],
        ];
    }

    // ========================================================================
    // REMOTE FILE OPERATIONS
    // ========================================================================

    /**
     * Remote: check if path is a directory.
     */
    protected function isDirectoryRemote(SshConnection $ssh, string $path): bool
    {
        $output = $ssh->exec("test -d " . escapeshellarg($path) . " && echo yes", 10);
        return $output !== null && str_contains($output, 'yes');
    }

    /**
     * Remote: check if path is a file.
     */
    protected function isFileRemote(SshConnection $ssh, string $path): bool
    {
        $output = $ssh->exec("test -f " . escapeshellarg($path) . " && echo yes", 10);
        return $output !== null && str_contains($output, 'yes');
    }

    /**
     * Remote: get file size.
     */
    protected function getFileSizeRemote(SshConnection $ssh, string $path): ?int
    {
        $output = $ssh->exec("stat -c '%s' " . escapeshellarg($path) . " 2>/dev/null", 5);
        if ($output === null) {
            return null;
        }
        $trimmed = trim($output);
        return is_numeric($trimmed) ? (int) $trimmed : null;
    }

    /**
     * Remote: read file content as text.
     */
    protected function readFileRemote(SshConnection $ssh, string $path, ?int $maxBytes = null): ?string
    {
        $escaped = escapeshellarg($path);
        if ($maxBytes !== null) {
            $cmd = "head -c {$maxBytes} {$escaped} 2>/dev/null";
        } else {
            $cmd = "cat {$escaped} 2>/dev/null";
        }
        return $ssh->exec($cmd, 30);
    }

    /**
     * Remote: read file as base64 (safe for binary transfer over SSH).
     */
    protected function readFileBase64Remote(SshConnection $ssh, string $path): ?string
    {
        $output = $ssh->exec("base64 " . escapeshellarg($path) . " 2>/dev/null", 60);
        if ($output === null) {
            return null;
        }
        // Remove any whitespace/newlines from base64 output
        return str_replace(["\n", "\r", " "], '', trim($output));
    }

    /**
     * Remote: write file content via base64 encoding (safe for any content).
     */
    protected function writeFileRemote(SshConnection $ssh, string $path, string $content): int|false
    {
        $b64 = base64_encode($content);
        $escaped = escapeshellarg($path);
        // Use printf to handle the base64 string safely, pipe through base64 decode
        $result = $ssh->run(
            "printf '%s' " . escapeshellarg($b64) . " | base64 -d > {$escaped} 2>&1",
            30
        );
        if ($result->failed()) {
            return false;
        }
        return strlen($content);
    }

    /**
     * Remote: get MIME type.
     */
    protected function getMimeTypeRemote(SshConnection $ssh, string $path): string
    {
        $output = $ssh->exec("file -b --mime-type " . escapeshellarg($path) . " 2>/dev/null", 5);
        return trim($output ?? '') ?: 'application/octet-stream';
    }

    /**
     * Remote: list subdirectories (sorted).
     */
    protected function getDirectoriesRemote(SshConnection $ssh, string $path): array
    {
        $escaped = escapeshellarg($path);
        $output = $ssh->exec(
            "find {$escaped} -maxdepth 1 -mindepth 1 -type d 2>/dev/null | sort",
            15
        );
        if (!$output) {
            return [];
        }
        return array_filter(explode("\n", trim($output)));
    }

    /**
     * Remote: list files (non-directories) with size and name.
     */
    protected function getFilesRemote(SshConnection $ssh, string $path): array
    {
        $escaped = escapeshellarg($path);
        // Use find + stat combo that works reliably on Linux
        // Output: size\tname (tab-separated)
        $output = $ssh->exec(
            "find {$escaped} -maxdepth 1 -mindepth 1 ! -type d -exec stat -c '%s\t%n' {} + 2>/dev/null | sort -t'\t' -k2",
            15
        );
        if (!$output) {
            return [];
        }

        $files = [];
        foreach (array_filter(explode("\n", trim($output))) as $line) {
            $parts = explode("\t", $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$size, $fullPath] = $parts;
            $name = basename($fullPath);
            $files[] = [
                'name' => $name,
                'path' => $fullPath,
                'size' => (int) $size,
                'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            ];
        }
        return $files;
    }

    /**
     * Remote: get directory sizes via du (batched).
     */
    protected function getDirSizesRemote(SshConnection $ssh, array $dirPaths): array
    {
        if (empty($dirPaths)) {
            return [];
        }

        if (count($dirPaths) > self::MAX_DIR_SIZE_COUNT) {
            $dirPaths = array_slice($dirPaths, 0, self::MAX_DIR_SIZE_COUNT);
        }

        $escaped = array_map('escapeshellarg', $dirPaths);
        $cmd = 'timeout 10 du -sbx ' . implode(' ', $escaped) . ' 2>/dev/null';
        // Use execPartial: du may timeout on huge directories but still have
        // partial output for smaller ones. We want to keep those results.
        $output = $ssh->execPartial($cmd, 15);

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
     * Remote: batch stat for metadata (permissions, owner, group, mtime).
     * Single SSH call for all paths — much more efficient than per-path.
     */
    protected function getPathMetadataBatchRemote(SshConnection $ssh, array $paths): array
    {
        $defaultMeta = ['mtime' => 0, 'mtimeFormatted' => '', 'owner' => '?', 'group' => '?', 'permissions' => '????'];

        if (empty($paths)) {
            return [];
        }

        $escaped = array_map('escapeshellarg', $paths);
        // stat format: permissions\towner\tgroup\tmtime_epoch\tfull_path
        $cmd = "stat -c '%a\t%U\t%G\t%Y\t%n' " . implode(' ', $escaped) . " 2>/dev/null";
        $output = $ssh->exec($cmd, 10);

        $results = [];
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                $parts = explode("\t", $line, 5);
                if (count($parts) < 5) {
                    continue;
                }
                [$perms, $owner, $group, $mtime, $filepath] = $parts;
                $results[$filepath] = [
                    'mtime' => (int) $mtime,
                    'mtimeFormatted' => self::formatModTime((int) $mtime),
                    'owner' => $owner,
                    'group' => $group,
                    'permissions' => str_pad($perms, 4, '0', STR_PAD_LEFT),
                ];
            }
        }

        // Fill defaults for any paths that failed
        foreach ($paths as $p) {
            if (!isset($results[$p])) {
                $results[$p] = $defaultMeta;
            }
        }

        return $results;
    }

    // ========================================================================
    // REMOTE TREE BUILDERS
    // ========================================================================

    /**
     * Build tree lazily via SSH - only renders first level + already loaded paths.
     */
    protected function buildTreeLazyRemote(SshConnection $ssh, string $path, array $expanded, array $loadedPaths): array
    {
        if (!$this->isDirectoryRemote($ssh, $path)) {
            return [];
        }

        $items = [];

        try {
            $entries = $this->getDirectoriesRemote($ssh, $path);
            $dirSizes = $this->getDirSizesRemote($ssh, $entries);
            $dirMeta = $this->getPathMetadataBatchRemote($ssh, $entries);

            $visible = [];
            $hidden = [];

            foreach ($entries as $dir) {
                $name = basename($dir);
                $isHidden = str_starts_with($name, '.');
                $isExpanded = in_array($dir, $expanded);
                $isLoaded = in_array($dir, $loadedPaths);

                $children = [];
                if ($isLoaded) {
                    $children = $this->buildTreeLazyRemote($ssh, $dir, $expanded, $loadedPaths);
                }

                $meta = $dirMeta[$dir] ?? ['mtime' => 0, 'mtimeFormatted' => '', 'owner' => '?', 'group' => '?', 'permissions' => '????'];
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

            $items = array_merge($visible, $hidden);

            // Files
            $files = $this->getFilesRemote($ssh, $path);
            $filePaths = array_column($files, 'path');
            $fileMeta = $this->getPathMetadataBatchRemote($ssh, $filePaths);

            $visibleFiles = [];
            $hiddenFiles = [];

            foreach ($files as $file) {
                $isHidden = str_starts_with($file['name'], '.');
                $meta = $fileMeta[$file['path']] ?? ['mtime' => 0, 'mtimeFormatted' => '', 'owner' => '?', 'group' => '?', 'permissions' => '????'];

                $fileItem = [
                    'type' => 'file',
                    'name' => $file['name'],
                    'path' => $file['path'],
                    'size' => $file['size'],
                    'extension' => $file['extension'],
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
            Log::warning('FileExplorerPanel: failed to read remote directory', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    /**
     * Build directory contents via SSH (used by loadChildren action).
     */
    protected function buildDirectoryContentsRemote(SshConnection $ssh, string $path): array
    {
        $items = [];

        try {
            $dirs = $this->getDirectoriesRemote($ssh, $path);
            $dirSizes = $this->getDirSizesRemote($ssh, $dirs);
            $dirMeta = $this->getPathMetadataBatchRemote($ssh, $dirs);

            $visible = [];
            $hidden = [];

            foreach ($dirs as $dir) {
                $name = basename($dir);
                $isHidden = str_starts_with($name, '.');
                $meta = $dirMeta[$dir] ?? ['mtime' => 0, 'mtimeFormatted' => '', 'owner' => '?', 'group' => '?', 'permissions' => '????'];

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

            // Files
            $files = $this->getFilesRemote($ssh, $path);
            $filePaths = array_column($files, 'path');
            $fileMeta = $this->getPathMetadataBatchRemote($ssh, $filePaths);

            $visibleFiles = [];
            $hiddenFiles = [];

            foreach ($files as $file) {
                $isHidden = str_starts_with($file['name'], '.');
                $meta = $fileMeta[$file['path']] ?? ['mtime' => 0, 'mtimeFormatted' => '', 'owner' => '?', 'group' => '?', 'permissions' => '????'];

                $fileItem = [
                    'type' => 'file',
                    'name' => $file['name'],
                    'path' => $file['path'],
                    'size' => $file['size'],
                    'extension' => $file['extension'],
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
            Log::warning('FileExplorerPanel: failed to read remote directory', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    // ========================================================================
    // LOCAL TREE BUILDERS (unchanged)
    // ========================================================================

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

    // ========================================================================
    // PEEK
    // ========================================================================

    public function peek(array $params, array $state): string
    {
        $ssh = SshConnection::fromPanelParams($params);
        $rootPath = $params['path'] ?? '/workspace/default';
        $expanded = $state['expanded'] ?? [];

        if ($ssh) {
            // Remote peek
            if (!$this->isDirectoryRemote($ssh, $rootPath)) {
                return "## File Explorer (SSH: {$ssh->getLabel()}): Error\n\nRemote directory not found: {$rootPath}";
            }

            $output = "## File Explorer (SSH: {$ssh->getLabel()}): {$rootPath}\n\n";
            $output .= $this->buildPeekTreeRemote($ssh, $rootPath, $expanded, 0);

            return $output;
        }

        // Local peek
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
     * Build peek tree via SSH.
     */
    protected function buildPeekTreeRemote(SshConnection $ssh, string $path, array $expanded, int $indent): string
    {
        $output = '';
        $prefix = str_repeat('   ', $indent);

        try {
            $dirs = $this->getDirectoriesRemote($ssh, $path);

            foreach ($dirs as $dir) {
                $name = basename($dir);
                $isExpanded = in_array($dir, $expanded);
                $marker = $isExpanded ? '(expanded)' : '(collapsed)';
                $output .= "{$prefix}[DIR] {$name}/ {$marker}\n";

                if ($isExpanded) {
                    $output .= $this->buildPeekTreeRemote($ssh, $dir, $expanded, $indent + 1);
                }
            }

            $files = $this->getFilesRemote($ssh, $path);
            foreach ($files as $file) {
                $size = self::formatSizeStatic($file['size']);
                $output .= "{$prefix}[FILE] {$file['name']} ({$size})\n";
            }
        } catch (\Exception $e) {
            $output .= "{$prefix}[!] Cannot read directory\n";
        }

        return $output;
    }

    // ========================================================================
    // LOCAL PEEK HELPERS (unchanged)
    // ========================================================================

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

    // ========================================================================
    // SHARED UTILITIES
    // ========================================================================

    /**
     * Map file extension/name to highlight.js language identifier.
     */
    protected function getHighlightLanguage(string $name, string $extension): string
    {
        // Special filename matches
        if (str_ends_with($name, '.blade.php')) {
            return 'php';  // blade templates are best highlighted as PHP
        }

        // Extensionless filename matches
        $nameLower = strtolower($name);
        if ($nameLower === 'dockerfile') return 'dockerfile';
        if ($nameLower === 'makefile' || $nameLower === 'gnumakefile') return 'makefile';

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

    protected function formatSize(int $bytes): string
    {
        return self::formatSizeStatic($bytes);
    }

    public static function formatSizeStatic(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
        return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
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

        // Cap directory count to avoid slow du calls on huge directory listings
        if (count($dirPaths) > self::MAX_DIR_SIZE_COUNT) {
            $dirPaths = array_slice($dirPaths, 0, self::MAX_DIR_SIZE_COUNT);
        }

        $escaped = array_map('escapeshellarg', $dirPaths);
        // timeout: kill du after 5s if filesystem is unresponsive
        // -x: stay on same filesystem (avoid cross-mount traversal)
        $cmd = 'timeout 5 du -sbx ' . implode(' ', $escaped) . ' 2>/dev/null';
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
        static $uidCache = [], $gidCache = [];

        try {
            $stat = @stat($path);
            if (!$stat) {
                return ['mtime' => 0, 'mtimeFormatted' => '', 'owner' => '?', 'group' => '?', 'permissions' => '????'];
            }

            $uid = $stat['uid'];
            $gid = $stat['gid'];

            if (!isset($uidCache[$uid])) {
                $pw = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
                $uidCache[$uid] = $pw ? $pw['name'] : (string) $uid;
            }
            if (!isset($gidCache[$gid])) {
                $gr = function_exists('posix_getgrgid') ? @posix_getgrgid($gid) : false;
                $gidCache[$gid] = $gr ? $gr['name'] : (string) $gid;
            }

            $owner = $uidCache[$uid];
            $group = $gidCache[$gid];

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

    // ========================================================================
    // SYSTEM PROMPT
    // ========================================================================

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

## SSH Remote Mode
Browse files on a remote server via SSH by passing connection parameters:
- `ssh_host` (required for SSH): Remote hostname or IP
- `ssh_user` (default: root): SSH username
- `ssh_port` (default: 22): SSH port
- `ssh_password`: Password for SSH auth (omit for key-based auth)
- `ssh_key_path`: Path to private key (default: tries ~/.ssh/id_rsa, id_ed25519)

```bash
# Remote file browsing (password)
pd tool:run file-explorer -- --path=/var/www --ssh_host=192.168.1.100 --ssh_user=root --ssh_password=secret

# Remote file browsing (key-based)
pd tool:run file-explorer -- --path=/home/deploy --ssh_host=192.168.1.100 --ssh_user=deploy
```

Use `pd panel:peek file-explorer` to see current state after user navigates.
PROMPT;
    }
}
