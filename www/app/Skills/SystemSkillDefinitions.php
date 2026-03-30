<?php

namespace App\Skills;

/**
 * Defines all system skills that ship with PocketDev.
 *
 * System skills are seeded into the skills table at deploy time.
 * They provide default capabilities that users can disable but not delete.
 */
class SystemSkillDefinitions
{
    /**
     * Current version of system skill definitions.
     * Increment when skills are updated.
     */
    public const VERSION = '1.0.0';

    /**
     * Get all system skill definitions.
     *
     * @return array<array{name: string, when_to_use: string, instructions: string, tags: string[]}>
     */
    public static function all(): array
    {
        return [
            self::deploymentSkill(),
        ];
    }

    /**
     * Get all unique tags used by system skills.
     */
    public static function allTags(): array
    {
        $tags = [];
        foreach (self::all() as $skill) {
            foreach ($skill['tags'] as $tag) {
                $tags[$tag] = true;
            }
        }
        return array_keys($tags);
    }

    /**
     * Deployment workflow skill.
     */
    private static function deploymentSkill(): array
    {
        return [
            'name' => 'deploy',
            'when_to_use' => 'When deploying an application to a server, setting up a new deployment, or managing deployed applications. Use this skill when the user asks to deploy a repository to a server.',
            'tags' => ['system', 'deployment', 'server'],
            'instructions' => <<<'INSTRUCTIONS'
# Deployment Workflow

Follow these steps **sequentially, one at a time**. Do NOT run multiple commands in parallel. Do NOT guess repository owners - use `scan-repos` to find them.

## Step 1: Get server info
```bash
pd server info --server=<SERVER-NAME>
```
This returns EVERYTHING about the server in one call: status, prerequisites, deployed apps, running containers, proxy domains. **Always run this first.**

## Step 2: Find the repository
If you don't know the exact owner/repo, scan for it:
```bash
pd server:app scan-repos
```
This returns all deployable repositories with their `owner` and `repo` values. **Do NOT guess the organization** - use this command to find it.

## Step 3: Get deployment config
```bash
pd server:app get-deploy-config --owner=<owner> --repo=<repo>
```
This fetches compose.yml and checks the slim-docker-laravel-setup version.

**⛔ IF `slim_docker_version.is_outdated` IS TRUE: STOP HERE.**

1. **PAUSE** - do NOT proceed with deployment yet
2. **REPORT**: Explain that the repository's slim-docker-laravel-setup is outdated and should be updated before deploying to avoid potential issues.
3. **OFFER TO FIX**: Ask the user: "Would you like me to update the repository? I'll run the slim-docker-laravel-setup installer, commit the changes, push, and create a new release before continuing with deployment."

4. **PROCEED BASED ON RESPONSE**:
   - **User agrees** → Perform the update:
     1. Clone/pull the repo to the current workspace directory
     2. Run `curl -sSL https://raw.githubusercontent.com/tetrixdev/slim-docker-laravel-setup/main/install.sh | bash`
     3. Commit changes with message "Update slim-docker-laravel-setup"
     4. Push to origin
     5. Create a new release (increment patch version, e.g., v0.1.0 → v0.1.1)
     6. Wait for GitHub Actions to build Docker images
     7. Continue with deployment using new IMAGE_TAG
   - **User says "deploy anyway"** → Proceed but warn it will likely fail
   - **User declines** → Stop and wait for instructions

## Step 4: Create deployment files
```bash
# Write compose.yml (from get-deploy-config response)
cat > /tmp/compose.yml << 'EOF'
<compose content here>
EOF

# Generate secure passwords and write .env
DB_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)
APP_KEY=$(openssl rand -base64 32)
cat > /tmp/app.env << EOF
GITHUB_REPOSITORY_OWNER=<owner>
COMPOSE_PROJECT_NAME=<repo-slug>
IMAGE_TAG=<latest-release-tag>
APP_KEY=base64:${APP_KEY}
DB_PASSWORD=${DB_PASS}
...
EOF
```

## Step 5: Add and deploy
```bash
pd server:app add --workspace=default --server=<server-id> --name="<app-name>" --compose=/tmp/compose.yml --env=/tmp/app.env
pd server:app deploy --id=<app-id>
```

## Step 6: Configure domain and SSL
```bash
pd server:app add-domain --id=<app-id> --domain=<domain> --upstream=<slug>-nginx
pd server:app request-ssl --id=<app-id> --domain=<domain>
```

## Environment Variables - Smart Handling

### Auto-derive these values (don't ask user):
- `COMPOSE_PROJECT_NAME`: Use repo name as slug (e.g., "box-of-crumbs")
- `GITHUB_REPOSITORY_OWNER`: Use repo owner (e.g., "jfbauer")
- `IMAGE_TAG`: Use latest release tag (e.g., "v0.1.0")

### Generate secure values (use bash, NOT LLM):
```bash
# Generate secure random password - write directly to .env, never read back
openssl rand -base64 32
```
For DB_PASSWORD, APP_KEY, etc. - generate and write directly, never output to conversation.

### Only ask user for:
- Domain name
- App-specific API keys they need to provide

### After deployment, tell user:
- Which values were auto-derived (so they can verify)
- Which secrets were generated (don't show values)
- Which fields still need manual input

### Reading/updating env safely (for updates):
```bash
# Read a key (masks secrets by default - shows "abc...xyz")
pd server:app read-env-key --id=<app-id> --key=IMAGE_TAG

# Update a single key without reading the file
pd server:app update-env-key --id=<app-id> --key=IMAGE_TAG --value=v0.2.0
```

**CRITICAL: Never use --full flag on secrets.**

## Important Rules
- **NEVER manually SSH** - use the `server:app` commands
- **NEVER skip prerequisites** - each step depends on previous ones
- **Always verify** the repository has a release before deploying
- **NEVER read full secret values** - use masked read-env-key for identification only
- If any step fails or returns unexpected results, STOP and report the issue
INSTRUCTIONS,
        ];
    }
}
