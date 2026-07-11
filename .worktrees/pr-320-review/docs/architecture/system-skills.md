# System Skills

**Last Updated**: March 2026

System skills are AI workflow instructions that ship with PocketDev source code. Unlike user-created skills (stored only in the database), system skills are defined in PHP and automatically seeded into all memory schemas.

## Overview

| Aspect | Details |
|--------|---------|
| Definition location | `app/Skills/SystemSkillDefinitions.php` |
| Seeder | `database/seeders/SystemSkillSeeder.php` |
| First deployment | Called from migration `2026_03_29_000001_add_system_skill_columns_to_skills_tables.php` |
| Distinction | `source` column: `'system'` vs `'user'` |

## How It Works

1. **SystemSkillDefinitions.php** contains all system skill definitions with:
   - `name`: Skill identifier (e.g., `'deploy'`)
   - `when_to_use`: Description for AI to know when to invoke
   - `instructions`: Full workflow/instructions
   - `tags`: Array of labels for filtering (e.g., `['system', 'deployment']`)

2. **SystemSkillSeeder** iterates all `memory_*` schemas and upserts skills:
   - Only updates rows where `source = 'system'`
   - Skips update if content is identical (avoids embedding regeneration costs)
   - Never overwrites user-customized skills

3. **Skills table columns**:
   - `source`: `'system'` or `'user'`
   - `tags`: PostgreSQL array for filtering
   - `version`: PocketDev version that last updated the skill

## Adding or Updating System Skills

When you need to add a new system skill or modify an existing one:

### Step 1: Update SystemSkillDefinitions

```php
// app/Skills/SystemSkillDefinitions.php

public const VERSION = '1.1.0'; // Bump version

public static function all(): array
{
    return [
        self::deploymentSkill(),
        self::newSkill(), // Add new skill method
    ];
}

private static function newSkill(): array
{
    return [
        'name' => 'my-new-skill',
        'when_to_use' => 'When the user asks to do X...',
        'instructions' => <<<'INSTRUCTIONS'
# My New Skill

Step-by-step instructions here...
INSTRUCTIONS,
        'tags' => ['system', 'my-category'],
    ];
}
```

### Step 2: Create a Migration

```php
<?php
// database/migrations/2026_XX_XX_update_system_skills.php

use Database\Seeders\SystemSkillSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => SystemSkillSeeder::class,
            '--force' => true,
        ]);
    }

    public function down(): void
    {
        // Skills remain in database - no rollback needed
    }
};
```

### Step 3: Deploy

Run `php artisan migrate` - the migration calls the seeder, which updates all schemas.

## Why This Pattern?

| Approach | Embedding Costs | Automatic | Tracked |
|----------|-----------------|-----------|---------|
| Migration + Seeder (chosen) | Only on actual changes | Yes | Yes |
| Run seeder every deploy | Every deploy | Yes | No |
| Manual seeder runs | On-demand | No | No |

The migration approach:
- **Zero cost on normal deploys** - seeder only runs when migration is new
- **Automatic** - no manual steps, works with `php artisan migrate`
- **Tracked** - changes visible in migration history
- **Safe** - seeder skips identical content, avoiding unnecessary embedding regeneration

## Workspace Tag Filtering

Workspaces can filter which skills are visible using tags:

```php
// Workspace model methods
$workspace->isSkillWhitelistMode();  // Default: false (blacklist mode)
$workspace->getSkillFilterTags();    // Tags to filter
$workspace->isSkillTagAllowed($tags); // Check if skill passes filter

// Blacklist mode (default): All skills allowed EXCEPT those with blacklisted tags
// Whitelist mode: ONLY skills with whitelisted tags are allowed
```

## Embeddings Note

Skills use `when_to_use` and `instructions` as embed_fields for semantic search. Embeddings are generated:
- On first access after seeding
- Via manual embedding regeneration commands

If the OpenAI API key isn't configured at seed time, skills are inserted without embeddings. See the pending todo for handling this case.
