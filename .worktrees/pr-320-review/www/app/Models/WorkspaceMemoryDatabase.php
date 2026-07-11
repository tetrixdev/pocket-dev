<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMemoryDatabase extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'workspace_id',
        'memory_database_id',
        'enabled',
        'is_default',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Get the workspace.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the memory database.
     */
    public function memoryDatabase(): BelongsTo
    {
        return $this->belongsTo(MemoryDatabase::class);
    }
}
