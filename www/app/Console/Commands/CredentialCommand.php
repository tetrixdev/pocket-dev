<?php

namespace App\Console\Commands;

use App\Models\Credential;
use Illuminate\Console\Command;

class CredentialCommand extends Command
{
    protected $signature = 'credential
        {action : The action to perform (export)}';

    protected $description = 'Manage credentials for container environment';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'export' => $this->exportCredentials(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Export all credentials as shell export statements.
     * Used by container entrypoint to inject credentials into environment.
     *
     * Output format (one per line):
     *   export GITHUB_TOKEN="value"
     *   export HETZNER_TOKEN="value"
     */
    private function exportCredentials(): int
    {
        // Only export global credentials (workspace_id IS NULL)
        // The queue container serves all workspaces, so workspace-specific
        // credentials should not be exported at container startup
        $credentials = Credential::whereNull('workspace_id')->get();

        foreach ($credentials as $credential) {
            $value = $credential->getValue();

            if ($value === null) {
                continue;
            }

            $envVar = $credential->env_var;

            // Escape for shell: handle quotes, backslashes, and special chars
            // Use single quotes and escape any single quotes within the value
            $escapedValue = str_replace("'", "'\\''", $value);

            $this->output->writeln("export {$envVar}='{$escapedValue}'");
        }

        return Command::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->output->writeln("Invalid action '{$action}'. Valid actions are: export.");

        return Command::FAILURE;
    }
}
