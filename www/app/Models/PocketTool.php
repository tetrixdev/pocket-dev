<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PocketTool extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    // Source constants
    public const SOURCE_POCKETDEV = 'pocketdev';
    public const SOURCE_USER = 'user';

    // Type constants
    public const TYPE_SCRIPT = 'script';
    public const TYPE_PANEL = 'panel';

    // Common category values (not enforced - categories are flexible labels)
    // These are just convenience constants for common categories
    public const CATEGORY_MEMORY = 'memory';
    public const CATEGORY_MEMORY_SCHEMA = 'memory_schema';
    public const CATEGORY_MEMORY_DATA = 'memory_data';
    public const CATEGORY_TOOLS = 'tools';
    public const CATEGORY_FILE_OPS = 'file_ops';
    public const CATEGORY_CUSTOM = 'custom';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'source',
        'category',
        'type',
        'system_prompt',
        'input_schema',
        'script',
        'blade_template',
        'panel_dependencies',
        'enabled',
    ];

    protected $casts = [
        'input_schema' => 'array',
        'panel_dependencies' => 'array',
        'enabled' => 'boolean',
    ];

    /**
     * Scope to only enabled tools.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to PocketDev tools.
     */
    public function scopePocketdev(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_POCKETDEV);
    }

    /**
     * Scope to user-created tools.
     */
    public function scopeUser(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_USER);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to script tools only.
     */
    public function scopeScripts(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SCRIPT);
    }

    /**
     * Scope to panel tools only.
     */
    public function scopePanels(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PANEL);
    }

    /**
     * Check if this tool is a PocketDev tool.
     */
    public function isPocketdev(): bool
    {
        return $this->source === self::SOURCE_POCKETDEV;
    }

    /**
     * Check if this tool is a user-created tool.
     */
    public function isUserTool(): bool
    {
        return $this->source === self::SOURCE_USER;
    }

    /**
     * Check if this tool can be modified (user tools only).
     */
    public function isEditable(): bool
    {
        return $this->isUserTool();
    }

    /**
     * Check if this tool has a bash script.
     */
    public function hasScript(): bool
    {
        return !empty($this->script);
    }

    /**
     * Check if this tool is a panel.
     */
    public function isPanel(): bool
    {
        return $this->type === self::TYPE_PANEL;
    }

    /**
     * Check if this tool is a script tool.
     */
    public function isScript(): bool
    {
        return $this->type === self::TYPE_SCRIPT;
    }

    /**
     * Check if this tool has a Blade template.
     */
    public function hasBladeTemplate(): bool
    {
        return !empty($this->blade_template);
    }

    /**
     * Dynamic artisan_command property (set from config).
     */
    public ?string $artisan_command = null;

    /**
     * Get the artisan command for this tool.
     */
    public function getArtisanCommand(): ?string
    {
        // Check for dynamically set artisan_command first (from config)
        if ($this->artisan_command) {
            return $this->artisan_command;
        }

        if (!$this->isPocketdev()) {
            return "tool:run {$this->slug}";
        }

        // Map PocketDev tool slugs to artisan commands (fallback)
        $commandMap = [
            'memory-structure-create' => 'memory:structure:create',
            'memory-structure-get' => 'memory:structure:get',
            'memory-structure-update' => 'memory:structure:update',
            'memory-structure-delete' => 'memory:structure:delete',
            'memory-create' => 'memory:create',
            'memory-query' => 'memory:query',
            'memory-update' => 'memory:update',
            'memory-delete' => 'memory:delete',
            'tool-create' => 'tool:create',
            'tool-update' => 'tool:update',
            'tool-delete' => 'tool:delete',
            'tool-list' => 'tool:list',
            'tool-show' => 'tool:show',
            'tool-run' => 'tool:run',
        ];

        return $commandMap[$this->slug] ?? null;
    }

    /**
     * Get all unique categories from the database.
     *
     * @return array<string>
     */
    public static function getCategories(): array
    {
        return static::query()
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values()
            ->toArray();
    }
}
