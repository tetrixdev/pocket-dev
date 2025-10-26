<?php

use App\Http\Controllers\Api\ClaudeController;
use App\Http\Controllers\Api\PricingController;
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
});

// Model pricing
Route::get('pricing/{modelName}', [PricingController::class, 'show']);
Route::post('pricing/{modelName}', [PricingController::class, 'store']);
