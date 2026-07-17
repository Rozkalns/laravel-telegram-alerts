<?php

declare(strict_types=1);

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Rozkalns\TelegramAlerts\Middleware\SlowResponseMiddleware;
use Rozkalns\TelegramAlerts\TelegramClient;

function livewirePayload(string $component = 'competition-results', ?string $method = 'loadRankings', array $data = [], array $params = []): array
{
    $snapshot = json_encode([
        'memo' => ['name' => $component, 'id' => 'abc123', 'path' => '/', 'method' => 'GET'],
        'data' => $data,
        'checksum' => 'fake-checksum',
    ]);

    $calls = $method !== null ? [['method' => $method, 'params' => $params]] : [];

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

function passSlowThreshold(): void
{
    Carbon::setTestNow(Carbon::now()->addMilliseconds(80));
}

function postSlowLivewire(string $path, array $payload): TestResponse
{
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post($path, function (): string {
        passSlowThreshold();

        return 'ok';
    });

    return test()->postJson($path, $payload);
}

function getSlowDb(string $path, Closure $body): TestResponse
{
    config()->set('telegram-alerts.slow_response_threshold', 50);
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Route::middleware(SlowResponseMiddleware::class)->get($path, function () use ($body): string {
        $body();
        passSlowThreshold();

        return 'ok';
    });

    return test()->get($path);
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
        passSlowThreshold();

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
        passSlowThreshold();

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
        passSlowThreshold();

        return 'ok';
    });

    $this->get('/test-excluded')->assertOk();

    Http::assertNothingSent();
});

it('rate limits alerts per path and query string', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-ratelimit', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->get('/test-ratelimit?a=1')->assertOk();
    $this->get('/test-ratelimit?a=1')->assertOk();

    Http::assertSentCount(1);
});

it('sends separate alerts for different query strings', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-qs', function (): string {
        passSlowThreshold();

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
        passSlowThreshold();

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
        passSlowThreshold();

        return 'ok';
    });

    $this->get('/test-db-stats')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'DB ')
        && str_contains((string) $request['text'], 'app ')
        && str_contains((string) $request['text'], '2 queries'));
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
        passSlowThreshold();

        return 'ok';
    });

    $this->get('/test-deactivate')->assertOk();

    DB::statement('SELECT 1');
    DB::statement('SELECT 1');

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '1 queries'));
});

it('shows the db-vs-app split', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-split', function (): string {
        DB::statement('SELECT 1');
        passSlowThreshold();

        return 'ok';
    });

    $this->get('/test-split')->assertOk();

    Http::assertSent(fn ($request): bool => (bool) preg_match('/🗄️ DB \d+ms · app \d+ms · 1 queries/u', (string) $request['text']));
});

it('appends an n+1 hint when query count is high', function (): void {
    config()->set('telegram-alerts.n_plus_one_threshold', 2);

    getSlowDb('/test-nplus1', function (): void {
        DB::statement('SELECT 1');
        DB::statement('SELECT 2');
    })->assertOk();

    Http::assertSent(fn ($r): bool => str_contains((string) $r['text'], '⚠️ N+1?'));
});

it('omits the n+1 hint below the threshold', function (): void {
    config()->set('telegram-alerts.n_plus_one_threshold', 100);

    getSlowDb('/test-no-nplus1', function (): void {
        DB::statement('SELECT 1');
    })->assertOk();

    Http::assertSent(fn ($r): bool => ! str_contains((string) $r['text'], 'N+1'));
});

it('shows the slowest query when over the threshold', function (): void {
    config()->set('telegram-alerts.slow_query_threshold', 0);

    getSlowDb('/test-slowq', function (): void {
        DB::statement('SELECT 1');
    })->assertOk();

    Http::assertSent(fn ($r): bool => str_contains((string) $r['text'], '🐢 slowest:')
        && str_contains((string) $r['text'], 'SELECT 1'));
});

it('omits the slowest query line below the threshold', function (): void {
    config()->set('telegram-alerts.slow_query_threshold', 100);

    getSlowDb('/test-no-slowq', function (): void {
        DB::statement('SELECT 1');
    })->assertOk();

    Http::assertSent(fn ($r): bool => ! str_contains((string) $r['text'], '🐢'));
});

