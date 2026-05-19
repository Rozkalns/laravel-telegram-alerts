<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
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

    $this->get('/test-slow')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Slow response')
        && str_contains((string) $request['text'], 'test-slow')
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

it('rate limits alerts per path', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-ratelimit', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-ratelimit')->assertOk();
    $this->get('/test-ratelimit')->assertOk();

    Http::assertSentCount(1);
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
