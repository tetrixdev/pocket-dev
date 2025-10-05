<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TerminalController::class, 'index'])->name('terminal.index');
Route::post('/transcribe', [TerminalController::class, 'transcribe'])->name('terminal.transcribe');

Route::get('/config', [ConfigController::class, 'index'])->name('config.index');
Route::get('/config/{id}', [ConfigController::class, 'read'])->name('config.read');
Route::post('/config/{id}', [ConfigController::class, 'save'])->name('config.save');
