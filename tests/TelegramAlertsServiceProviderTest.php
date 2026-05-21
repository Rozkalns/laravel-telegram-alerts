<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Rozkalns\TelegramAlerts\Http\CiWebhookController;
use Rozkalns\TelegramAlerts\Middleware\SlowResponseMiddleware;
use Rozkalns\TelegramAlerts\TelegramAlertsServiceProvider;
use Rozkalns\TelegramAlerts\TelegramClient;

it('registers telegram client as a singleton', function (): void {
    $clientA = app(TelegramClient::class);
    $clientB = app(TelegramClient::class);

    expect($clientA)->toBe($clientB);
});

it('registers the telegram log channel', function (): void {
    $channels = config('logging.channels');

    expect($channels)->toHaveKey('telegram');
    expect($channels['telegram']['driver'])->toBe('custom');
});

it('pushes slow response middleware when not running in console', function (): void {
    $app = Mockery::mock(app())->makePartial();
    $app->shouldReceive('runningInConsole')->andReturn(false);

    /** @var Illuminate\Foundation\Http\Kernel $kernel */
    $kernel = $app->make(Kernel::class);

    $provider = new TelegramAlertsServiceProvider($app);
    $provider->boot();

    expect($kernel->hasMiddleware(SlowResponseMiddleware::class))->toBeTrue();
});

it('registers the ci webhook route', function (): void {
    $routes = app('router')->getRoutes();
    $route = $routes->match(
        Request::create('/api/telegram-alerts/ci', 'POST'),
    );

    expect($route->getActionName())->toBe(CiWebhookController::class);
});

it('registers scheduled commands when config is enabled', function (): void {
    config()->set('telegram-alerts.scheduler_heartbeat', true);
    config()->set('telegram-alerts.backup_path', '/tmp/test-backup-*.sql');

    $schedule = app(Schedule::class);
    $events = $schedule->events();
    $commands = array_map(fn (Event $event) => $event->command, $events);
    $commandStr = implode(' ', $commands);

    expect($commandStr)->toContain('telegram:heartbeat')
        ->and($commandStr)->toContain('telegram:check-backup');
});
