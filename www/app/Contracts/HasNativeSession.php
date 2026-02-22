<?php

namespace App\Contracts;

use App\Models\Conversation;

/**
 * Interface for providers that manage their own conversation sessions.
 *
 * CLI providers (Claude Code, Codex) maintain conversation history internally
 * via session/thread IDs. This interface signals that the provider needs
 * session ID persistence and supports abort message sync.
 */
interface HasNativeSession
{
    /**
     * Get the session ID from the conversation.
     */
    public function getSessionId(Conversation $conversation): ?string;

    /**
     * Set the session ID on the conversation.
     *
     * Does NOT call save() -- the caller is responsible for persistence.
     */
    public function setSessionId(Conversation $conversation, string $sessionId): void;
}
