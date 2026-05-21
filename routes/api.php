<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rozkalns\TelegramAlerts\Http\CiWebhookController;
use Rozkalns\TelegramAlerts\Http\CiWebhookMiddleware;

Route::post('/api/telegram-alerts/ci', CiWebhookController::class)
    ->middleware(CiWebhookMiddleware::class);
