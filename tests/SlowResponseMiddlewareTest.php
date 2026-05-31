<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Rozkalns\TelegramAlerts\Middleware\SlowResponseMiddleware;
use Rozkalns\TelegramAlerts\TelegramClient;

function livewirePayload(string $component = 'competition-results', ?string $method = 'loadRankings'): array
{
    $snapshot = json_encode([
        'memo' => ['name' => $component, 'id' => 'abc123', 'path' => '/', 'method' => 'GET'],
        'data' => [],
        'checksum' => 'fake-checksum',
    ]);

    $calls = $method !== null ? [['method' => $method, 'params' => []]] : [];

    return [
        '_token' => 'csrf-token',
        'components' => [
            [
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => $calls,
            ],
        ],
    ];
}

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

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '2 queries ('));
});

it('deactivates db listener after handle completes', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-deactivate', function (): string {
        DB::statement('SELECT 1');
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-deactivate')->assertOk();

    DB::statement('SELECT 1');
    DB::statement('SELECT 1');

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '1 queries ('));
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

it('shows livewire component and method for livewire requests', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test1/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-test1/update', livewirePayload('competition-results', 'loadRankings'))
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Component: <code>competition-results::loadRankings</code>')
        && ! str_contains((string) $request['text'], '/livewire-test1/update'));
});

it('defaults livewire method to __render when no calls present', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test2/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-test2/update', livewirePayload('counter', null))
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Component: <code>counter::__render</code>'));
});

it('falls back to standard format for malformed livewire payload', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test3/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-test3/update', ['_token' => 'csrf', 'components' => [['snapshot' => 'not-json']]])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test3/update'));
});

it('falls back to standard format when livewire components array is empty', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test4/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-test4/update', ['_token' => 'csrf', 'components' => []])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test4/update'));
});

it('falls back to standard format when livewire component entry is not an array', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test5/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-test5/update', ['_token' => 'csrf', 'components' => ['not-an-array']])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test5/update'));
});

it('falls back to standard format when livewire snapshot is not a string', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test6/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-test6/update', ['_token' => 'csrf', 'components' => [['snapshot' => 123, 'calls' => []]]])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test6/update'));
});

it('falls back to standard format when livewire memo is not an array', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test7/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $snapshot = json_encode(['memo' => 'not-an-array', 'data' => []]);
    $this->postJson('/livewire-test7/update', ['_token' => 'csrf', 'components' => [['snapshot' => $snapshot, 'calls' => []]]])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test7/update'));
});

it('falls back to standard format when livewire snapshot has no component name', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test8/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $snapshot = json_encode(['memo' => ['id' => 'abc'], 'data' => []]);
    $this->postJson('/livewire-test8/update', ['_token' => 'csrf', 'components' => [['snapshot' => $snapshot, 'calls' => []]]])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test8/update'));
});

it('rate limits livewire alerts by component and method', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test9/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-test9/update', livewirePayload('counter', 'increment'))
        ->assertOk();
    $this->postJson('/livewire-test9/update', livewirePayload('counter', 'increment'))
        ->assertOk();

    Http::assertSentCount(1);
});

it('sends separate livewire alerts for different components', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test10/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-test10/update', livewirePayload('counter', 'increment'))
        ->assertOk();
    $this->postJson('/livewire-test10/update', livewirePayload('user-profile', 'save'))
        ->assertOk();

    Http::assertSentCount(2);
});
