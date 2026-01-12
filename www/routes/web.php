<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClaudeAuthController;
use App\Http\Controllers\CodexAuthController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\CredentialsController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\SystemPromptController;
use Illuminate\Support\Facades\Route;

// Setup wizard (first-run)
Route::get("/setup", [CredentialsController::class, "showSetup"])->name("setup");
Route::post("/setup", [CredentialsController::class, "processSetup"])->name("setup.process");

// Claude authentication routes - MUST be before wildcard routes
Route::get("/claude/auth", [ClaudeAuthController::class, "index"])->name("claude.auth");
Route::get("/claude/auth/status", [ClaudeAuthController::class, "status"])->name("claude.auth.status");
Route::post("/claude/auth/upload", [ClaudeAuthController::class, "upload"])->name("claude.auth.upload");
Route::post("/claude/auth/upload-json", [ClaudeAuthController::class, "uploadJson"])->name("claude.auth.uploadJson");
Route::delete("/claude/auth/logout", [ClaudeAuthController::class, "logout"])->name("claude.auth.logout");

// Codex authentication routes
Route::get("/codex/auth", [CodexAuthController::class, "index"])->name("codex.auth");
Route::get("/codex/auth/status", [CodexAuthController::class, "status"])->name("codex.auth.status");
Route::post("/codex/auth/upload-json", [CodexAuthController::class, "uploadJson"])->name("codex.auth.uploadJson");
Route::delete("/codex/auth/logout", [CodexAuthController::class, "logout"])->name("codex.auth.logout");

// Chat - Multi-provider conversation interface
Route::get("/", [ChatController::class, "index"])->name("chat.index");
Route::get("/chat/{conversationUuid}", [ChatController::class, "show"])
    ->whereUuid("conversationUuid")
    ->name("chat.conversation");
Route::post("/chat/{conversationUuid}/session", [ChatController::class, "setSession"])
    ->whereUuid("conversationUuid")
    ->name("chat.session");

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

// Hooks editor
Route::get("/config/hooks", [ConfigController::class, "showHooks"])->name("config.hooks");
Route::post("/config/hooks", [ConfigController::class, "saveHooks"])->name("config.hooks.save");

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

// Developer tools (only available in local environment)
if (app()->environment('local')) {
    Route::get("/config/developer", [ConfigController::class, "showDeveloper"])->name("config.developer");
    Route::post("/config/developer/force-recreate", [ConfigController::class, "forceRecreate"])->name("config.developer.force-recreate");
    Route::post("/config/developer/rebuild", [ConfigController::class, "rebuildContainers"])->name("config.developer.rebuild");
}
