<?php

use App\Models\Conversation;
use App\Models\Screen;
use App\Models\Session;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Wrap each existing conversation in a session with a single chat screen.
     */
    public function up(): void
    {
        // Get all conversations that don't already have a screen
        $conversations = Conversation::withTrashed()
            ->whereDoesntHave('screen')
            ->whereNotNull('workspace_id')
            ->get();

        foreach ($conversations as $conversation) {
            DB::transaction(function () use ($conversation) {
                // Create session for this conversation
                $session = Session::create([
                    'workspace_id' => $conversation->workspace_id,
                    'name' => $conversation->title ?? 'Untitled',
                    'is_archived' => $conversation->status === Conversation::STATUS_ARCHIVED,
                ]);

                // Create chat screen for the conversation
                $screen = Screen::create([
                    'session_id' => $session->id,
                    'type' => Screen::TYPE_CHAT,
                    'conversation_id' => $conversation->id,
                    'is_active' => true,
                ]);

                // Set the screen as active and update screen order
                $session->update([
                    'last_active_screen_id' => $screen->id,
                    'screen_order' => [$screen->id],
                ]);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Delete all sessions and screens (conversations remain intact).
     */
    public function down(): void
    {
        // Delete all screens first (due to foreign key constraints)
        Screen::query()->delete();

        // Delete all sessions
        Session::query()->delete();
    }
};
