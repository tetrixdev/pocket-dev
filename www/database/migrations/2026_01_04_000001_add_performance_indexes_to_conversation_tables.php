<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes for conversation loading.
 *
 * These indexes address slow loading times for long conversations by:
 * 1. Indexing conversation_id on messages (foreign key queries)
 * 2. Composite index for turn-based lookups (AI tools, embeddings)
 * 3. Composite index for ordered message retrieval (every page load)
 * 4. Index on soft deletes (implicit WHERE deleted_at IS NULL)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Index 1: Fast lookup of all messages for a conversation
            // Used by: ConversationController::show(), Conversation::messages(), providers
            $table->index('conversation_id', 'idx_messages_conversation_id');

            // Index 2: Fast lookup of messages by turn within a conversation
            // Used by: ConversationGetTurnsTool, GenerateConversationEmbeddings, search
            $table->index(['conversation_id', 'turn_number'], 'idx_messages_conversation_turn');

            // Index 3: Fast ordered retrieval of messages
            // Used by: Conversation::messages() relationship which always orders by sequence
            $table->index(['conversation_id', 'sequence'], 'idx_messages_conversation_sequence');
        });

        Schema::table('conversations', function (Blueprint $table) {
            // Index 4: Fast filtering of non-deleted conversations
            // Used by: All queries implicitly via SoftDeletes trait
            $table->index('deleted_at', 'idx_conversations_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_conversation_id');
            $table->dropIndex('idx_messages_conversation_turn');
            $table->dropIndex('idx_messages_conversation_sequence');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_deleted_at');
        });
    }
};
