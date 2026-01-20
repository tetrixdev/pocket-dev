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
3. Handles file mounts via staging directory + entrypoint copy
4. Creates `compose.override.yaml` in the same directory (original unchanged)

**Important:** The input path must be within `/workspace/` so the tool can calculate the correct subpath for volume mounts.

**Known Limitations:**
- File vs directory detection uses extension-based heuristics. Directories with extensions (e.g., `./configs.d`) may be misclassified as files, and files without extensions (e.g., `./Makefile`) may be misclassified as directories.
- Service command guessing assumes standard naming (php, nginx, mysql, redis, node). Services with custom names or entrypoints may need manual adjustment in the override file.

**After running this tool:** Read both the original compose file and the generated override file. Warn the user if any volume mounts might be affected by the limitations above (e.g., extensionless files, directories with extensions, or non-standard service names with file mounts). Suggest manual adjustments if needed.
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
                    $parts = explode(":", $mount, 2);
                    $source = $parts[0];
                    $target = $parts[1];

                    // Check if bind mount (starts with .)
                    if (substr($source, 0, 1) === ".") {
                        // Convert ./path to subpath
                        if ($source === ".") {
                            $subpath = $relativeDirFromWorkspace;
                        } else {
                            $subpath = $relativeDirFromWorkspace . "/" . substr($source, 2);
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
        $output = $this->buildOverrideFile($serviceOverrides, $composeDir);

        return [
            'output' => $output,
            'error' => null,
            'services_transformed' => count($serviceOverrides),
            'mounts_converted' => $mountsConverted,
            'file_mounts' => $fileMountsCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Build the override YAML file from collected service overrides.
     *
     * @param array $serviceOverrides Service override data including volumes, fileMounts, buildContext, dockerfile
     * @param string $composeDir Absolute path to directory containing compose file
     */
    private function buildOverrideFile(array $serviceOverrides, string $composeDir): string
    {
        $lines = [];

        if (!empty($serviceOverrides)) {
            $lines[] = "services:";

            foreach ($serviceOverrides as $serviceName => $overrides) {
                $lines[] = "  {$serviceName}:";

                // Add volumes override
                if (!empty($overrides['volumes'])) {
                    $lines[] = "    volumes: !override";
                    foreach ($overrides['volumes'] as $vol) {
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

                // Add entrypoint override for file mounts
                if (!empty($overrides['fileMounts'])) {
                    $copyCommands = [];
                    foreach ($overrides['fileMounts'] as $mount) {
                        // Use escapeshellarg to handle filenames with spaces or special characters
                        $source = escapeshellarg("{$mount['staging']}/{$mount['filename']}");
                        $target = escapeshellarg($mount['target']);
                        $copyCommands[] = "cp {$source} {$target}";
                    }

                    // Get Dockerfile metadata (CMD and USER) with build arg substitution
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

                    // Check if we need privilege dropping
                    // Skip if: no user, user is root, or user is still a variable (substitution failed)
                    $needsPrivilegeDrop = $originalUser !== null
                        && $originalUser !== 'root'
                        && !str_starts_with($originalUser, '$');

                    if ($needsPrivilegeDrop) {
                        // Drop privileges after copy using su
                        // -s /bin/sh handles users with /sbin/nologin as their shell
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

        return implode("\n", $lines);
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
     * Handles USER username, USER $VAR, USER ${VAR}, USER uid:gid.
     * Returns the last USER found (Docker behavior).
     *
     * @param string $dockerfilePath Absolute path to Dockerfile
     * @param array $buildArgs Build arguments from compose.yml for variable substitution
     * @return string|null The resolved username, or null if not found
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

            // Match: USER username, USER $VAR, USER ${VAR}, USER uid:gid
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
