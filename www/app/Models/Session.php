<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    use HasUuids;

    protected $table = 'pocketdev_sessions';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'workspace_id',
        'name',
        'is_archived',
        'last_active_screen_id',
        'screen_order',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'screen_order' => 'array',
    ];

    /**
     * Get the workspace this session belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get all screens in this session.
     */
    public function screens(): HasMany
    {
        return $this->hasMany(Screen::class);
    }

    /**
     * Get the last active screen.
     */
    public function lastActiveScreen(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'last_active_screen_id');
    }

    /**
     * Get screens in display order.
     */
    public function orderedScreens()
    {
        if (empty($this->screen_order)) {
            return $this->screens()->orderBy('created_at');
        }

        // Order by position in screen_order array using parameter binding
        // to prevent SQL injection
        $bindings = [];
        $orderCase = collect($this->screen_order)
            ->map(function ($id, $index) use (&$bindings) {
                $bindings[] = $id;
                $bindings[] = $index;
                return "WHEN id = ? THEN ?";
            })
            ->join(' ');

        return $this->screens()
            ->orderByRaw("CASE {$orderCase} ELSE 999 END", $bindings);
    }

    /**
     * Get chat screens only.
     */
    public function chatScreens(): HasMany
    {
        return $this->screens()->where('type', Screen::TYPE_CHAT);
    }

    /**
     * Get panel screens only.
     */
    public function panelScreens(): HasMany
    {
        return $this->screens()->where('type', Screen::TYPE_PANEL);
    }

    /**
     * Archive the session.
     */
    public function archive(): void
    {
        $this->update(['is_archived' => true]);
    }

    /**
     * Restore the session from archive.
     */
    public function restore(): void
    {
        $this->update(['is_archived' => false]);
    }

    /**
     * Set the last active screen.
     */
    public function setActiveScreen(Screen $screen): void
    {
        $this->update(['last_active_screen_id' => $screen->id]);
    }

    /**
     * Add a screen to the end of the order.
     */
    public function addScreenToOrder(string $screenId): void
    {
        $order = $this->screen_order ?? [];
        if (!in_array($screenId, $order)) {
            $order[] = $screenId;
            $this->update(['screen_order' => $order]);
        }
    }

    /**
     * Remove a screen from the order.
     */
    public function removeScreenFromOrder(string $screenId): void
    {
        $order = $this->screen_order ?? [];
        $order = array_values(array_filter($order, fn($id) => $id !== $screenId));
        $this->update(['screen_order' => $order]);
    }

    /**
     * Reorder screens.
     */
    public function reorderScreens(array $screenIds): void
    {
        $this->update(['screen_order' => $screenIds]);
    }

    /**
     * Scope to active (non-archived) sessions.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Scope to archived sessions.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    /**
     * Scope to filter by workspace.
     */
    public function scopeForWorkspace(Builder $query, string $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }
}
