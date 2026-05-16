<?php

namespace App\Tools;

/**
 * Manage system packages (CLI tools, libraries) installed in the container.
 *
 * This tool provides documentation only - actual execution is via the pd CLI.
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
                'description' => 'list, add, update, or remove',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Display name (e.g., "Azure CLI", "jq"). Required for add.',
            ],
            'cli_commands' => [
                'type' => 'string',
                'description' => 'CLI command(s) to invoke, comma-separated (e.g., "mgc" or "libreoffice, soffice"). This is what appears in the AI prompt. Required for add.',
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
- Packages install immediately when added, no restart needed
- Installation runs as root via `docker exec`, so `apt-get` and `curl | bash` scripts work
- Installation may take 1-2 minutes for large packages (e.g., Chromium)
- Scripts run non-interactively - no prompts allowed
- Verify download URLs before adding: `curl -fsSI <url>` to check for 404s
- Check archive contents first: `tar -tzf` or `unzip -l` before writing extraction paths
- If installation fails, the user can retry by restarting containers (Developer tab in menu)
- Packages are automatically reinstalled on container restart (they persist in the database)
INSTRUCTIONS;

    public ?string $cliExamples = <<<'CLI'
## CLI Examples

```bash
# List packages
pd system:package list

# Add apt package (installs immediately)
pd system:package add --name="jq" --cli_commands="jq" --install_script="apt-get update -qq && apt-get install -y -qq jq"

# Add package where CLI command differs from name
pd system:package add --name="Azure CLI" --cli_commands="az" --install_script="curl -sL https://aka.ms/InstallAzureCLIDeb | bash"

# Add package with multiple CLI commands
pd system:package add --name="LibreOffice" --cli_commands="libreoffice, soffice" --install_script="apt-get update -qq && apt-get install -y -qq libreoffice"

# Update existing package CLI commands
pd system:package update --name="Microsoft Graph CLI" --cli_commands="mgc"

# Update install script (reinstalls immediately)
pd system:package update --name="extract-msg" --install_script="pip3 install extract-msg && ln -sf ~/.local/bin/extract_msg /usr/local/bin/"

# Remove package
pd system:package remove --id="uuid-here"
pd system:package remove --name="jq"
```

Packages install immediately when added or updated. If installation fails, tell user: "Restart containers via Developer tab in menu to retry."
CLI;

    /**
     * This tool is documentation-only for CLI providers.
     * Execution happens via the pd CLI.
     */
    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        return ToolResult::error('This tool should be invoked via: pd system:package <action>');
    }
}
