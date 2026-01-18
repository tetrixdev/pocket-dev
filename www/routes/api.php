<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationSearchController;
use App\Http\Controllers\Api\FilePreviewController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Support\Facades\Route;

// TODO: Migrate browser-only routes (like file/write) to web.php for proper CSRF protection.
// Routes in api.php are stateless and don't validate CSRF tokens, even if the frontend sends them.

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
    Route::post('transcribe/realtime-session', [TranscriptionController::class, 'createRealtimeSession']);
    Route::get('openai-key/check', [TranscriptionController::class, 'checkOpenAiKey']);
    Route::post('openai-key', [TranscriptionController::class, 'setOpenAiKey']);
    Route::delete('openai-key', [TranscriptionController::class, 'deleteOpenAiKey']);

    // Anthropic API key for Claude Code CLI
    Route::get('anthropic-key/check', [TranscriptionController::class, 'checkAnthropicKey']);
    Route::post('anthropic-key', [TranscriptionController::class, 'setAnthropicKey']);
    Route::delete('anthropic-key', [TranscriptionController::class, 'deleteAnthropicKey']);
});

/*
|--------------------------------------------------------------------------
| Pricing Routes
|--------------------------------------------------------------------------
|
| Model pricing information (read-only, defined in config/ai.php).
|
*/

Route::get('pricing', [PricingController::class, 'index']);
Route::get('pricing/{modelId}', [PricingController::class, 'show']);

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

// Search conversations semantically (must be before resource route)
Route::get('conversations/search', [ConversationSearchController::class, 'search']);

// Latest activity timestamp for sidebar polling (must be before resource route)
Route::get('conversations/latest-activity', [ConversationController::class, 'latestActivity']);

// Resource routes: index, store, show, destroy
Route::apiResource('conversations', ConversationController::class)
    ->only(['index', 'store', 'show', 'destroy']);

// Conversation actions
Route::prefix('conversations/{conversation}')->group(function () {
    Route::get('status', [ConversationController::class, 'status']);
    Route::post('stream', [ConversationController::class, 'stream']);
    Route::post('abort', [ConversationController::class, 'abort']);
    Route::get('stream-status', [ConversationController::class, 'streamStatus']);
    Route::get('stream-events', [ConversationController::class, 'streamEvents']);
    Route::post('archive', [ConversationController::class, 'archive']);
    Route::post('unarchive', [ConversationController::class, 'unarchive']);
    Route::patch('agent', [ConversationController::class, 'switchAgent']);
    Route::patch('title', [ConversationController::class, 'updateTitle']);
    Route::get('stream-log-path', [ConversationController::class, 'streamLogPath']);
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

/*
|--------------------------------------------------------------------------
| Workspace Routes
|--------------------------------------------------------------------------
|
| Workspace management and selection.
| Note: Active workspace routes need session middleware for state persistence.
| IMPORTANT: Specific routes (like /active) must come BEFORE wildcard routes.
|
*/

Route::get('workspaces', [\App\Http\Controllers\Api\WorkspaceController::class, 'index']);

// Routes that require session for active workspace state (must be before {workspace} routes)
Route::middleware(['web'])->group(function () {
    Route::get('workspaces/active', [\App\Http\Controllers\Api\WorkspaceController::class, 'getActive']);
    Route::post('workspaces/active/{workspace}', [\App\Http\Controllers\Api\WorkspaceController::class, 'setActive']);
});

// Wildcard routes must come last
Route::get('workspaces/{workspace}', [\App\Http\Controllers\Api\WorkspaceController::class, 'show']);
Route::get('workspaces/{workspace}/agents', [\App\Http\Controllers\Api\WorkspaceController::class, 'agents']);
Route::get('workspaces/{workspace}/memory-databases', [\App\Http\Controllers\Api\WorkspaceController::class, 'memoryDatabases']);

/*
|--------------------------------------------------------------------------
| Agent Routes
|--------------------------------------------------------------------------
|
| Agent configuration and selection.
|
*/

Route::get('agents', [AgentController::class, 'index']);
Route::get('agents/providers', [AgentController::class, 'providers']);
Route::get('agents/for-provider/{provider}', [AgentController::class, 'forProvider']);
Route::get('agents/default/{provider}', [AgentController::class, 'defaultForProvider']);
Route::get('agents/available-schemas', [AgentController::class, 'availableSchemas']);
Route::get('agents/{agent}/available-schemas', [AgentController::class, 'availableSchemas']);
Route::get('agents/{agent}/system-prompt-preview', [AgentController::class, 'agentSystemPromptPreview']);
Route::get('agents/{agent}', [AgentController::class, 'show']);
Route::get('tools', [AgentController::class, 'allTools']);
Route::get('tools/for-provider/{provider}', [AgentController::class, 'availableTools']);
Route::post('agents/preview-system-prompt', [AgentController::class, 'previewSystemPrompt']);
Route::post('agents/check-schema-affected', [AgentController::class, 'checkSchemaAffectedAgents']);
Route::post('agents/validate-clone', [AgentController::class, 'validateClone']);
Route::get('agents/{agent}/skills', [AgentController::class, 'skills']);

/*
|--------------------------------------------------------------------------
| File Preview Routes
|--------------------------------------------------------------------------
|
| Preview file contents for clickable file paths in chat.
|
*/

Route::prefix('file')->group(function () {
    Route::post('preview', [FilePreviewController::class, 'preview']);
    Route::post('write', [FilePreviewController::class, 'write']);
    Route::post('check', [FilePreviewController::class, 'check']);
    Route::post('upload', [\App\Http\Controllers\Api\FileUploadController::class, 'upload']);
    Route::post('delete', [\App\Http\Controllers\Api\FileUploadController::class, 'delete']);
});
