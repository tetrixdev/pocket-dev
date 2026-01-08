<?php

namespace App\Tools;

/**
 * Manage system packages (CLI tools, libraries) installed in the container.
 *
 * This tool provides documentation only - actual execution is via artisan command.
 */
class SystemPackageTool extends Tool
{
    public string $name = 'SystemPackage';

    public string $description = 'Add or remove system packages installed in the container.';

    public string $category = 'tools';

    public array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'description' => 'list, add, or remove',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Display name (e.g., "Azure CLI", "jq"). Required for add.',
            ],
            'install_script' => [
                'type' => 'string',
                'description' => 'Bash script to run. Required for add. Must be non-interactive - use silent flags: -y, -qq, --yes, --quiet.',
            ],
            'id' => [
                'type' => 'string',
                'description' => 'Package UUID. For remove: use either --id or --name.',
            ],
        ],
        'required' => ['action'],
    ];

    public function getArtisanCommand(): ?string
    {
        return 'system:package';
    }

    public ?string $instructions = <<<'INSTRUCTIONS'
Add system packages (CLI tools, libraries) to be installed in the container.

**When to use:** If you need a CLI tool (like `az`, `aws`, `hcloud`) or library that isn't installed.

**Important:**
- Packages install on container restart (user must restart via Developer tab in menu)
- Scripts run non-interactively - no prompts allowed
- You do NOT have root access. You cannot test install scripts directly. They run as root during container startup.
- Verify download URLs before adding: `curl -fsSI <url>` to check for 404s
- Check archive contents first: `tar -tzf` or `unzip -l` before writing extraction paths
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Examples

```bash
# List packages
php artisan system:package list

# Add apt package
php artisan system:package add --name="jq" --install_script="apt-get update -qq && apt-get install -y -qq jq"

# Add Azure CLI
php artisan system:package add --name="Azure CLI" --install_script="curl -sL https://aka.ms/InstallAzureCLIDeb | bash"

# Add from GitHub release
php artisan system:package add --name="GitHub CLI" --install_script="curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg && echo 'deb [arch=amd64 signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main' | tee /etc/apt/sources.list.d/github-cli.list && apt-get update -qq && apt-get install -y -qq gh"

# Remove package
php artisan system:package remove --id="uuid-here"
php artisan system:package remove --name="jq"
```

After adding packages, tell user: "Restart containers via Developer tab in menu to install."
CLI;

    /**
     * This tool is documentation-only for CLI providers.
     * Execution happens via direct artisan command.
     */
    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        return ToolResult::error('This tool should be invoked via artisan command: php artisan system:package <action>');
    }
}
