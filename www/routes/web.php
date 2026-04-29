<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationSearchController;
use App\Http\Controllers\Api\FilePreviewController;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\ScreenController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TranscriptionController;
use App\Http\Controllers\Api\WorkspaceController as ApiWorkspaceController;
use App\Http\Controllers\Auth\SecuritySettingsController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClaudeAuthController;
use App\Http\Controllers\CodexAuthController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\CredentialsController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\SystemPromptController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

// ============================================================================
// Authentication Routes
// ============================================================================

// Fortify routes — only the minimal set needed for login/logout/2FA challenge.
// All other Fortify routes (the /user/two-factor-* management endpoints,
// password confirmation, etc.) are disabled via Fortify::ignoreRoutes() and
// replaced by our custom SecuritySettingsController flows.
Route::get('/login', [AuthenticatedSessionController::class, 'create'])
    ->middleware('guest:web')
    ->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware(['guest:web', 'throttle:login'])
    ->name('login.store');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:web')
    ->name('logout');
Route::get('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'create'])
    ->middleware('guest:web')
    ->name('two-factor.login');
Route::post('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
    ->middleware(['guest:web', 'throttle:two-factor'])
    ->name('two-factor.login.store');

// User setup wizard (first-run - create admin account)
// Throttled to prevent brute-forcing the first-run race window.
Route::prefix('setup')->name('setup.')->middleware('throttle:30,1')->group(function () {
    Route::get('/', [SetupController::class, 'index'])->name('index');
    Route::post('/skip', [SetupController::class, 'skipAuth'])->name('skip');
    Route::get('/credentials', [SetupController::class, 'showCredentials'])->name('credentials');
    Route::post('/credentials', [SetupController::class, 'storeCredentials']);
    Route::get('/totp', [SetupController::class, 'showTotp'])->name('totp');
    Route::post('/totp', [SetupController::class, 'verifyTotp']);
    Route::get('/recovery', [SetupController::class, 'showRecovery'])->name('recovery');
    Route::post('/recovery', [SetupController::class, 'confirmRecovery']);
});

// Two-factor challenge cancel (clears pending login session without requiring auth)
Route::post('/two-factor-challenge/cancel', function (\Illuminate\Http\Request $request) {
    $request->session()->forget(['login.id', 'login.remember']);
    return redirect()->route('login');
})->name('two-factor.cancel');

// Security settings
// The index route is accessible without auth (to show bypass status).
// Mutating routes require authentication AND are throttled to slow down
// attackers who obtain a live session cookie.
Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('/security', [SecuritySettingsController::class, 'index'])->name('security');

    Route::middleware(['auth', 'throttle:10,1'])->group(function () {
        // Add password (two-step: password → TOTP)
        Route::get('/security/add-password', [SecuritySettingsController::class, 'showAddPassword'])->name('security.add-password');
        Route::post('/security/add-password', [SecuritySettingsController::class, 'storeAddPasswordStep']);
        Route::get('/security/add-password/totp', [SecuritySettingsController::class, 'showAddPasswordTotp'])->name('security.add-password.totp');
        Route::post('/security/add-password/totp', [SecuritySettingsController::class, 'verifyAddPasswordTotp']);

        // Recovery codes preview + commit
        Route::get('/security/recovery-codes', [SecuritySettingsController::class, 'showRecoveryCodes'])->name('security.recovery-codes');
        Route::post('/security/recovery-codes/acknowledge', [SecuritySettingsController::class, 'acknowledgeRecoveryCodes'])->name('security.recovery-codes.acknowledge');

        // Regenerate recovery codes
        Route::post('/security/regenerate-recovery', [SecuritySettingsController::class, 'regenerateRecovery'])->name('security.regenerate-recovery');

        // Reset TOTP (two-step: verify current → setup new)
        Route::get('/security/reset-totp', [SecuritySettingsController::class, 'showResetTotp'])->name('security.reset-totp');
        Route::post('/security/reset-totp', [SecuritySettingsController::class, 'verifyCurrentTotp']);
        Route::get('/security/reset-totp/new', [SecuritySettingsController::class, 'showResetTotpNew'])->name('security.reset-totp.new');
        Route::post('/security/reset-totp/new', [SecuritySettingsController::class, 'confirmResetTotpNew']);
        Route::post('/security/reset-totp/cancel', [SecuritySettingsController::class, 'cancelResetTotp'])->name('security.reset-totp.cancel');

        // Session hygiene
        Route::post('/security/logout-other-sessions', [SecuritySettingsController::class, 'logoutOtherSessions'])->name('security.logout-other-sessions');

        // Change password
        Route::get('/security/change-password', [SecuritySettingsController::class, 'showChangePassword'])->name('security.change-password');
        Route::post('/security/change-password', [SecuritySettingsController::class, 'changePassword']);

        // Disable authentication entirely
        Route::delete('/security/disable-auth', [SecuritySettingsController::class, 'disableAuth'])->name('security.disable-auth');
    });
});

