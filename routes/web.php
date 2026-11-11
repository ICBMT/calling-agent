<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Workspace\AgentController;
use App\Http\Controllers\Workspace\CallController;
use App\Http\Controllers\Workspace\OverviewController;
use App\Http\Controllers\Workspace\PlaygroundController;
use App\Http\Controllers\Workspace\ProviderController;
use App\Http\Controllers\Workspace\SettingsController;
use App\Http\Controllers\Workspace\SetupController;
use App\Http\Controllers\Workspace\VoiceSessionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'marketing.home')->name('home');
Route::view('/privacy', 'marketing.privacy')->name('privacy');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:registration');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'workspace'])->prefix('app')->name('workspace.')->group(function () {
    Route::get('/', OverviewController::class)->name('overview');
    Route::resource('agents', AgentController::class)->except('show');
    foreach (['providers' => 'providers.', 'platform/providers' => 'platform.providers.'] as $prefix => $name) {
        Route::prefix($prefix)->name($name)->middleware('provider.scope')->group(function () {
            $controller = ProviderController::class;
            Route::get('/', [$controller, 'index'])->name('index');
            Route::get('/compare', [$controller, 'compare'])->name('compare');
            Route::get('/create', [$controller, 'create'])->name('create');
            Route::post('/', [$controller, 'store'])->middleware('throttle:provider-writes')->name('store');
            Route::get('/{configuration}/edit', [$controller, 'edit'])->whereNumber('configuration')->name('edit');
            Route::put('/{configuration}', [$controller, 'update'])->whereNumber('configuration')->middleware('throttle:provider-writes')->name('update');
            Route::put('/{configuration}/selection', [$controller, 'selection'])->whereNumber('configuration')->middleware('throttle:provider-writes')->name('selection');
            Route::post('/{configuration}/test', [$controller, 'test'])->whereNumber('configuration')->middleware('throttle:provider-tests')->name('test');
            Route::delete('/{configuration}/credential', [$controller, 'removeCredential'])->whereNumber('configuration')->middleware('throttle:provider-writes')->name('credential.destroy');
            Route::delete('/{configuration}', [$controller, 'destroy'])->whereNumber('configuration')->middleware('throttle:provider-writes')->name('destroy');
        });
    }
    Route::get('/calls', [CallController::class, 'index'])->name('calls');
    Route::get('/calls/{call}', [CallController::class, 'show'])->whereUuid('call')->name('calls.show');
    Route::get('/playground', PlaygroundController::class)->name('playground');
    Route::post('/voice-sessions', [VoiceSessionController::class, 'store'])->middleware('throttle:voice-starts')->name('voice-sessions.store');
    Route::get('/voice-sessions/{session}', [VoiceSessionController::class, 'show'])->whereUuid('session')->middleware('throttle:voice-polls')->name('voice-sessions.show');
    Route::delete('/voice-sessions/{session}', [VoiceSessionController::class, 'destroy'])->whereUuid('session')->middleware('throttle:voice-stops')->name('voice-sessions.destroy');
    Route::get('/setup', SetupController::class)->name('setup');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});
