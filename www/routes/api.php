<?php

use App\Http\Controllers\Api\ClaudeController;
use Illuminate\Support\Facades\Route;

Route::prefix('claude')->group(function () {
    // Status check
    Route::get('status', [ClaudeController::class, 'status']);

    // Session management
    Route::get('sessions', [ClaudeController::class, 'index']);
    Route::post('sessions', [ClaudeController::class, 'createSession']);
    Route::get('sessions/{session}', [ClaudeController::class, 'show']);
    Route::delete('sessions/{session}', [ClaudeController::class, 'destroy']);

    // Queries
    Route::post('sessions/{session}/query', [ClaudeController::class, 'query']);
    Route::post('sessions/{session}/stream', [ClaudeController::class, 'streamQuery']);

    // Claude's native session files
    Route::get('claude-sessions', [ClaudeController::class, 'listClaudeSessions']);
    Route::get('claude-sessions/{sessionId}', [ClaudeController::class, 'loadClaudeSession']);
});
