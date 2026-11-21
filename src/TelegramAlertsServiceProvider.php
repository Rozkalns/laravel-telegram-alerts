<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Rozkalns\TelegramAlerts\Commands\CheckBackupCommand;
use Rozkalns\TelegramAlerts\Commands\HeartbeatCommand;
use Rozkalns\TelegramAlerts\Commands\NotifyDeployCommand;
use Rozkalns\TelegramAlerts\Commands\SetupCiWebhookCommand;
use Rozkalns\TelegramAlerts\Listeners\LivewireTamperingReporter;
use Rozkalns\TelegramAlerts\Listeners\QueueFailureListener;
use Rozkalns\TelegramAlerts\Middleware\SlowResponseMiddleware;
use Rozkalns\TelegramAlerts\Support\Host;
use Rozkalns\TelegramAlerts\Support\IpIdentity;
use Rozkalns\TelegramAlerts\Support\Resolver;
use Rozkalns\TelegramAlerts\Support\SystemHost;
use Rozkalns\TelegramAlerts\Support\SystemResolver;
use Throwable;

final class TelegramAlertsServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telegram-alerts.php', 'telegram-alerts');

        $this->app->scoped(TelegramClient::class, fn (): TelegramClient => new TelegramClient(
            token: config()->string('telegram-alerts.bot_token'),
            chatId: config()->string('telegram-alerts.chat_id'),
            maxAttempts: config()->integer('telegram-alerts.retry_attempts', 3),
        ));

        $this->app->bind(Resolver::class, SystemResolver::class);
        $this->app->bind(Host::class, SystemHost::class);

        $this->app->scoped(IpIdentity::class, fn (): IpIdentity => new IpIdentity(
            resolver: $this->app->make(Resolver::class),
            budgetMs: config()->integer('telegram-alerts.identify_caller_budget_ms', 1000),
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
                SetupCiWebhookCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        if (config()->boolean('telegram-alerts.queue_failures', true)) {
            Queue::failing(fn (JobFailed $event) => $this->app->make(QueueFailureListener::class)->handle($event));
        }

        $this->registerLivewireTampering();

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

    private function registerLivewireTampering(): void
    {
        if (! config()->boolean('telegram-alerts.livewire_tampering', true) || ! class_exists(Livewire::class)) {
            return;
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! $handler instanceof Handler) {
                return;
            }

            $handler->buildContextUsing(fn (): array => array_filter([
                'livewire_components' => LivewireTamperingReporter::components($this->app->make(Request::class)),
            ]));

            $handler->reportable(function (Throwable $e): void {
                $this->app->make(LivewireTamperingReporter::class)->report($e, $this->app->make(Request::class));
            });
        });
    }
}