// ============================================================================
// Provider Setup (API keys, CLI tools)
// ============================================================================

// Provider setup wizard - configuring AI providers after user account exists
Route::get("/setup/provider", [CredentialsController::class, "showSetup"])->name("setup.provider");
Route::post("/setup/provider", [CredentialsController::class, "processSetup"])->name("setup.provider.process");

// Claude authentication routes - MUST be before wildcard routes
Route::get("/claude/auth", [ClaudeAuthController::class, "index"])->name("claude.auth");
Route::get("/claude/auth/status", [ClaudeAuthController::class, "status"])->name("claude.auth.status");
// Claude auth mutation routes (require authentication + throttle — these accept
// file uploads that write into $HOME/.claude/).
Route::middleware(['auth', 'throttle:10,1'])->group(function () {
    Route::post("/claude/auth/upload", [ClaudeAuthController::class, "upload"])->name("claude.auth.upload");
    Route::post("/claude/auth/upload-json", [ClaudeAuthController::class, "uploadJson"])->name("claude.auth.uploadJson");
    Route::delete("/claude/auth/logout", [ClaudeAuthController::class, "logout"])->name("claude.auth.logout");
});

// Codex authentication routes
Route::get("/codex/auth", [CodexAuthController::class, "index"])->name("codex.auth");
Route::get("/codex/auth/status", [CodexAuthController::class, "status"])->name("codex.auth.status");
// Codex auth mutation routes (require authentication + throttle)
Route::middleware(['auth', 'throttle:10,1'])->group(function () {
    Route::post("/codex/auth/upload-json", [CodexAuthController::class, "uploadJson"])->name("codex.auth.uploadJson");
    Route::delete("/codex/auth/logout", [CodexAuthController::class, "logout"])->name("codex.auth.logout");
});

// Chat - Multi-provider conversation interface
Route::get("/", [ChatController::class, "index"])->name("chat.index");
Route::get("/session/{sessionId}", [ChatController::class, "showSession"])
    ->whereUuid("sessionId")
    ->name("session.show");
Route::post("/chat/{conversationUuid}/session", [ChatController::class, "setSession"])
    ->whereUuid("conversationUuid")
    ->name("chat.session");
Route::post("/session/{sessionId}/last", [ChatController::class, "setLastSession"])
    ->whereUuid("sessionId")
    ->name("session.setLast");

// Config - Redirect to last visited section
Route::get("/config", [ConfigController::class, "index"])->name("config.index");

// System Prompt (core AI instructions)
Route::get("/config/system-prompt", [SystemPromptController::class, "show"])->name("config.system-prompt");
// Additional prompt (project-specific, commonly customized)
Route::get("/config/system-prompt/additional/edit", [SystemPromptController::class, "editAdditional"])->name("config.system-prompt.additional.edit");
Route::post("/config/system-prompt/additional", [SystemPromptController::class, "saveAdditional"])->name("config.system-prompt.additional.save");
Route::delete("/config/system-prompt/additional", [SystemPromptController::class, "resetAdditional"])->name("config.system-prompt.additional.reset");
// Core prompt (rarely modified)
Route::get("/config/system-prompt/core/edit", [SystemPromptController::class, "editCore"])->name("config.system-prompt.core.edit");
Route::post("/config/system-prompt/core", [SystemPromptController::class, "saveCore"])->name("config.system-prompt.core.save");
Route::delete("/config/system-prompt/core", [SystemPromptController::class, "resetCore"])->name("config.system-prompt.core.reset");

// Simple config file pages (CLAUDE.md, settings.json, nginx)
Route::get("/config/claude", [ConfigController::class, "showClaude"])->name("config.claude");
Route::post("/config/claude", [ConfigController::class, "saveClaude"])->name("config.claude.save");

