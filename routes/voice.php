<?php

use App\Http\Controllers\VoiceWorkerController;
use Illuminate\Support\Facades\Route;

// No web/session/CSRF middleware: each bounded POST is authenticated by HMAC + DB replay protection.
// Reverse-proxy operators should additionally restrict this path to their worker network.
Route::post('/sessions/{session}/claim', [VoiceWorkerController::class, 'claim'])->whereUuid('session')->name('claim');
Route::post('/sessions/{session}/heartbeat', [VoiceWorkerController::class, 'heartbeat'])->whereUuid('session')->name('heartbeat');
Route::post('/sessions/{session}/finish', [VoiceWorkerController::class, 'finish'])->whereUuid('session')->name('finish');
