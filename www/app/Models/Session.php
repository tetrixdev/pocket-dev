<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Session model for PocketDev chat sessions.
 *
 * IMPORTANT: DO NOT use updateQuietly() on this model!
 * Laravel's updateQuietly() only suppresses model events (observers),
 * but still updates the updated_at timestamp. For session updates that
 * should preserve timestamps (navigation, metadata changes), use the
 * updatePreservingTimestamp() method instead.
 *
 * Only Conversation::completeProcessing() and Conversation::markFailed()
 * should touch the session timestamp (via explicit $session->touch()).
 */
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
        'next_chat_number',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'screen_order' => 'array',
        'next_chat_number' => 'integer',
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
     * Update session columns WITHOUT touching updated_at.
     *
     * Use this instead of updateQuietly() which only skips events but still
     * updates timestamps. This method uses a raw query to truly preserve
     * the updated_at column.
     *
     * @param array<string, mixed> $attributes Key-value pairs to update
     */
    public function updatePreservingTimestamp(array $attributes): void
    {
        // Handle JSON columns (screen_order needs json_encode for raw query)
        $dbAttributes = [];
        foreach ($attributes as $key => $value) {
            if ($key === 'screen_order' && is_array($value)) {
                $dbAttributes[$key] = json_encode($value);
            } else {
                $dbAttributes[$key] = $value;
            }
        }

        DB::table('pocketdev_sessions')
            ->where('id', $this->id)
            ->update($dbAttributes);

        // Sync in-memory model
        foreach ($attributes as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Archive the session.
     */
    public function archive(): void
    {
        $this->updatePreservingTimestamp(['is_archived' => true]);
    }

    /**
     * Restore the session from archive.
     */
    // TODO: Rename to unarchive() to avoid conflict with SoftDeletes::restore()
    public function restore(): void
    {
        $this->updatePreservingTimestamp(['is_archived' => false]);
    }

    /**
     * Set the last active screen.
     */
    public function setActiveScreen(Screen $screen): void
    {
        $this->updatePreservingTimestamp(['last_active_screen_id' => $screen->id]);
    }

    /**
     * Add a screen to the end of the order.
     */
    public function addScreenToOrder(string $screenId): void
    {
        $order = $this->screen_order ?? [];
        if (!in_array($screenId, $order)) {
            $order[] = $screenId;
            $this->updatePreservingTimestamp(['screen_order' => $order]);
        }
    }

    /**
     * Remove a screen from the order.
     */
    public function removeScreenFromOrder(string $screenId): void
    {
        $order = $this->screen_order ?? [];
        $order = array_values(array_filter($order, fn($id) => $id !== $screenId));
        $this->updatePreservingTimestamp(['screen_order' => $order]);
    }

    /**
     * Reorder screens.
     */
    public function reorderScreens(array $screenIds): void
    {
        $this->updatePreservingTimestamp(['screen_order' => $screenIds]);
    }

    /**
     * Get the next chat number and increment the counter atomically.
     *
     * Uses a database transaction with row locking to prevent race conditions
     * when multiple chats are created concurrently for the same session.
     */
    public function getNextChatNumber(): int
    {
        return DB::transaction(function () {
            // Lock the session row to prevent concurrent assignment
            $session = DB::table('pocketdev_sessions')
                ->where('id', $this->id)
                ->lockForUpdate()
                ->first();

            $chatNumber = $session->next_chat_number ?? 1;

            // Increment the counter
            DB::table('pocketdev_sessions')
                ->where('id', $this->id)
                ->update(['next_chat_number' => $chatNumber + 1]);

            return $chatNumber;
        });
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
