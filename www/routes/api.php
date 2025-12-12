<?php

use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Support\Facades\Route;

// Voice transcription and OpenAI key management
// Kept at /api/claude/* for backward compatibility with existing frontend
Route::prefix('claude')->group(function () {
    Route::post('transcribe', [TranscriptionController::class, 'transcribe']);
    Route::get('openai-key/check', [TranscriptionController::class, 'checkOpenAiKey']);
    Route::post('openai-key', [TranscriptionController::class, 'setOpenAiKey']);
    Route::delete('openai-key', [TranscriptionController::class, 'deleteOpenAiKey']);
});

// Model pricing
Route::get('pricing', [PricingController::class, 'index']);
Route::get('pricing/{modelId}', [PricingController::class, 'show']);
Route::post('pricing/{modelId}', [PricingController::class, 'store']);

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
