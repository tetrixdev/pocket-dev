<?php

use App\Http\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/terminal', [TerminalController::class, 'index'])->name('terminal.index');
Route::post('/terminal/transcribe', [TerminalController::class, 'transcribe'])->name('terminal.transcribe');