it('truncates a long slowest query', function (): void {
    config()->set('telegram-alerts.slow_query_threshold', 0);

    getSlowDb('/test-slowq-long', function (): void {
        DB::statement('SELECT '.str_repeat('1+', 80).'1');
    })->assertOk();

    Http::assertSent(fn ($r): bool => str_contains((string) $r['text'], '🐢 slowest:')
        && str_contains((string) $r['text'], '…'));
});

it('shows the authenticated user with name, email, and id', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-user', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->actingAs(new GenericUser(['id' => 17, 'name' => 'Rudolfs', 'email' => 'rudolfs@example.com']))
        ->get('/test-user')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '👤 Rudolfs · rudolfs@example.com (#17)'));
});

it('falls back to a generic user label when name and email are absent', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-user-id-only', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->actingAs(new GenericUser(['id' => 42]))
        ->get('/test-user-id-only')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '👤 User (#42)'));
});

it('omits the user line for guest requests', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-guest', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->get('/test-guest')->assertOk();

    Http::assertSent(fn ($request): bool => ! str_contains((string) $request['text'], '👤'));
});

it('omits db stats line when no queries are executed', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-no-queries', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->get('/test-no-queries')->assertOk();

    Http::assertSent(fn ($request): bool => ! str_contains((string) $request['text'], '🗄️'));
});

it('shows livewire component and method for livewire requests', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test1/update', function (): string {
        passSlowThreshold();

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
        passSlowThreshold();

        return 'ok';
    });

    $this->postJson('/livewire-test2/update', livewirePayload('counter', null))
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Component: <code>counter::__render</code>'));
});

it('falls back to standard format for malformed livewire payload', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test3/update', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->postJson('/livewire-test3/update', ['_token' => 'csrf', 'components' => [['snapshot' => 'not-json']]])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test3/update'));
});

it('falls back to standard format when livewire components array is empty', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test4/update', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->postJson('/livewire-test4/update', ['_token' => 'csrf', 'components' => []])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test4/update'));
});

it('falls back to standard format when livewire component entry is not an array', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test5/update', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->postJson('/livewire-test5/update', ['_token' => 'csrf', 'components' => ['not-an-array']])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test5/update'));
});

it('falls back to standard format when livewire snapshot is not a string', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test6/update', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->postJson('/livewire-test6/update', ['_token' => 'csrf', 'components' => [['snapshot' => 123, 'calls' => []]]])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test6/update'));
});

it('falls back to standard format when livewire memo is not an array', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test7/update', function (): string {
        passSlowThreshold();

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
        passSlowThreshold();

        return 'ok';
    });

    $snapshot = json_encode(['memo' => ['id' => 'abc'], 'data' => []]);
    $this->postJson('/livewire-test8/update', ['_token' => 'csrf', 'components' => [['snapshot' => $snapshot, 'calls' => []]]])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-test8/update'));
});

it('includes livewire method call arguments', function (): void {
    postSlowLivewire('/livewire-params/update', livewirePayload('participants.index', 'exportStartingLists', [], [42, ['ignored-array']]))
        ->assertOk();

    Http::assertSent(fn ($r): bool => str_contains((string) $r['text'], 'Component: <code>participants.index::exportStartingLists(42)</code>'));
});

it('extracts a bound model reference from the snapshot', function (): void {
    postSlowLivewire('/livewire-model/update', livewirePayload('participants.index', 'render', [
        'competition' => [null, ['class' => 'App\\Models\\Competition', 'key' => 42, 's' => 'mdl']],
    ]))->assertOk();

    Http::assertSent(fn ($r): bool => str_contains((string) $r['text'], '🔗 Competition #42'));
});

it('extracts a model reference without a key for new models', function (): void {
    postSlowLivewire('/livewire-model-nokey/update', livewirePayload('plan.editor', 'save', [
        'draft' => [null, ['class' => 'App\\Models\\Plan', 's' => 'mdl']],
    ]))->assertOk();

    Http::assertSent(fn ($r): bool => str_contains((string) $r['text'], '🔗 Plan')
        && ! str_contains((string) $r['text'], 'Plan #'));
});