Route::get("/config/settings", [ConfigController::class, "showSettings"])->name("config.settings");
Route::post("/config/settings", [ConfigController::class, "saveSettings"])->name("config.settings.save");

Route::get("/config/nginx", [ConfigController::class, "showNginx"])->name("config.nginx");
Route::post("/config/nginx", [ConfigController::class, "saveNginx"])->name("config.nginx.save");

// Agents management (database-backed)
Route::get("/config/agents", [ConfigController::class, "listAgents"])->name("config.agents");
Route::get("/config/agents/create", [ConfigController::class, "createAgentForm"])->name("config.agents.create");
Route::post("/config/agents", [ConfigController::class, "storeAgent"])->name("config.agents.store");
Route::get("/config/agents/{agent}/edit", [ConfigController::class, "editAgentForm"])->name("config.agents.edit");
Route::put("/config/agents/{agent}", [ConfigController::class, "updateAgent"])->name("config.agents.update");
Route::delete("/config/agents/{agent}", [ConfigController::class, "deleteAgent"])->name("config.agents.delete");
Route::post("/config/agents/{agent}/toggle-default", [ConfigController::class, "toggleAgentDefault"])->name("config.agents.toggle-default");
Route::post("/config/agents/{agent}/toggle-enabled", [ConfigController::class, "toggleAgentEnabled"])->name("config.agents.toggle-enabled");

// Claude Code settings (settings.json, CLAUDE.md, skills, import)
Route::get("/config/claude-code", [ConfigController::class, "showClaudeCode"])->name("config.claude-code");
Route::post("/config/claude-code", [ConfigController::class, "saveClaudeCode"])->name("config.claude-code.save");
Route::post("/config/claude-code/mcp", [ConfigController::class, "saveMcpServers"])->name("config.claude-code.mcp.save");
Route::delete("/config/claude-code/skill/{schema}/{skillId}", [ConfigController::class, "deleteMemorySkill"])->name("config.claude-code.skill.delete");
Route::post("/config/claude-code/base-prompt", [ConfigController::class, "saveBasePrompt"])->name("config.claude-code.base-prompt.save");
Route::get("/config/claude-code/base-prompt", [ConfigController::class, "getBasePrompt"])->name("config.claude-code.base-prompt.get");

// Config import (Claude Code export archive)
Route::post("/config/import/preview", [ConfigController::class, "importConfigPreview"])->name("config.import.preview");
Route::post("/config/import/apply", [ConfigController::class, "importConfigApply"])->name("config.import.apply");
Route::get("/config/import/schemas", [ConfigController::class, "getMemorySchemas"])->name("config.import.schemas");

// Credentials management
Route::get("/config/credentials", [CredentialsController::class, "show"])->name("config.credentials");
Route::post("/config/credentials/api-keys", [CredentialsController::class, "saveApiKeys"])->name("config.credentials.api-keys");
Route::delete("/config/credentials/api-keys/{provider}", [CredentialsController::class, "deleteApiKey"])->name("config.credentials.api-keys.delete");

// Environment: Custom credentials and system packages
Route::get("/config/environment", [EnvironmentController::class, "index"])->name("config.environment");
Route::post("/config/environment/credentials", [EnvironmentController::class, "storeCredential"])->name("config.environment.credentials.store");
Route::put("/config/environment/credentials/{credential}", [EnvironmentController::class, "updateCredential"])->name("config.environment.credentials.update");
Route::delete("/config/environment/credentials/{credential}", [EnvironmentController::class, "destroyCredential"])->name("config.environment.credentials.destroy");
Route::delete("/config/environment/packages/{id}", [EnvironmentController::class, "destroyPackage"])->name("config.environment.packages.destroy");

// Tools management
Route::get("/config/tools", [ConfigController::class, "listTools"])->name("config.tools");
Route::get("/config/tools/create", [ConfigController::class, "createToolForm"])->name("config.tools.create");
Route::post("/config/tools", [ConfigController::class, "storeTool"])->name("config.tools.store");
Route::get("/config/tools/{slug}", [ConfigController::class, "showTool"])->name("config.tools.show");
Route::get("/config/tools/{slug}/edit", [ConfigController::class, "editToolForm"])->name("config.tools.edit");
Route::put("/config/tools/{slug}", [ConfigController::class, "updateTool"])->name("config.tools.update");
Route::delete("/config/tools/{slug}", [ConfigController::class, "deleteTool"])->name("config.tools.delete");

