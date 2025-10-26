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
Route::get("/config/{id}", [ConfigController::class, "read"])->name("config.read");
Route::post("/config/{id}", [ConfigController::class, "save"])->name("config.save");
