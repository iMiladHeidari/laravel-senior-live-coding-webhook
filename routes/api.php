<?php

use App\Http\Controllers\FiatWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/fiat-rates', [FiatWebhookController::class, 'handle'])
    ->middleware(['throttle:fiat-webhook', 'webhook.auth']);