// Native tools toggle (AJAX)
Route::post("/config/tools/native/toggle", [ConfigController::class, "toggleNativeTool"])->name("config.tools.native.toggle");

// Workspace management
Route::get("/config/workspaces", [\App\Http\Controllers\WorkspaceController::class, "index"])->name("config.workspaces");
Route::get("/config/workspaces/create", [\App\Http\Controllers\WorkspaceController::class, "create"])->name("config.workspaces.create");
Route::post("/config/workspaces", [\App\Http\Controllers\WorkspaceController::class, "store"])->name("config.workspaces.store");
Route::get("/config/workspaces/{workspace}/edit", [\App\Http\Controllers\WorkspaceController::class, "edit"])->name("config.workspaces.edit");
Route::put("/config/workspaces/{workspace}", [\App\Http\Controllers\WorkspaceController::class, "update"])->name("config.workspaces.update");
Route::delete("/config/workspaces/{workspace}", [\App\Http\Controllers\WorkspaceController::class, "destroy"])->name("config.workspaces.delete");
Route::post("/config/workspaces/{workspace}/restore", [\App\Http\Controllers\WorkspaceController::class, "restore"])->name("config.workspaces.restore");

// Memory management
Route::get("/config/memory", [MemoryController::class, "index"])->name("config.memory");
Route::get("/config/memory/tables/{tableName}", [MemoryController::class, "browseTable"])->name("config.memory.browse");
Route::get("/config/memory/tables/{tableName}/{rowId}", [MemoryController::class, "showRow"])
    ->whereUuid("rowId")
    ->name("config.memory.show");
Route::post("/config/memory/settings", [MemoryController::class, "updateSettings"])->name("config.memory.settings");
Route::post("/config/memory/snapshots", [MemoryController::class, "createSnapshot"])->name("config.memory.snapshots.create");
Route::post("/config/memory/snapshots/{filename}/restore", [MemoryController::class, "restoreSnapshot"])->name("config.memory.snapshots.restore");
Route::delete("/config/memory/snapshots/{filename}", [MemoryController::class, "deleteSnapshot"])->name("config.memory.snapshots.delete");
Route::get("/config/memory/export", [MemoryController::class, "export"])->name("config.memory.export");
Route::post("/config/memory/import", [MemoryController::class, "import"])->name("config.memory.import");
Route::get("/config/memory/import/configure", [MemoryController::class, "importConfigure"])->name("config.memory.import.configure");
Route::post("/config/memory/import/apply", [MemoryController::class, "importApply"])->name("config.memory.import.apply");
Route::post("/config/memory/import/cancel", [MemoryController::class, "importCancel"])->name("config.memory.import.cancel");
Route::patch("/config/memory/database/{memoryDatabase}", [MemoryController::class, "updateDatabase"])->name("config.memory.update-database");
Route::post("/config/memory/database", [MemoryController::class, "createDatabase"])->name("config.memory.create-database");
Route::delete("/config/memory/database/{memoryDatabase}", [MemoryController::class, "deleteDatabase"])->name("config.memory.delete-database");

// Backup and restore
Route::get("/config/backup", [\App\Http\Controllers\BackupController::class, "show"])->name("config.backup");
Route::post("/config/backup/create", [\App\Http\Controllers\BackupController::class, "create"])->name("config.backup.create");
Route::get("/config/backup/download/{filename}", [\App\Http\Controllers\BackupController::class, "download"])->name("config.backup.download");
Route::delete("/config/backup/{filename}", [\App\Http\Controllers\BackupController::class, "delete"])->name("config.backup.delete");
Route::post("/config/backup/restore", [\App\Http\Controllers\BackupController::class, "restore"])->name("config.backup.restore");

