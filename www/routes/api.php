<?php

use App\Http\Controllers\Api\ClaudeController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('claude')->group(function () {
    // Status check
    Route::get('status', [ClaudeController::class, 'status']);

    // Session management (metadata only)
    Route::get('sessions', [ClaudeController::class, 'index']);
    Route::post('sessions', [ClaudeController::class, 'createSession']);

    // Streaming queries
    Route::post('sessions/{session}/stream', [ClaudeController::class, 'streamQuery']);
    Route::get('sessions/{session}/poll', [ClaudeController::class, 'pollMessages']);
    Route::get('sessions/{session}/status', [ClaudeController::class, 'getSessionStatus']);
    Route::get('sessions/{session}/history', [ClaudeController::class, 'getStreamingHistory']);
    Route::post('sessions/{session}/cancel', [ClaudeController::class, 'cancel']);

    // Claude's native session files (.jsonl)
    Route::get('claude-sessions', [ClaudeController::class, 'listClaudeSessions']);
    Route::get('claude-sessions/{sessionId}', [ClaudeController::class, 'loadClaudeSession']);

    // Voice transcription
    Route::post('transcribe', [ClaudeController::class, 'transcribe']);

    // OpenAI API key management
    Route::get('openai-key/check', [ClaudeController::class, 'checkOpenAiKey']);
    Route::post('openai-key', [ClaudeController::class, 'setOpenAiKey']);
    Route::delete('openai-key', [ClaudeController::class, 'deleteOpenAiKey']);

    // Quick settings (model, permission mode, max turns)
    Route::get('quick-settings', [ClaudeController::class, 'getQuickSettings']);
    Route::post('quick-settings', [ClaudeController::class, 'saveQuickSettings']);
});

// Model pricing
Route::get('pricing', [PricingController::class, 'index']);
Route::get('pricing/{modelId}', [PricingController::class, 'show']);
Route::post('pricing/{modelId}', [PricingController::class, 'store']);

// Multi-provider conversations (v2)
Route::prefix('v2')->group(function () {
    // Provider info
    Route::get('providers', [ConversationController::class, 'providers']);

    // Conversation CRUD
    Route::get('conversations', [ConversationController::class, 'index']);
    Route::post('conversations', [ConversationController::class, 'store']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy']);

    // Conversation actions
    Route::get('conversations/{conversation}/status', [ConversationController::class, 'status']);
    Route::post('conversations/{conversation}/stream', [ConversationController::class, 'stream']);
    Route::get('conversations/{conversation}/stream-status', [ConversationController::class, 'streamStatus']);
    Route::get('conversations/{conversation}/stream-events', [ConversationController::class, 'streamEvents']);
    Route::post('conversations/{conversation}/archive', [ConversationController::class, 'archive']);
    Route::post('conversations/{conversation}/unarchive', [ConversationController::class, 'unarchive']);

    // Settings
    Route::get('settings/chat-defaults', [SettingsController::class, 'chatDefaults']);
    Route::post('settings/chat-defaults', [SettingsController::class, 'updateChatDefaults']);
});
