<?php

use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Voice / Transcription Routes
|--------------------------------------------------------------------------
|
| Voice transcription and OpenAI key management.
| Kept at /api/claude/* for backward compatibility with existing frontend.
|
*/

Route::prefix('claude')->group(function () {
    Route::post('transcribe', [TranscriptionController::class, 'transcribe']);
    Route::get('openai-key/check', [TranscriptionController::class, 'checkOpenAiKey']);
    Route::post('openai-key', [TranscriptionController::class, 'setOpenAiKey']);
    Route::delete('openai-key', [TranscriptionController::class, 'deleteOpenAiKey']);
});

/*
|--------------------------------------------------------------------------
| Pricing Routes
|--------------------------------------------------------------------------
|
| Model pricing information and customization.
|
*/

Route::get('pricing', [PricingController::class, 'index']);
Route::get('pricing/{modelId}', [PricingController::class, 'show']);
Route::post('pricing/{modelId}', [PricingController::class, 'store']);

/*
|--------------------------------------------------------------------------
| Provider Routes
|--------------------------------------------------------------------------
|
| AI provider information and availability.
|
*/

Route::get('providers', [ConversationController::class, 'providers']);

/*
|--------------------------------------------------------------------------
| Conversation Routes
|--------------------------------------------------------------------------
|
| Conversation resource and related actions.
|
*/

// Resource routes: index, store, show, destroy
Route::apiResource('conversations', ConversationController::class)
    ->only(['index', 'store', 'show', 'destroy']);

// Conversation actions
Route::prefix('conversations/{conversation}')->group(function () {
    Route::get('status', [ConversationController::class, 'status']);
    Route::post('stream', [ConversationController::class, 'stream']);
    Route::get('stream-status', [ConversationController::class, 'streamStatus']);
    Route::get('stream-events', [ConversationController::class, 'streamEvents']);
    Route::post('archive', [ConversationController::class, 'archive']);
    Route::post('unarchive', [ConversationController::class, 'unarchive']);
});

/*
|--------------------------------------------------------------------------
| Settings Routes
|--------------------------------------------------------------------------
|
| Application settings management.
|
*/

Route::prefix('settings')->group(function () {
    Route::get('chat-defaults', [SettingsController::class, 'chatDefaults']);
    Route::post('chat-defaults', [SettingsController::class, 'updateChatDefaults']);
});
