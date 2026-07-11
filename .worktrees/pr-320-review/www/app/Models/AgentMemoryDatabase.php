<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMemoryDatabase extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const PERMISSION_READ = 'read';
    public const PERMISSION_WRITE = 'write';
    public const PERMISSION_ADMIN = 'admin';

    protected $fillable = [
        'agent_id',
        'memory_database_id',
        'permission',
    ];

    /**
     * Get the agent.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Get the memory database.
     */
    public function memoryDatabase(): BelongsTo
    {
        return $this->belongsTo(MemoryDatabase::class);
    }

    /**
     * Check if this grants write access.
     */
    public function canWrite(): bool
    {
        return in_array($this->permission, [self::PERMISSION_WRITE, self::PERMISSION_ADMIN], true);
    }

    /**
     * Check if this grants admin access.
     */
    public function canAdmin(): bool
    {
        return $this->permission === self::PERMISSION_ADMIN;
    }

    /**
     * Get all valid permission levels.
     */
    public static function getPermissions(): array
    {
        return [
            self::PERMISSION_READ,
            self::PERMISSION_WRITE,
            self::PERMISSION_ADMIN,
        ];
    }
}
