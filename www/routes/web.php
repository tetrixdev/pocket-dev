<?php

use App\Http\Controllers\ClaudeAuthController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;

// Claude authentication routes - MUST be before wildcard routes
Route::get("/claude/auth", [ClaudeAuthController::class, "index"])->name("claude.auth");
Route::get("/claude/auth/status", [ClaudeAuthController::class, "status"])->name("claude.auth.status");
Route::post("/claude/auth/upload", [ClaudeAuthController::class, "upload"])->name("claude.auth.upload");
Route::post("/claude/auth/upload-json", [ClaudeAuthController::class, "uploadJson"])->name("claude.auth.uploadJson");
Route::delete("/claude/auth/logout", [ClaudeAuthController::class, "logout"])->name("claude.auth.logout");

// Claude chat routes - Blade view with streaming
Route::view("/session/{sessionId}", "chat")->name("claude.session");
Route::view("/", "chat")->name("claude.index");

// V2 Chat - Multi-provider direct API
Route::view("/chat-v2/{conversationUuid?}", "chat-v2")->name("chat.v2");

Route::get("/terminal", [TerminalController::class, "index"])->name("terminal.index");
Route::post("/transcribe", [TerminalController::class, "transcribe"])->name("terminal.transcribe");

// Config - Redirect to last visited section
Route::get("/config", [ConfigController::class, "index"])->name("config.index");

// Simple config file pages (CLAUDE.md, settings.json, nginx)
Route::get("/config/claude", [ConfigController::class, "showClaude"])->name("config.claude");
Route::post("/config/claude", [ConfigController::class, "saveClaude"])->name("config.claude.save");

Route::get("/config/settings", [ConfigController::class, "showSettings"])->name("config.settings");
Route::post("/config/settings", [ConfigController::class, "saveSettings"])->name("config.settings.save");

Route::get("/config/nginx", [ConfigController::class, "showNginx"])->name("config.nginx");
Route::post("/config/nginx", [ConfigController::class, "saveNginx"])->name("config.nginx.save");

// Agents management
Route::get("/config/agents", [ConfigController::class, "listAgents"])->name("config.agents");
Route::get("/config/agents/create", [ConfigController::class, "createAgentForm"])->name("config.agents.create");
Route::post("/config/agents", [ConfigController::class, "storeAgent"])->name("config.agents.store");
Route::get("/config/agents/{filename}/edit", [ConfigController::class, "editAgentForm"])->name("config.agents.edit");
Route::put("/config/agents/{filename}", [ConfigController::class, "updateAgent"])->name("config.agents.update");
Route::delete("/config/agents/{filename}", [ConfigController::class, "deleteAgent"])->name("config.agents.delete");

// Commands management
Route::get("/config/commands", [ConfigController::class, "listCommands"])->name("config.commands");
Route::get("/config/commands/create", [ConfigController::class, "createCommandForm"])->name("config.commands.create");
Route::post("/config/commands", [ConfigController::class, "storeCommand"])->name("config.commands.store");
Route::get("/config/commands/{filename}/edit", [ConfigController::class, "editCommandForm"])->name("config.commands.edit");
Route::put("/config/commands/{filename}", [ConfigController::class, "updateCommand"])->name("config.commands.update");
Route::delete("/config/commands/{filename}", [ConfigController::class, "deleteCommand"])->name("config.commands.delete");

// Hooks editor
Route::get("/config/hooks", [ConfigController::class, "showHooks"])->name("config.hooks");
Route::post("/config/hooks", [ConfigController::class, "saveHooks"])->name("config.hooks.save");

// Skills management
Route::get("/config/skills", [ConfigController::class, "listSkills"])->name("config.skills");
Route::get("/config/skills/create", [ConfigController::class, "createSkillForm"])->name("config.skills.create");
Route::post("/config/skills", [ConfigController::class, "storeSkill"])->name("config.skills.store");
Route::get("/config/skills/{skillName}/edit", [ConfigController::class, "editSkillForm"])->name("config.skills.edit");
Route::put("/config/skills/{skillName}", [ConfigController::class, "updateSkill"])->name("config.skills.update");
Route::delete("/config/skills/{skillName}", [ConfigController::class, "deleteSkill"])->name("config.skills.delete");

// Skill file management (for file browser within skill edit page)
Route::get("/config/skills/{skillName}/files/{path}", [ConfigController::class, "getSkillFile"])->name("config.skills.file")->where('path', '.*');
Route::put("/config/skills/{skillName}/files/{path}", [ConfigController::class, "saveSkillFile"])->name("config.skills.file.save")->where('path', '.*');
Route::delete("/config/skills/{skillName}/files/{path}", [ConfigController::class, "deleteSkillFile"])->name("config.skills.file.delete")->where('path', '.*');
