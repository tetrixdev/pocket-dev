<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'directory',
        'description',
        'owner_id',
        'settings',
        'selected_packages',
        'claude_base_prompt',
    ];

    protected $casts = [
        'settings' => 'array',
        'selected_packages' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate directory from name on create
        static::creating(function (Workspace $workspace) {
            if (empty($workspace->directory)) {
                $workspace->directory = Str::slug($workspace->name);
            }
        });

        // Create workspace directory on creation
        // Owner will be www-data (PHP process), group will be www-data's primary group
        // We set group to appgroup and mode to 0775 so appuser (Claude CLI) can write
        static::created(function (Workspace $workspace) {
            $path = $workspace->getWorkingDirectoryPath();
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
                // Set group to appgroup so both www-data and appuser can write
                // (www-data as owner, appuser as group member)
                @chgrp($path, 'appgroup');
            }
        });

        // Ensure correct permissions when workspace is restored from soft-delete
        static::restored(function (Workspace $workspace) {
            $path = $workspace->getWorkingDirectoryPath();
            if (is_dir($path)) {
                @chmod($path, 0775);
                @chgrp($path, 'appgroup');
            }
        });
    }

    /**
     * Get agents belonging to this workspace.
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /**
     * Get conversations belonging to this workspace.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get all memory databases associated with this workspace.
     */
    public function memoryDatabases(): BelongsToMany
    {
        return $this->belongsToMany(MemoryDatabase::class, 'workspace_memory_databases')
            ->withPivot(['enabled', 'is_default'])
            ->withTimestamps();
    }

    /**
     * Get enabled memory databases for this workspace.
     */
    public function enabledMemoryDatabases(): BelongsToMany
    {
        return $this->memoryDatabases()->wherePivot('enabled', true);
    }

    /**
     * Get the default memory database for this workspace.
     */
    public function defaultMemoryDatabase(): ?MemoryDatabase
    {
        return $this->memoryDatabases()->wherePivot('is_default', true)->first();
    }

    /**
     * Get the default memory database (alias for consistency).
     */
    public function getDefaultMemoryDatabase(): ?MemoryDatabase
    {
        return $this->defaultMemoryDatabase();
    }

    /**
     * Get workspace tool configurations.
     */
    public function workspaceTools(): HasMany
    {
        return $this->hasMany(WorkspaceTool::class);
    }

    /**
     * Get the full working directory path.
     */
    public function getWorkingDirectoryPath(): string
    {
        return '/workspace/' . $this->directory;
    }

    /**
     * Check if a tool is enabled in this workspace.
     *
     * Tools are enabled by default unless explicitly disabled.
     */
    public function isToolEnabled(string $slug): bool
    {
        $workspaceTool = $this->workspaceTools()
            ->where('tool_slug', $slug)
            ->first();

        // If no entry exists, tool is enabled by default
        if (!$workspaceTool) {
            return true;
        }

        return $workspaceTool->enabled;
    }

    /**
     * Check if a memory database is enabled in this workspace.
     */
    public function isMemoryDatabaseEnabled(MemoryDatabase $db): bool
    {
        $pivot = $this->memoryDatabases()
            ->where('memory_databases.id', $db->id)
            ->first();

        return $pivot && $pivot->pivot->enabled;
    }

    /**
     * Get all disabled tool slugs for this workspace.
     */
    public function getDisabledToolSlugs(): array
    {
        return $this->workspaceTools()
            ->where('enabled', false)
            ->pluck('tool_slug')
            ->toArray();
    }

    /**
     * Change the workspace directory (with filesystem rename).
     */
    public function changeDirectory(string $newDirectory): bool
    {
        $oldPath = $this->getWorkingDirectoryPath();
        $newPath = '/workspace/' . $newDirectory;

        // Rename directory if it exists
        if (is_dir($oldPath) && $oldPath !== $newPath) {
            if (!@rename($oldPath, $newPath)) {
                return false;
            }
        }

        $this->update(['directory' => $newDirectory]);
        return true;
    }

    /**
     * Scope to active (non-deleted) workspaces.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Scope to filter by owner.
     */
    public function scopeForOwner(Builder $query, string $ownerId): Builder
    {
        return $query->where('owner_id', $ownerId);
    }
}
