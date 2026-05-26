<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Rozkalns\TelegramAlerts\Middleware\SlowResponseMiddleware;
use Rozkalns\TelegramAlerts\TelegramClient;

beforeEach(function (): void {
    Http::fake();
    Cache::flush();
});

it('does not send when threshold is disabled', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 0);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-slow', fn (): string => 'ok');
    $this->get('/test-slow')->assertOk();

    Http::assertNothingSent();
});

it('does not send when response is fast', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 60000);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-fast', fn (): string => 'ok');
    $this->get('/test-fast')->assertOk();

    Http::assertNothingSent();
});

it('sends alert when response exceeds threshold', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-slow', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-slow?foo=bar')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Slow response')
        && str_contains((string) $request['text'], '/test-slow?foo=bar')
        && str_contains((string) $request['text'], 'TestApp'));
});

it('includes method and threshold in the message', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-method', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-method')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'GET')
        && str_contains((string) $request['text'], 'threshold'));
});

it('skips excluded paths', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);
    config()->set('telegram-alerts.slow_response_exclude', ['/test-excluded']);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-excluded', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-excluded')->assertOk();

    Http::assertNothingSent();
});

it('rate limits alerts per path and query string', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-ratelimit', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-ratelimit?a=1')->assertOk();
    $this->get('/test-ratelimit?a=1')->assertOk();

    Http::assertSentCount(1);
});

it('sends separate alerts for different query strings', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-qs', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-qs?a=1')->assertOk();
    $this->get('/test-qs?a=2')->assertOk();

    Http::assertSentCount(2);
});

it('skips when start timestamp is missing', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    $middleware = app(SlowResponseMiddleware::class);
    $request = Request::create('/test-no-start');

    $middleware->terminate($request);

    Http::assertNothingSent();
});

it('is a no-op when client is not configured', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);
    config()->set('telegram-alerts.bot_token', '');
    config()->set('telegram-alerts.chat_id', '');

    app()->forgetInstance(TelegramClient::class);
    app()->singleton(TelegramClient::class, fn (): TelegramClient => new TelegramClient(token: '', chatId: ''));

    Route::middleware(SlowResponseMiddleware::class)->get('/test-noconfig', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-noconfig')->assertOk();

    Http::assertNothingSent();
});

it('includes db query stats in the alert', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-db-stats', function (): string {
        DB::statement('SELECT 1');
        DB::statement('SELECT 2');
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-db-stats')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '2 queries'));
});

it('omits db stats line when no queries are executed', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-no-queries', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-no-queries')->assertOk();

    Http::assertSent(fn ($request): bool => ! str_contains((string) $request['text'], '🗄️'));
});
