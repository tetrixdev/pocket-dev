<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/transcribe', [DashboardController::class, 'transcribeAudio'])->name('transcribe');
