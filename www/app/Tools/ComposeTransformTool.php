<?php

namespace App\Tools;

/**
 * Transform Docker Compose files to use PocketDev workspace volume mounts.
 *
 * Converts bind mounts (./path:/target) to volume mounts with subpath,
 * creating a compose.override.yaml that Docker Compose auto-merges.
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
        $result = $this->transform($content, $relativeDirFromWorkspace);

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
     * @return array{output: string, error: ?string, services_transformed: int, mounts_converted: int, file_mounts: int, warnings: array}
     */
    private function transform(string $content, string $relativeDirFromWorkspace): array
    {
        $lines = explode("\n", $content);

        // Track sections and state
        $inServicesSection = false;
        $inServiceVolumes = false;
        $currentService = null;
        $volumesIndent = 0;

        // Collect service overrides: service => [volumes => [...], fileMounts => [...]]
        $serviceOverrides = [];
        $currentVolumes = [];
        $currentFileMounts = [];

        // Stats
        $mountsConverted = 0;
        $fileMountsCount = 0;
        $warnings = [];

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $stripped = ltrim($line);
            $currentIndent = strlen($line) - strlen($stripped);

            // Detect top-level sections
            if ($currentIndent == 0 && preg_match("/^([a-z]+):\s*$/", $stripped, $sectionMatch)) {
                // Save previous service's data before switching sections
                if ($currentService && (!empty($currentVolumes) || !empty($currentFileMounts))) {
                    $serviceOverrides[$currentService] = [
                        'volumes' => $currentVolumes,
                        'fileMounts' => $currentFileMounts,
                    ];
                }

                $sectionName = $sectionMatch[1];
                $inServicesSection = ($sectionName === 'services');
                $inServiceVolumes = false;
                $currentService = null;
                $currentVolumes = [];
                $currentFileMounts = [];
                continue;
            }

            // Detect service definition (only when in services section)
            if ($inServicesSection && preg_match("/^([a-z0-9_-]+):\s*$/", $stripped, $serviceMatch) && $currentIndent == 2) {
                // Save previous service's data
                if ($currentService && (!empty($currentVolumes) || !empty($currentFileMounts))) {
                    $serviceOverrides[$currentService] = [
                        'volumes' => $currentVolumes,
                        'fileMounts' => $currentFileMounts,
                    ];
                }

                $currentService = $serviceMatch[1];
                $inServiceVolumes = false;
                $currentVolumes = [];
                $currentFileMounts = [];
                continue;
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
            ];
        }

        // Build the override file (only contains overrides, not full content)
        $output = $this->buildOverrideFile($serviceOverrides);

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
     */
    private function buildOverrideFile(array $serviceOverrides): string
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
                        $copyCommands[] = "cp {$mount['staging']}/{$mount['filename']} {$mount['target']}";
                    }
                    $serviceCommand = $this->getServiceCommand($serviceName);
                    $copyScript = implode(" && ", $copyCommands);

                    $lines[] = "    user: root";
                    $lines[] = "    entrypoint: [\"/bin/sh\", \"-c\", \"{$copyScript} && {$serviceCommand}\"]";
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
     * Get the appropriate default command for a service based on its name.
     */
    private function getServiceCommand(string $serviceName): string
    {
        if (strpos($serviceName, 'php') !== false) {
            return 'php-fpm';
        } elseif (strpos($serviceName, 'nginx') !== false) {
            return "nginx -g 'daemon off;'";
        } elseif (strpos($serviceName, 'mariadb') !== false || strpos($serviceName, 'mysql') !== false) {
            return 'docker-entrypoint.sh mariadbd';
        } elseif (strpos($serviceName, 'redis') !== false) {
            return 'docker-entrypoint.sh redis-server';
        } elseif (strpos($serviceName, 'node') !== false) {
            return 'node';
        }
        return 'sh';
    }
}
