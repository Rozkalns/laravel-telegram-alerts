<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rozkalns\TelegramAlerts\Http\CiWebhookController;
use Rozkalns\TelegramAlerts\Http\CiWebhookMiddleware;
use Rozkalns\TelegramAlerts\Http\GlitchTipWebhookController;
use Rozkalns\TelegramAlerts\Http\GlitchTipWebhookMiddleware;

Route::post('/api/telegram-alerts/ci', CiWebhookController::class)
    ->middleware(CiWebhookMiddleware::class);

Route::post('/api/telegram-alerts/glitchtip', GlitchTipWebhookController::class)
    ->middleware(GlitchTipWebhookMiddleware::class);
