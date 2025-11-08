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

Route::get("/terminal", [TerminalController::class, "index"])->name("terminal.index");
Route::post("/transcribe", [TerminalController::class, "transcribe"])->name("terminal.transcribe");

Route::get("/config", [ConfigController::class, "index"])->name("config.index");

// Agents management routes - MUST be before /config/{id} wildcard
Route::get("/config/agents/list", [ConfigController::class, "listAgents"])->name("config.agents.list");
Route::post("/config/agents/create", [ConfigController::class, "createAgent"])->name("config.agents.create");
Route::get("/config/agents/read/{filename}", [ConfigController::class, "readAgent"])->name("config.agents.read");
Route::post("/config/agents/save/{filename}", [ConfigController::class, "saveAgent"])->name("config.agents.save");
Route::delete("/config/agents/delete/{filename}", [ConfigController::class, "deleteAgent"])->name("config.agents.delete");

// Commands management routes - MUST be before /config/{id} wildcard
Route::get("/config/commands/list", [ConfigController::class, "listCommands"])->name("config.commands.list");
Route::post("/config/commands/create", [ConfigController::class, "createCommand"])->name("config.commands.create");
Route::get("/config/commands/read/{filename}", [ConfigController::class, "readCommand"])->name("config.commands.read");
Route::post("/config/commands/save/{filename}", [ConfigController::class, "saveCommand"])->name("config.commands.save");
Route::delete("/config/commands/delete/{filename}", [ConfigController::class, "deleteCommand"])->name("config.commands.delete");

// Hooks management routes - MUST be before /config/{id} wildcard
Route::get("/config/hooks", [ConfigController::class, "getHooks"])->name("config.hooks.get");
Route::post("/config/hooks", [ConfigController::class, "updateHooks"])->name("config.hooks.update");

// Skills management routes - MUST be before /config/{id} wildcard
Route::get("/config/skills/list", [ConfigController::class, "listSkills"])->name("config.skills.list");
Route::post("/config/skills/create", [ConfigController::class, "createSkill"])->name("config.skills.create");
Route::get("/config/skills/read/{skillName}", [ConfigController::class, "readSkill"])->name("config.skills.read");
Route::get("/config/skills/file/{skillName}/{path}", [ConfigController::class, "readSkillFile"])->name("config.skills.readFile")->where('path', '.*');
Route::post("/config/skills/file/{skillName}/{path}", [ConfigController::class, "saveSkillFile"])->name("config.skills.saveFile")->where('path', '.*');
Route::delete("/config/skills/file/{skillName}/{path}", [ConfigController::class, "deleteSkillFile"])->name("config.skills.deleteFile")->where('path', '.*');
Route::delete("/config/skills/delete/{skillName}", [ConfigController::class, "deleteSkill"])->name("config.skills.delete");

// Generic config routes - MUST be last to avoid catching specific routes
Route::get("/config/{id}", [ConfigController::class, "read"])->name("config.read");
Route::post("/config/{id}", [ConfigController::class, "save"])->name("config.save");
