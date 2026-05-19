<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use Illuminate\Support\ServiceProvider;
use Rozkalns\TelegramAlerts\Commands\NotifyDeployCommand;

final class TelegramAlertsServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telegram-alerts.php', 'telegram-alerts');

        config()->set('logging.channels.telegram', [
            'driver' => 'monolog',
            'handler' => TelegramHandler::class,
            'handler_with' => [
                'token' => config()->string('telegram-alerts.bot_token'),
                'chatId' => config()->string('telegram-alerts.chat_id'),
            ],
            'level' => env('LOG_TELEGRAM_LEVEL', 'error'),
        ]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telegram-alerts.php' => config_path('telegram-alerts.php'),
            ], 'telegram-alerts-config');

            $this->commands([
                NotifyDeployCommand::class,
            ]);
        }
    }
}
