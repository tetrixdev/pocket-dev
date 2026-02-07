<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PanelState extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'panel_slug',
        'parameters',
        'state',
    ];

    protected $casts = [
        'parameters' => 'array',
        'state' => 'array',
    ];

    /**
     * Get the panel tool definition.
     */
    public function panel(): BelongsTo
    {
        return $this->belongsTo(PocketTool::class, 'panel_slug', 'slug');
    }

    /**
     * Get the screen that uses this panel state.
     */
    public function screen(): HasOne
    {
        return $this->hasOne(Screen::class, 'panel_id');
    }

    /**
     * Get a specific state value with dot notation support.
     */
    public function getStateValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->state, $key, $default);
    }

    /**
     * Set a specific state value with dot notation support.
     */
    public function setStateValue(string $key, mixed $value): void
    {
        $state = $this->state ?? [];
        data_set($state, $key, $value);
        $this->state = $state;
    }

    /**
     * Merge new state values into existing state.
     */
    public function mergeState(array $newState): void
    {
        $this->state = array_merge($this->state ?? [], $newState);
    }

    /**
     * Get a parameter value.
     */
    public function getParameter(string $key, mixed $default = null): mixed
    {
        return data_get($this->parameters, $key, $default);
    }
}
