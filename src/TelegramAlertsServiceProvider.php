<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Rozkalns\TelegramAlerts\Commands\CheckBackupCommand;
use Rozkalns\TelegramAlerts\Commands\HeartbeatCommand;
use Rozkalns\TelegramAlerts\Commands\NotifyDeployCommand;
use Rozkalns\TelegramAlerts\Listeners\QueueFailureListener;
use Rozkalns\TelegramAlerts\Middleware\SlowResponseMiddleware;

final class TelegramAlertsServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telegram-alerts.php', 'telegram-alerts');

        $this->app->singleton(TelegramClient::class, fn (): TelegramClient => new TelegramClient(
            token: config()->string('telegram-alerts.bot_token'),
            chatId: config()->string('telegram-alerts.chat_id'),
            maxAttempts: config()->integer('telegram-alerts.retry_attempts', 3),
        ));

        config()->set('logging.channels.telegram', [
            'driver' => 'custom',
            'via' => TelegramLogChannel::class,
            'level' => config()->string('telegram-alerts.log_level', 'error'),
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/telegram-alerts.php' => config_path('telegram-alerts.php'),
        ], 'telegram-alerts-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                NotifyDeployCommand::class,
                HeartbeatCommand::class,
                CheckBackupCommand::class,
            ]);
        }

        if (config()->boolean('telegram-alerts.queue_failures', true)) {
            Queue::failing(fn (JobFailed $event) => $this->app->make(QueueFailureListener::class)->handle($event));
        }

        if (! $this->app->runningInConsole()) {
            /** @var \Illuminate\Foundation\Http\Kernel $kernel */
            $kernel = $this->app->make(Kernel::class);
            $kernel->pushMiddleware(SlowResponseMiddleware::class);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config()->boolean('telegram-alerts.scheduler_heartbeat', false)) {
                $schedule->command(HeartbeatCommand::class)->hourly();
            }

            $backupPath = config()->string('telegram-alerts.backup_path');
            if ($backupPath !== '') {
                $schedule->command(CheckBackupCommand::class)->dailyAt('06:00');
            }
        });
    }
}