// System management (available in both environments)
// GET is accessible without explicit auth (EnsureSetupComplete handles it),
// but mutation routes require auth middleware as defense-in-depth.
Route::get("/config/system", [ConfigController::class, "showSystem"])->name("config.system");
Route::middleware('auth')->group(function () {
    Route::post("/config/system/restart", [ConfigController::class, "restartContainers"])->name("config.system.restart");
    Route::post("/config/system/check-update", [ConfigController::class, "checkUpdate"])->name("config.system.check-update");
    Route::post("/config/system/apply-update", [ConfigController::class, "applyUpdate"])->name("config.system.apply-update");

    Route::post("/config/system/switch-version", [ConfigController::class, "switchVersion"])->name("config.system.switch-version");

    // Local-only operations (rebuild from scratch, git pull, branch switch)
    if (app()->environment('local')) {
        Route::post("/config/system/rebuild", [ConfigController::class, "rebuildContainers"])->name("config.system.rebuild");
        Route::post("/config/system/pull-main", [ConfigController::class, "pullFromMain"])->name("config.system.pull-main");
        Route::post("/config/system/switch-branch", [ConfigController::class, "switchBranch"])->name("config.system.switch-branch");
    }
});

/*
|--------------------------------------------------------------------------
| API Routes (moved from routes/api.php)
|--------------------------------------------------------------------------
|
| These are browser-only AJAX endpoints called from the SPA frontend.
| They are web routes with full CSRF protection, grouped under /api prefix.
|
*/

Route::prefix('api')->group(function () {

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
    | IMPORTANT: Specific routes (like /active) must come BEFORE wildcard routes.
    |
    */

    Route::get('workspaces', [ApiWorkspaceController::class, 'index']);

    // Active workspace routes (must be before {workspace} routes)
    Route::get('workspaces/active', [ApiWorkspaceController::class, 'getActive']);
    Route::post('workspaces/active/{workspace}', [ApiWorkspaceController::class, 'setActive']);

    // Wildcard routes must come last
    Route::get('workspaces/{workspace}', [ApiWorkspaceController::class, 'show']);
    Route::get('workspaces/{workspace}/agents', [ApiWorkspaceController::class, 'agents']);
    Route::get('workspaces/{workspace}/memory-databases', [ApiWorkspaceController::class, 'memoryDatabases']);

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
        Route::get('download', [FilePreviewController::class, 'download']);
        Route::post('upload', [FileUploadController::class, 'upload']);
        Route::post('delete', [FileUploadController::class, 'delete']);
    });

    /*
    |--------------------------------------------------------------------------
    | Session & Screen Routes
    |--------------------------------------------------------------------------
    |
    | Sessions group screens (chats + panels) into workspaces.
    | Screens are individual tabs within a session.
    |
    */

    Route::prefix('sessions')->group(function () {
        Route::get('/', [SessionController::class, 'index']);
        Route::get('latest-activity', [SessionController::class, 'latestActivity']);
        Route::post('/', [SessionController::class, 'store']);
        Route::get('{session}', [SessionController::class, 'show']);
        Route::patch('{session}', [SessionController::class, 'update']);
        Route::delete('{session}', [SessionController::class, 'destroy']);
        Route::post('{session}/archive', [SessionController::class, 'archive']);
        Route::post('{session}/restore', [SessionController::class, 'restore']);
        Route::post('{session}/active', [SessionController::class, 'setActive']);
        Route::post('{session}/save-as-default', [SessionController::class, 'saveAsDefault']);
        Route::post('{session}/clear-default', [SessionController::class, 'clearDefault']);
        Route::get('{session}/archived-conversations', [SessionController::class, 'archivedConversations']);

        // Screen operations within a session
        Route::post('{session}/screens/chat', [ScreenController::class, 'createChat']);
        Route::post('{session}/screens/panel', [ScreenController::class, 'createPanel']);
        Route::post('{session}/screens/reorder', [ScreenController::class, 'reorder']);
    });

    Route::prefix('screens')->group(function () {
        Route::get('{screen}', [ScreenController::class, 'show']);
        Route::post('{screen}/activate', [ScreenController::class, 'activate']);
        Route::delete('{screen}', [ScreenController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Panel Routes
    |--------------------------------------------------------------------------
    |
    | Panel state management, rendering, and peek (AI awareness).
    |
    */

    Route::get('panels', [PanelController::class, 'availablePanels']);

    Route::prefix('panel/{panelState}')->group(function () {
        Route::get('render', [PanelController::class, 'render']);
        Route::get('state', [PanelController::class, 'getState']);
        Route::post('state', [PanelController::class, 'updateState']);
        Route::post('action', [PanelController::class, 'action']);
        Route::get('peek', [PanelController::class, 'peek']);
        Route::delete('/', [PanelController::class, 'destroy']);
    });
});
