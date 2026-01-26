<?php

namespace App\Tools;

/**
 * Transform Docker Compose files to use PocketDev workspace volume mounts.
 *
 * Converts bind mounts (./path:/target) to volume mounts with subpath,
 * creating a compose.override.yaml that Docker Compose auto-merges.
 *
 * @todo Add build directive parsing to transform relative paths in build contexts.
 *       Currently doesn't handle: `build: ./app`, `build: { context: ./, dockerfile: Dockerfile.dev }`,
 *       or multi-line build blocks. These would need path transformation for PocketDev workspaces.
 */
class ComposeTransformTool extends Tool
{
    public string $name = 'ComposeTransform';

    public string $description = 'Transform Docker Compose files to use PocketDev workspace volume mounts instead of bind mounts.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'input' => [
                'type' => 'string',
                'description' => 'Path to the compose file (e.g., /workspace/default/my-project/compose.yaml)',
            ],
        ],
        'required' => ['input'],
    ];

    private const VOLUME_NAME = 'pocket-dev-workspace';
    private const WORKSPACE_ROOT = '/workspace';

    public function getArtisanCommand(): ?string
    {
        return 'compose:transform';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Transforms a Docker Compose file to use PocketDev workspace volume mounts instead of bind mounts.

**When to use:**
- User wants to run their Docker project inside PocketDev
- User has a compose file with bind mounts (`./path:/target`)
- Setting up a new project's Docker environment

**How it works:**
1. Reads the compose file you specify
2. Converts bind mounts (`./src:/app`) to volume mounts with subpath
3. For file mounts: generates `Dockerfile.pocketdev` with COPY instructions before the USER directive
4. Creates `compose.override.yaml` in the same directory (original unchanged)

**File mount handling:**
- Files are injected at BUILD time via Dockerfile modification (not runtime copy)
- This preserves proper file descriptor permissions for `/dev/stdout` and `/dev/stderr`
- The container starts directly as the correct user
- Changes to mounted files require `docker compose build` to take effect

**Important:** The input path must be within `/workspace/` so the tool can calculate the correct subpath for volume mounts.

**Known Limitations:**
- File vs directory detection uses extension-based heuristics. Directories with extensions (e.g., `./configs.d`) may be misclassified as files, and files without extensions (e.g., `./Makefile`) may be misclassified as directories.
- Dockerfile modification requires a USER directive in the Dockerfile. Falls back to runtime copy if not found.
- Multi-stage Dockerfiles: Currently picks the last USER in the entire file. If USER is only in a builder stage (not the final stage), files may be injected incorrectly. Falls back to runtime copy for these cases.
- Build context mismatch: File mount sources must be inside the Docker build context. If build context is a subdirectory and mount source is outside it, the COPY will fail. Use `build: ./` (same as compose dir) to avoid this.

**After running this tool:** Read the generated files and inform the user that file mount changes require a rebuild (`docker compose build`).
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Example

```bash
# Transform a compose file (creates compose.override.yaml in same directory)
php artisan compose:transform --input=/workspace/default/my-project/compose.yaml

# For a compose file in a subdirectory
php artisan compose:transform --input=/workspace/default/my-project/docker/compose.yaml
```

## After Running

```bash
# Start services (Docker Compose auto-merges the override file)
cd /workspace/default/my-project
docker compose up -d

# Or with explicit files
docker compose -f compose.yaml -f compose.override.yaml up -d
```
CLI;

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $inputPath = $input['input'] ?? null;

        if (empty($inputPath)) {
            return ToolResult::error('The --input parameter is required. Specify the path to the compose file.');
        }

        // Resolve relative paths
        if (!str_starts_with($inputPath, '/')) {
            $inputPath = $context->resolvePath($inputPath);
        }

        // Validate input file exists
        if (!file_exists($inputPath)) {
            return ToolResult::error("Compose file not found: {$inputPath}");
        }

        // Validate path is within workspace
        if (!str_starts_with($inputPath, self::WORKSPACE_ROOT . '/')) {
            return ToolResult::error(
                "Compose file must be within " . self::WORKSPACE_ROOT . "/ for volume subpath calculation. " .
                "Got: {$inputPath}"
            );
        }

        // Calculate the directory containing the compose file (relative to workspace root)
        $composeDir = dirname($inputPath);
        $relativeDirFromWorkspace = substr($composeDir, strlen(self::WORKSPACE_ROOT . '/'));

        // Read and transform
        $content = file_get_contents($inputPath);
        $result = $this->transform($content, $relativeDirFromWorkspace, $composeDir);

        if ($result['error']) {
            return ToolResult::error($result['error']);
        }

        // Write output file
        $outputPath = $composeDir . '/compose.override.yaml';
        $written = file_put_contents($outputPath, $result['output']);

        if ($written === false) {
            return ToolResult::error("Failed to write output file: {$outputPath}");
        }

        $summary = [
            'output' => "Created {$outputPath}",
            'input_file' => $inputPath,
            'output_file' => $outputPath,
            'services_transformed' => $result['services_transformed'],
            'mounts_converted' => $result['mounts_converted'],
            'file_mounts' => $result['file_mounts'],
            'is_error' => false,
        ];

        if (!empty($result['generated_dockerfiles'])) {
            $summary['generated_dockerfiles'] = $result['generated_dockerfiles'];
            $summary['note'] = 'File mounts are injected at build time. Run `docker compose build` after changing mounted files.';
        }

        if (!empty($result['warnings'])) {
            $summary['warnings'] = $result['warnings'];
        }

        return ToolResult::success(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Transform compose file content into an override file.
     *
     * Creates a minimal override file containing only:
     * - Service volume overrides (converted bind mounts)
     * - Service user/entrypoint (for file mount copy operations)
     * - External volume declaration
     *
     * @param string $content The compose file content
     * @param string $relativeDirFromWorkspace Directory containing compose file, relative to /workspace/
     * @param string $composeDir Absolute path to directory containing compose file
     * @return array{output: string, error: ?string, services_transformed: int, mounts_converted: int, file_mounts: int, warnings: array}
     */
    private function transform(string $content, string $relativeDirFromWorkspace, string $composeDir): array
    {
        $lines = explode("\n", $content);

        // Track sections and state
        $inServicesSection = false;
        $inServiceVolumes = false;
        $inServiceBuild = false;
        $currentService = null;
        $volumesIndent = 0;
        $buildIndent = 0;

        // Collect service overrides: service => [volumes => [...], fileMounts => [...], buildContext => ..., dockerfile => ..., buildArgs => [...]]
        $serviceOverrides = [];
        $currentVolumes = [];
        $currentFileMounts = [];
        $currentBuildContext = null;
        $currentDockerfile = null;
        $currentBuildArgs = [];
        $inBuildArgs = false;
        $buildArgsIndent = 0;

        // Stats
        $mountsConverted = 0;
        $fileMountsCount = 0;
        $warnings = [];

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $stripped = ltrim($line);
            $currentIndent = strlen($line) - strlen($stripped);

            // Detect top-level sections (including extension sections like x-templates:)
            if ($currentIndent == 0 && preg_match("/^([a-z][a-z0-9-]*):\s*$/", $stripped, $sectionMatch)) {
                // Save previous service's data before switching sections
                if ($currentService && (!empty($currentVolumes) || !empty($currentFileMounts))) {
                    $serviceOverrides[$currentService] = [
                        'volumes' => $currentVolumes,
                        'fileMounts' => $currentFileMounts,
                        'buildContext' => $currentBuildContext,
                        'dockerfile' => $currentDockerfile,
                        'buildArgs' => $currentBuildArgs,
                    ];
                }

                $sectionName = $sectionMatch[1];
                $inServicesSection = ($sectionName === 'services');
                $inServiceVolumes = false;
                $inServiceBuild = false;
                $inBuildArgs = false;
                $currentService = null;
                $currentVolumes = [];
                $currentFileMounts = [];
                $currentBuildContext = null;
                $currentDockerfile = null;
                $currentBuildArgs = [];
                continue;
            }

            // Detect service definition (only when in services section)
            if ($inServicesSection && preg_match("/^([a-z0-9_-]+):\s*$/", $stripped, $serviceMatch) && $currentIndent == 2) {
                // Save previous service's data
                if ($currentService && (!empty($currentVolumes) || !empty($currentFileMounts))) {
                    $serviceOverrides[$currentService] = [
                        'volumes' => $currentVolumes,
                        'fileMounts' => $currentFileMounts,
                        'buildContext' => $currentBuildContext,
                        'dockerfile' => $currentDockerfile,
                        'buildArgs' => $currentBuildArgs,
                    ];
                }

                $currentService = $serviceMatch[1];
                $inServiceVolumes = false;
                $inServiceBuild = false;
                $inBuildArgs = false;
                $currentVolumes = [];
                $currentFileMounts = [];
                $currentBuildContext = null;
                $currentDockerfile = null;
                $currentBuildArgs = [];
                continue;
            }

            // Detect service build section (can be string or object)
            if ($inServicesSection && $currentService && preg_match("/^build:\s*(.*)$/", $stripped, $buildMatch) && $currentIndent >= 2 && $currentIndent <= 6) {
                $buildValue = trim($buildMatch[1]);
                if (!empty($buildValue)) {
                    // String form: build: ./path
                    $currentBuildContext = trim($buildValue, "\"'");
                    $inServiceBuild = false;
                } else {
                    // Object form: build:\n  context: ...
                    $inServiceBuild = true;
                    $buildIndent = $currentIndent;
                }
                continue;
            }

            // Parse build object properties (context, dockerfile, args)
            if ($inServiceBuild && $currentIndent > $buildIndent) {
                // Check if we exited the args section
                if ($inBuildArgs && $currentIndent <= $buildArgsIndent && !preg_match("/^#/", $stripped)) {
                    $inBuildArgs = false;
                }

                if (preg_match("/^context:\s*(.+)$/", $stripped, $contextMatch)) {
                    $currentBuildContext = trim($contextMatch[1], "\"'");
                } elseif (preg_match("/^dockerfile:\s*(.+)$/", $stripped, $dockerfileMatch)) {
                    $currentDockerfile = trim($dockerfileMatch[1], "\"'");
                } elseif (preg_match("/^args:\s*$/", $stripped)) {
                    $inBuildArgs = true;
                    $buildArgsIndent = $currentIndent;
                } elseif ($inBuildArgs && $currentIndent > $buildArgsIndent) {
                    // Parse build arg: ARG_NAME: value or ARG_NAME: ${ENV_VAR:-default}
                    if (preg_match("/^([A-Za-z_][A-Za-z0-9_]*):\s*(.+)$/", $stripped, $argMatch)) {
                        $argName = $argMatch[1];
                        $argValue = trim($argMatch[2], "\"'");

                        // Extract default from ${VAR:-default} pattern
                        if (preg_match('/^\$\{[^:}]+(:-([^}]+))?\}$/', $argValue, $defaultMatch)) {
                            $currentBuildArgs[$argName] = $defaultMatch[2] ?? null;
                        } else {
                            $currentBuildArgs[$argName] = $argValue;
                        }
                    }
                }
                continue;
            }

            // Check if we exited the build section
            if ($inServiceBuild && $stripped && $currentIndent <= $buildIndent && !preg_match("/^#/", $stripped)) {
                $inServiceBuild = false;
            }

            // Detect service volumes section
            if ($inServicesSection && $currentService && preg_match("/^volumes:\s*$/", $stripped) && $currentIndent >= 2 && $currentIndent <= 6) {
                $inServiceVolumes = true;
                $volumesIndent = $currentIndent;
                continue;
            }

            // Check if we exited the volumes section
            if ($inServiceVolumes && $stripped && !preg_match("/^#/", $stripped)) {
                if ($currentIndent <= $volumesIndent && !preg_match("/^-/", $stripped)) {
                    $inServiceVolumes = false;
                }
            }

            // Process volume mount entries
            if ($inServiceVolumes && preg_match("/^-\s*[\"']?([^\"']+)[\"']?$/", $stripped, $matches)) {
                $mount = trim($matches[1], "\"'");

                if (strpos($mount, ":") !== false) {
                    $parts = explode(":", $mount);
                    $source = $parts[0];
                    $target = $parts[1];
                    // Strip mount options (:ro, :rw, :z, :Z, :cached, :delegated, :consistent)
                    // These are in $parts[2] if present, but we just ignore them

                    // Check if bind mount (starts with . or ./)
                    if (substr($source, 0, 1) === ".") {
                        // Convert relative path to subpath
                        if ($source === ".") {
                            // Current directory
                            $subpath = $relativeDirFromWorkspace;
                        } elseif (substr($source, 0, 2) === "./") {
                            // Explicit relative path: ./path/to/file
                            $subpath = $relativeDirFromWorkspace . "/" . substr($source, 2);
                        } else {
                            // Dotfile in current directory: .env, .gitignore, etc.
                            $subpath = $relativeDirFromWorkspace . "/" . $source;
                        }

                        // Normalize path
                        $subpath = preg_replace('#/+#', '/', $subpath);
                        $subpath = trim($subpath, '/');

                        // Check if source looks like a file (has extension)
                        $isFile = preg_match("/\.[a-zA-Z0-9]+$/", $source);

                        if ($isFile) {
                            // For files, mount parent to staging location
                            $pathParts = explode("/", $subpath);
                            $filename = array_pop($pathParts);
                            $parentSubpath = implode("/", $pathParts);

                            // Use hash of target path for unique staging location
                            $hash = substr(md5($target), 0, 6);
                            $stagingTarget = "/pocketdev-stage-{$hash}";

                            $currentVolumes[] = [
                                'type' => 'volume',
                                'source' => self::VOLUME_NAME,
                                'target' => $stagingTarget,
                                'subpath' => $parentSubpath,
                            ];

                            $currentFileMounts[] = [
                                'staging' => $stagingTarget,
                                'target' => $target,
                                'filename' => $filename,
                                'originalSource' => $source, // Keep original for Dockerfile COPY
                            ];

                            $mountsConverted++;
                            $fileMountsCount++;
                        } else {
                            // Directory mount
                            $currentVolumes[] = [
                                'type' => 'volume',
                                'source' => self::VOLUME_NAME,
                                'target' => $target,
                                'subpath' => $subpath,
                            ];
                            $mountsConverted++;
                        }
                    } else {
                        // Named volume - keep as-is
                        $currentVolumes[] = [
                            'type' => 'named',
                            'raw' => $mount,
                        ];
                    }
                }
            }
        }

        // Save last service's data
        if ($currentService && (!empty($currentVolumes) || !empty($currentFileMounts))) {
            $serviceOverrides[$currentService] = [
                'volumes' => $currentVolumes,
                'fileMounts' => $currentFileMounts,
                'buildContext' => $currentBuildContext,
                'dockerfile' => $currentDockerfile,
                'buildArgs' => $currentBuildArgs,
            ];
        }

        // Build the override file (only contains overrides, not full content)
        $result = $this->buildOverrideFile($serviceOverrides, $composeDir);

        return [
            'output' => $result['yaml'],
            'error' => null,
            'services_transformed' => count($serviceOverrides),
            'mounts_converted' => $mountsConverted,
            'file_mounts' => $fileMountsCount,
            'warnings' => $warnings,
            'generated_dockerfiles' => $result['generatedDockerfiles'],
        ];
    }

    /**
     * Build the override YAML file from collected service overrides.
     *
     * For services with file mounts, this method attempts to use build-time file injection
     * by generating a modified Dockerfile (Dockerfile.pocketdev) with COPY instructions
     * before the USER directive. This allows the container to start directly as the
     * correct user with properly initialized file descriptors.
     *
     * Falls back to runtime copy approach if no USER directive is found in the Dockerfile.
     *
     * @param array $serviceOverrides Service override data including volumes, fileMounts, buildContext, dockerfile
     * @param string $composeDir Absolute path to directory containing compose file
     * @return array{yaml: string, generatedDockerfiles: array<string>} The override YAML and list of generated Dockerfiles
     */
    private function buildOverrideFile(array $serviceOverrides, string $composeDir): array
    {
        $lines = [];
        $generatedDockerfiles = [];

        if (!empty($serviceOverrides)) {
            $lines[] = "services:";

            foreach ($serviceOverrides as $serviceName => $overrides) {
                $lines[] = "  {$serviceName}:";

                $handledViaDockerfile = false;
                $volumesToAdd = $overrides['volumes'] ?? [];

                // Collect directory mount targets to detect conflicts with file mounts
                $directoryMountTargets = [];
                foreach ($volumesToAdd as $vol) {
                    if ($vol['type'] === 'named') {
                        // Extract target from named volume's raw format (e.g., "data:/var/www" → "/var/www")
                        $parts = explode(':', $vol['raw']);
                        if (isset($parts[1])) {
                            $directoryMountTargets[] = rtrim($parts[1], '/');
                        }
                    } elseif (!str_starts_with($vol['target'], '/pocketdev-stage-')) {
                        $directoryMountTargets[] = rtrim($vol['target'], '/');
                    }
                }

                // Split file mounts into safe (can use Dockerfile COPY) and conflicting (must use runtime copy)
                // A file mount conflicts if its target is inside a directory that's also being volume-mounted,
                // because the volume mount at runtime would overwrite the file we COPY at build time
                $safeFileMounts = [];
                $conflictingFileMounts = [];
                foreach ($overrides['fileMounts'] ?? [] as $mount) {
                    $isConflicting = false;
                    foreach ($directoryMountTargets as $dirTarget) {
                        if (str_starts_with($mount['target'], $dirTarget . '/')) {
                            $isConflicting = true;
                            break;
                        }
                    }
                    if ($isConflicting) {
                        $conflictingFileMounts[] = $mount;
                    } else {
                        $safeFileMounts[] = $mount;
                    }
                }

                // Try to handle SAFE file mounts via Dockerfile modification
                if (!empty($safeFileMounts) && !empty($overrides['buildContext'])) {
                    $buildContext = $overrides['buildContext'];
                    $originalDockerfile = $overrides['dockerfile'] ?? 'Dockerfile';

                    $dockerfilePath = $this->resolveDockerfilePath($composeDir, $buildContext, $originalDockerfile);

                    if ($dockerfilePath && file_exists($dockerfilePath)) {
                        // Convert file mounts to COPY-compatible format (source relative to build context)
                        $copyMounts = [];
                        foreach ($safeFileMounts as $mount) {
                            // The 'source' in fileMounts is the original bind mount source (e.g., ./iac/containers/php/shared/conf.d/overwrite.ini)
                            // We need to use this directly as the COPY source (relative to build context)
                            $copyMounts[] = [
                                'source' => $mount['originalSource'],
                                'target' => $mount['target'],
                            ];
                        }

                        $modifiedContent = $this->generateModifiedDockerfile($dockerfilePath, $copyMounts);

                        if ($modifiedContent !== null) {
                            // Resolve build context to absolute path
                            if (str_starts_with($buildContext, './')) {
                                $buildContextPath = $composeDir . '/' . substr($buildContext, 2);
                            } elseif (str_starts_with($buildContext, '/')) {
                                $buildContextPath = $buildContext;
                            } else {
                                $buildContextPath = $composeDir . '/' . $buildContext;
                            }
                            $buildContextPath = rtrim($buildContextPath, '/');

                            // Write Dockerfile.pocketdev
                            $newDockerfilePath = $buildContextPath . '/Dockerfile.pocketdev';
                            $written = file_put_contents($newDockerfilePath, $modifiedContent);
                            if ($written !== false) {
                                $generatedDockerfiles[] = $newDockerfilePath;

                                // Add build section pointing to new Dockerfile
                                $lines[] = "    build:";
                                $lines[] = "      context: {$buildContext}";
                                $lines[] = "      dockerfile: Dockerfile.pocketdev";

                                // Remove staging volumes for SAFE file mounts only - those files are now in the image
                                // Keep staging volumes for conflicting file mounts (they need runtime copy)
                                $conflictingStagingTargets = array_map(fn($m) => $m['staging'], $conflictingFileMounts);
                                $volumesToAdd = array_filter($volumesToAdd, function ($vol) use ($conflictingStagingTargets) {
                                    if ($vol['type'] === 'named') {
                                        return true;
                                    }
                                    if (!str_starts_with($vol['target'], '/pocketdev-stage-')) {
                                        return true;
                                    }
                                    // This is a staging volume - keep it only if it's for a conflicting file mount
                                    return in_array($vol['target'], $conflictingStagingTargets);
                                });

                                $handledViaDockerfile = true;
                            }
                            // On write failure, $handledViaDockerfile stays false → falls through to runtime copy
                        }
                    }
                }

                // Add volumes override (excluding staging volumes if handled via Dockerfile)
                if (!empty($volumesToAdd)) {
                    $lines[] = "    volumes: !override";
                    foreach ($volumesToAdd as $vol) {
                        if ($vol['type'] === 'named') {
                            $lines[] = "      - {$vol['raw']}";
                        } else {
                            $lines[] = "      - type: volume";
                            $lines[] = "        source: {$vol['source']}";
                            $lines[] = "        target: {$vol['target']}";
                            $lines[] = "        volume:";
                            $lines[] = "          subpath: {$vol['subpath']}";
                        }
                    }
                }

                // Runtime copy for:
                // 1. Conflicting file mounts (target inside a directory mount) - ALWAYS need runtime copy
                // 2. Safe file mounts if Dockerfile modification failed - fallback to runtime copy
                $fileMountsForRuntimeCopy = $conflictingFileMounts;
                if (!$handledViaDockerfile) {
                    $fileMountsForRuntimeCopy = array_merge($fileMountsForRuntimeCopy, $safeFileMounts);
                }

                if (!empty($fileMountsForRuntimeCopy)) {
                    $copyCommands = [];
                    foreach ($fileMountsForRuntimeCopy as $mount) {
                        $source = escapeshellarg("{$mount['staging']}/{$mount['filename']}");
                        $target = escapeshellarg($mount['target']);
                        $copyCommands[] = "cp {$source} {$target}";
                    }

                    $metadata = $this->getDockerfileMetadata(
                        $composeDir,
                        $overrides['buildContext'] ?? null,
                        $overrides['dockerfile'] ?? null,
                        $overrides['buildArgs'] ?? []
                    );

                    $serviceCommand = $metadata['cmd'] ?? $this->getServiceCommand($serviceName);
                    $originalUser = $metadata['user'];

                    $copyScript = implode(" && ", $copyCommands);
                    $lines[] = "    user: root";

                    $needsPrivilegeDrop = $originalUser !== null
                        && $originalUser !== 'root'
                        && $originalUser !== '0'
                        && !str_starts_with($originalUser, '$')
                        && !ctype_digit($originalUser);

                    if ($needsPrivilegeDrop) {
                        $escapedUser = escapeshellarg($originalUser);
                        $escapedCmd = escapeshellarg($serviceCommand);
                        $entrypoint = [
                            "/bin/sh",
                            "-c",
                            "{$copyScript} && exec su -s /bin/sh {$escapedUser} -c {$escapedCmd}"
                        ];
                    } else {
                        $entrypoint = ["/bin/sh", "-c", "{$copyScript} && exec {$serviceCommand}"];
                    }

                    $lines[] = "    entrypoint: " . json_encode($entrypoint, JSON_UNESCAPED_SLASHES);
                }
            }
        }

        // Add external volume declaration
        $lines[] = "";
        $lines[] = "volumes:";
        $lines[] = "  " . self::VOLUME_NAME . ":";
        $lines[] = "    external: true";

        return [
            'yaml' => implode("\n", $lines),
            'generatedDockerfiles' => $generatedDockerfiles,
        ];
    }

    /**
     * Get the service command from the Dockerfile CMD instruction.
     *
     * @param string $composeDir Absolute path to directory containing compose file
     * @param string|null $buildContext Build context path (relative to compose dir)
     * @param string|null $dockerfile Dockerfile path (relative to build context)
     * @return string|null The CMD as a shell command string, or null if not found
     */
    private function getServiceCommandFromDockerfile(string $composeDir, ?string $buildContext, ?string $dockerfile): ?string
    {
        if ($buildContext === null) {
            return null;
        }

        // Resolve the Dockerfile path
        $dockerfilePath = $this->resolveDockerfilePath($composeDir, $buildContext, $dockerfile ?? 'Dockerfile');
        if ($dockerfilePath === null || !file_exists($dockerfilePath)) {
            return null;
        }

        return $this->extractDockerfileCmd($dockerfilePath);
    }

    /**
     * Parse a Dockerfile and return its structure including the USER directive location.
     *
     * @param string $dockerfilePath Absolute path to Dockerfile
     * @return array{content: string, userLine: ?int, cmdLine: ?int, entrypointLine: ?int, lines: array<string>}
     */
    private function parseDockerfileStructure(string $dockerfilePath): array
    {
        $content = file_get_contents($dockerfilePath);
        if ($content === false) {
            return ['content' => '', 'userLine' => null, 'cmdLine' => null, 'entrypointLine' => null, 'lines' => []];
        }

        $lines = explode("\n", $content);
        $userLine = null;
        $cmdLine = null;
        $entrypointLine = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            // Match USER directive (case-insensitive)
            // We want the LAST of each directive (don't break)
            if (preg_match('/^USER\s+/i', $trimmed)) {
                $userLine = $index; // 0-indexed
            }
            if (preg_match('/^CMD\s+/i', $trimmed)) {
                $cmdLine = $index;
            }
            if (preg_match('/^ENTRYPOINT\s+/i', $trimmed)) {
                $entrypointLine = $index;
            }
        }

        return [
            'content' => $content,
            'userLine' => $userLine,
            'cmdLine' => $cmdLine,
            'entrypointLine' => $entrypointLine,
            'lines' => $lines,
        ];
    }

    /**
     * Generate a modified Dockerfile with COPY instructions inserted at an appropriate location.
     *
     * Insertion point priority:
     * 1. Before USER directive (preferred - files copied as root before privilege drop)
     * 2. Before CMD directive
     * 3. Before ENTRYPOINT directive
     * 4. At end of file (last resort)
     *
     * This allows files to be copied at build time, avoiding runtime privilege issues.
     *
     * @param string $dockerfilePath Absolute path to original Dockerfile
     * @param array $fileMounts Array of file mount info with 'source' (relative to build context) and 'target' keys
     * @return string|null Modified Dockerfile content, or null on read failure
     */
    private function generateModifiedDockerfile(string $dockerfilePath, array $fileMounts): ?string
    {
        $structure = $this->parseDockerfileStructure($dockerfilePath);

        if (empty($structure['lines'])) {
            return null;
        }

        $lines = $structure['lines'];

        // Find insertion point in order of preference:
        // 1. Before USER (preferred - copy as root before dropping privileges)
        // 2. Before CMD
        // 3. Before ENTRYPOINT
        // 4. End of file
        $insertionLine = $structure['userLine']
            ?? $structure['cmdLine']
            ?? $structure['entrypointLine']
            ?? count($lines);

        // Sanity check: insertion at line 0 would mean no FROM before it
        if ($insertionLine === 0) {
            $insertionLine = count($lines);
        }

        // Build COPY instructions (using JSON array syntax to handle paths with spaces)
        $copyInstructions = ["", "# === PocketDev file injection (generated) ==="];
        foreach ($fileMounts as $mount) {
            $source = $mount['source'];
            $target = $mount['target'];
            $copyInstructions[] = "COPY " . json_encode([$source, $target], JSON_UNESCAPED_SLASHES);
        }
        $copyInstructions[] = "# === End PocketDev file injection ===";

        // Insert COPY instructions at the chosen location
        array_splice($lines, $insertionLine, 0, $copyInstructions);

        return implode("\n", $lines);
    }

    /**
     * Resolve the absolute path to a Dockerfile.
     *
     * @param string $composeDir Absolute path to directory containing compose file
     * @param string $buildContext Build context path (relative to compose dir, e.g., "./" or "./docker")
     * @param string $dockerfile Dockerfile path (relative to build context)
     * @return string|null Absolute path to Dockerfile, or null if cannot resolve
     */
    private function resolveDockerfilePath(string $composeDir, string $buildContext, string $dockerfile): ?string
    {
        // Resolve build context relative to compose directory
        if (str_starts_with($buildContext, './')) {
            $contextPath = $composeDir . '/' . substr($buildContext, 2);
        } elseif (str_starts_with($buildContext, '/')) {
            $contextPath = $buildContext;
        } else {
            $contextPath = $composeDir . '/' . $buildContext;
        }

        // Normalize path
        $contextPath = rtrim($contextPath, '/');

        // Resolve dockerfile relative to build context
        if (str_starts_with($dockerfile, '/')) {
            $dockerfilePath = $dockerfile;
        } else {
            $dockerfilePath = $contextPath . '/' . $dockerfile;
        }

        // Normalize and check if path exists
        $realPath = realpath($dockerfilePath);
        return $realPath ?: null;
    }

    /**
     * Extract the CMD instruction from a Dockerfile.
     *
     * Handles both exec form (CMD ["executable", "param1"]) and shell form (CMD command param1).
     * Returns the last CMD found (Docker behavior).
     *
     * @param string $dockerfilePath Absolute path to Dockerfile
     * @return string|null The CMD as a shell command string, or null if not found
     */
    private function extractDockerfileCmd(string $dockerfilePath): ?string
    {
        $content = file_get_contents($dockerfilePath);
        if ($content === false) {
            return null;
        }

        $lines = explode("\n", $content);
        $cmd = null;
        $continuationBuffer = '';

        foreach ($lines as $line) {
            // Handle line continuations
            $trimmedLine = trim($line);

            // Skip comments and empty lines
            if (empty($trimmedLine) || str_starts_with($trimmedLine, '#')) {
                continue;
            }

            // Check for line continuation
            if (str_ends_with($trimmedLine, '\\')) {
                $continuationBuffer .= rtrim($trimmedLine, '\\') . ' ';
                continue;
            }

            // Complete the line
            $fullLine = $continuationBuffer . $trimmedLine;
            $continuationBuffer = '';

            // Check for CMD instruction (case insensitive)
            if (preg_match('/^CMD\s+(.+)$/i', $fullLine, $matches)) {
                $cmdValue = trim($matches[1]);
                $cmd = $this->parseCmdValue($cmdValue);
            }
        }

        return $cmd;
    }

    /**
     * Extract the USER instruction from a Dockerfile, substituting build args.
     *
     * Handles USER username, USER $VAR, USER ${VAR}. For USER user:group format,
     * only extracts the user portion (group is not used for privilege dropping).
     * Returns the last USER found (Docker behavior).
     *
     * @param string $dockerfilePath Absolute path to Dockerfile
     * @param array $buildArgs Build arguments from compose.yml for variable substitution
     * @return string|null The resolved username (without group), or null if not found
     */
    private function extractDockerfileUser(string $dockerfilePath, array $buildArgs = []): ?string
    {
        $content = file_get_contents($dockerfilePath);
        if ($content === false) {
            return null;
        }

        $lines = explode("\n", $content);
        $user = null;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Skip comments and empty lines
            if (empty($trimmedLine) || str_starts_with($trimmedLine, '#')) {
                continue;
            }

            // Match USER instruction, capturing only the user portion (before any colon)
            // USER user:group → captures "user", USER 1000:1000 → captures "1000"
            if (preg_match('/^USER\s+([^\s:]+)/i', $trimmedLine, $matches)) {
                $rawUser = trim($matches[1]);

                // Substitute build args: $USER or ${USER}
                if (preg_match('/^\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?$/', $rawUser, $varMatch)) {
                    $varName = $varMatch[1];
                    if (isset($buildArgs[$varName])) {
                        $user = $buildArgs[$varName];
                    } else {
                        // Variable not found in build args - keep raw (will be skipped later)
                        $user = $rawUser;
                    }
                } else {
                    $user = $rawUser;
                }
            }
        }

        return $user;
    }

    /**
     * Get metadata from the Dockerfile (CMD and USER).
     *
     * @param string $composeDir Absolute path to directory containing compose file
     * @param string|null $buildContext Build context path (relative to compose dir)
     * @param string|null $dockerfile Dockerfile path (relative to build context)
     * @param array $buildArgs Build arguments for variable substitution
     * @return array{cmd: ?string, user: ?string}
     */
    private function getDockerfileMetadata(string $composeDir, ?string $buildContext, ?string $dockerfile, array $buildArgs = []): array
    {
        $result = ['cmd' => null, 'user' => null];

        if ($buildContext === null) {
            return $result;
        }

        $dockerfilePath = $this->resolveDockerfilePath($composeDir, $buildContext, $dockerfile ?? 'Dockerfile');
        if ($dockerfilePath === null || !file_exists($dockerfilePath)) {
            return $result;
        }

        $result['cmd'] = $this->extractDockerfileCmd($dockerfilePath);
        $result['user'] = $this->extractDockerfileUser($dockerfilePath, $buildArgs);

        return $result;
    }

    /**
     * Parse a CMD value from a Dockerfile into a shell command string.
     *
     * @param string $cmdValue The CMD value (either exec form or shell form)
     * @return string The command as a shell string
     */
    private function parseCmdValue(string $cmdValue): string
    {
        // Check for exec form: ["executable", "param1", "param2"]
        if (str_starts_with($cmdValue, '[') && str_ends_with($cmdValue, ']')) {
            $jsonArray = json_decode($cmdValue, true);
            if (is_array($jsonArray)) {
                // Quote each argument to preserve shell semantics and prevent injection
                // This is necessary because the result is embedded in sh -c "... && {$cmd}"
                $quotedArgs = array_map(function ($arg) {
                    // Only quote if contains shell metacharacters or whitespace
                    if (preg_match('/[\s;&|<>()$`"\'\\\*?#~=%]/', $arg)) {
                        return escapeshellarg($arg);
                    }
                    return $arg;
                }, $jsonArray);
                return implode(' ', $quotedArgs);
            }
        }

        // Shell form: just return as-is
        return $cmdValue;
    }

    /**
     * Get the appropriate default command for a service based on its name.
     * This is a fallback when no Dockerfile CMD is available.
     */
    private function getServiceCommand(string $serviceName): string
    {
        if (strpos($serviceName, 'php') !== false) {
            return 'php-fpm';
        } elseif (strpos($serviceName, 'nginx') !== false) {
            return "nginx -g 'daemon off;'";
        } elseif (strpos($serviceName, 'mariadb') !== false) {
            return 'docker-entrypoint.sh mariadbd';
        } elseif (strpos($serviceName, 'mysql') !== false) {
            return 'docker-entrypoint.sh mysqld';
        } elseif (strpos($serviceName, 'redis') !== false) {
            return 'docker-entrypoint.sh redis-server';
        } elseif (strpos($serviceName, 'node') !== false) {
            return 'node';
        }
        return 'sh';
    }
}
