<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MemoryDatabase extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'schema_name',
        'description',
    ];

    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate schema_name from name on create
        static::creating(function (MemoryDatabase $db) {
            if (empty($db->schema_name)) {
                // Convert to snake_case for PostgreSQL schema name
                $db->schema_name = Str::snake(Str::slug($db->name, '_'));
            }
        });
    }

    /**
     * Get workspaces that have this memory database.
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memory_databases')
            ->withPivot(['enabled', 'is_default'])
            ->withTimestamps();
    }

    /**
     * Get agents that have access to this memory database.
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_memory_databases')
            ->withPivot(['permission'])
            ->withTimestamps();
    }

    /**
     * Get the full PostgreSQL schema name.
     */
    public function getFullSchemaName(): string
    {
        return 'memory_' . $this->schema_name;
    }

    /**
     * Check if this memory database is enabled in a workspace.
     */
    public function isEnabledInWorkspace(Workspace $workspace): bool
    {
        $pivot = $this->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->first();

        return $pivot && $pivot->pivot->enabled;
    }

    /**
     * Check if an agent has access to this memory database.
     */
    public function isAccessibleByAgent(Agent $agent): bool
    {
        // First check if memory DB is enabled in the agent's workspace
        if (!$agent->workspace || !$this->isEnabledInWorkspace($agent->workspace)) {
            return false;
        }

        // Then check if agent has explicit access
        return $this->agents()
            ->where('agents.id', $agent->id)
            ->exists();
    }

    /**
     * Get the permission level for an agent.
     */
    public function getAgentPermission(Agent $agent): ?string
    {
        $pivot = $this->agents()
            ->where('agents.id', $agent->id)
            ->first();

        return $pivot?->pivot?->permission;
    }

    /**
     * Check if an agent can write to this memory database.
     */
    public function agentCanWrite(Agent $agent): bool
    {
        $permission = $this->getAgentPermission($agent);
        return in_array($permission, ['write', 'admin'], true);
    }

    /**
     * Check if an agent has admin access to this memory database.
     */
    public function agentCanAdmin(Agent $agent): bool
    {
        return $this->getAgentPermission($agent) === 'admin';
    }

    /**
     * Validate schema name format.
     */
    public static function isValidSchemaName(string $name): bool
    {
        // Must be lowercase, alphanumeric with underscores, max 55 chars (63 - 8 for "memory_" prefix)
        return preg_match('/^[a-z][a-z0-9_]{0,54}$/', $name) === 1;
    }
}