it('extracts id-like scalar properties and ignores other scalars', function (): void {
    postSlowLivewire('/livewire-ids/update', livewirePayload('participants.index', 'render', [
        'competitionId' => 42,
        'routingPlanUlid' => '01J7XYZ',
        'name' => 'should be ignored',
    ]))->assertOk();

    Http::assertSent(fn ($r): bool => str_contains((string) $r['text'], 'competitionId=42')
        && str_contains((string) $r['text'], 'routingPlanUlid=01J7XYZ')
        && ! str_contains((string) $r['text'], 'should be ignored'));
});

it('caps extracted entities at five', function (): void {
    postSlowLivewire('/livewire-cap/update', livewirePayload('big.form', 'save', [
        'aId' => 1, 'bId' => 2, 'cId' => 3, 'dId' => 4, 'eId' => 5, 'fId' => 6,
    ]))->assertOk();

    Http::assertSent(fn ($r): bool => substr_count((string) $r['text'], 'Id=') === 5);
});

it('ignores malformed model tuples and non-model tuples', function (): void {
    postSlowLivewire('/livewire-malformed/update', livewirePayload('x.y', 'go', [
        'thing' => [null, ['s' => 'mdl']],
        'filters' => [['a' => 1], ['s' => 'arr']],
    ]))->assertOk();

    Http::assertSent(fn ($r): bool => ! str_contains((string) $r['text'], '🔗'));
});

it('handles non-array snapshot data gracefully', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-baddata/update', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $snapshot = json_encode(['memo' => ['name' => 'comp'], 'data' => 'not-an-array']);
    $this->postJson('/livewire-baddata/update', ['_token' => 'x', 'components' => [['snapshot' => $snapshot, 'calls' => [['method' => 'go', 'params' => []]]]]])
        ->assertOk();

    Http::assertSent(fn ($r): bool => str_contains((string) $r['text'], 'Component: <code>comp::go</code>')
        && ! str_contains((string) $r['text'], '🔗'));
});

it('escapes html-special characters in entities and params', function (): void {
    postSlowLivewire('/livewire-escape/update', livewirePayload('x.y', 'run', [
        'fooId' => '<b>',
    ], ['<script>']))->assertOk();

    Http::assertSent(fn ($r): bool => str_contains((string) $r['text'], 'run(&lt;script&gt;)')
        && str_contains((string) $r['text'], 'fooId=&lt;b&gt;')
        && ! str_contains((string) $r['text'], 'run(<script>)')
        && ! str_contains((string) $r['text'], 'fooId=<b>'));
});

it('rate limits livewire alerts by component and method', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-test9/update', function (): string {
        passSlowThreshold();

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
        passSlowThreshold();

        return 'ok';
    });

    $this->postJson('/livewire-test10/update', livewirePayload('counter', 'increment'))
        ->assertOk();
    $this->postJson('/livewire-test10/update', livewirePayload('user-profile', 'save'))
        ->assertOk();

    Http::assertSentCount(2);
});

it('includes the client ip for guest requests', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-client-ip', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->get('/test-client-ip')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '📡 127.0.0.1'));
});

it('includes and truncates a long user-agent', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-client-ua', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->withHeader('User-Agent', str_repeat('A', 200))
        ->get('/test-client-ua')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], str_repeat('A', 79).'…')
        && ! str_contains((string) $request['text'], str_repeat('A', 81)));
});

it('includes the release identifier when configured', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);
    config()->set('telegram-alerts.release', 'a1b2c3d4');

    Route::middleware(SlowResponseMiddleware::class)->get('/test-release', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $this->get('/test-release')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '🏷️ <code>a1b2c3d4</code>'));
});

it('shows the originating url for livewire requests', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-url/update', function (): string {
        passSlowThreshold();

        return 'ok';
    });

    $payload = livewirePayload('public-results-content', '__lazyLoad');
    $snapshot = json_decode((string) $payload['components'][0]['snapshot'], true);
    $snapshot['memo']['path'] = 'competitions/lsvs-63/results';
    $snapshot['memo']['method'] = 'GET';
    $payload['components'][0]['snapshot'] = json_encode($snapshot);

    $this->postJson('/livewire-url/update', $payload)->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '🌐 <code>GET /competitions/lsvs-63/results</code>'));
});
