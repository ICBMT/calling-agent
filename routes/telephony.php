<?php

use App\Http\Controllers\TelephonyWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook endpoints for telephony providers (Twilio, Telnyx, SIP trunk)
Route::post('/inbound/{provider}', [TelephonyWebhookController::class, 'inbound'])->name('inbound');
Route::post('/status/{provider}', [TelephonyWebhookController::class, 'status'])->name('status');
