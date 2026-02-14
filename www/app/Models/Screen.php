<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Screen extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    // Type constants
    public const TYPE_CHAT = 'chat';
    public const TYPE_PANEL = 'panel';

    protected $fillable = [
        'session_id',
        'type',
        'chat_number',
        'conversation_id',
        'panel_slug',
        'panel_id',
        'parameters',
        'is_active',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the session this screen belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    /**
     * Get the conversation (for chat screens).
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the panel state (for panel screens).
     */
    public function panelState(): BelongsTo
    {
        return $this->belongsTo(PanelState::class, 'panel_id');
    }

    /**
     * Get the panel tool definition (for panel screens).
     */
    public function panel(): BelongsTo
    {
        return $this->belongsTo(PocketTool::class, 'panel_slug', 'slug');
    }

    /**
     * Check if this is a chat screen.
     */
    public function isChat(): bool
    {
        return $this->type === self::TYPE_CHAT;
    }

    /**
     * Check if this is a panel screen.
     */
    public function isPanel(): bool
    {
        return $this->type === self::TYPE_PANEL;
    }

    /**
     * Get the display name for this screen.
     */
    public function getDisplayName(): string
    {
        if ($this->isChat()) {
            return $this->conversation?->title ?? 'Chat';
        }

        return $this->panel?->name ?? $this->panel_slug ?? 'Panel';
    }

    /**
     * Activate this screen (and deactivate others in the session).
     */
    public function activate(): void
    {
        // Deactivate all other screens in the session
        self::where('session_id', $this->session_id)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);

        // Activate this screen
        $this->update(['is_active' => true]);

        // Update session's last active screen
        $this->session->setActiveScreen($this);
    }

    /**
     * Scope to chat screens only.
     */
    public function scopeChats(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CHAT);
    }

    /**
     * Scope to panel screens only.
     */
    public function scopePanels(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PANEL);
    }

    /**
     * Scope to active screens.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Create a chat screen for a conversation.
     */
    public static function createChatScreen(Session $session, Conversation $conversation): self
    {
        $screen = self::create([
            'session_id' => $session->id,
            'type' => self::TYPE_CHAT,
            'chat_number' => $session->getNextChatNumber(),
            'conversation_id' => $conversation->id,
        ]);

        $session->addScreenToOrder($screen->id);

        return $screen;
    }

    /**
     * Create a panel screen.
     */
    public static function createPanelScreen(
        Session $session,
        string $panelSlug,
        PanelState $panelState,
        ?array $parameters = null
    ): self {
        $screen = self::create([
            'session_id' => $session->id,
            'type' => self::TYPE_PANEL,
            'panel_slug' => $panelSlug,
            'panel_id' => $panelState->id,
            'parameters' => $parameters ?? $panelState->parameters,
        ]);

        $session->addScreenToOrder($screen->id);

        return $screen;
    }
}
