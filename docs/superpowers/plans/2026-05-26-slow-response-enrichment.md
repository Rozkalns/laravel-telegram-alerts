# Slow Response Alert Enrichment — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enrich `SlowResponseMiddleware` alerts with DB query stats and Livewire component/method context so slow response alerts are immediately actionable.

**Architecture:** Direct enrichment of the existing middleware. `handle()` registers a `DB::listen()` counter. `terminate()` extracts Livewire context from the JSON payload when applicable, and builds an enriched message. No new files, dependencies, or config keys.

**Tech Stack:** PHP 8.4, Laravel 13, Pest, Orchestra Testbench

**Spec:** `docs/superpowers/specs/2026-05-26-slow-response-enrichment-design.md`

---

### Task 1: DB Query Stats — Tests

**Files:**
- Modify: `tests/SlowResponseMiddlewareTest.php`

- [ ] **Step 1: Write failing test — DB stats appear in alert**

Add this test after the existing tests in `SlowResponseMiddlewareTest.php`:

```php
it('includes db query stats in the alert', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-db-stats', function (): string {
        DB::statement('SELECT 1');
        DB::statement('SELECT 2');
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-db-stats')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '2 queries'));
});
```

Add `use Illuminate\Support\Facades\DB;` to the top imports.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/roberts/code/laravel-telegram-alerts && vendor/bin/pest tests/SlowResponseMiddlewareTest.php --filter='includes db query stats'`

Expected: FAIL — the current message does not contain "2 queries".

- [ ] **Step 3: Write failing test — DB stats omitted when no queries**

```php
it('omits db stats line when no queries are executed', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->get('/test-no-queries', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->get('/test-no-queries')->assertOk();

    Http::assertSent(fn ($request): bool => ! str_contains((string) $request['text'], 'queries'));
});
```

- [ ] **Step 4: Run test to verify it fails or passes**

Run: `cd /Users/roberts/code/laravel-telegram-alerts && vendor/bin/pest tests/SlowResponseMiddlewareTest.php --filter='omits db stats'`

Expected: This test should PASS against the current code (no query line exists yet). That's fine — it's a guard test.

---

### Task 2: DB Query Stats — Implementation

**Files:**
- Modify: `src/Middleware/SlowResponseMiddleware.php`

- [ ] **Step 1: Add DB::listen counter to handle()**

Replace the entire `handle()` method in `src/Middleware/SlowResponseMiddleware.php` with:

```php
/**
 * @param  Closure(Request): Response  $next
 */
public function handle(Request $request, Closure $next): Response
{
    $request->attributes->set('_telegram_start_us', (int) (microtime(true) * 1_000_000));

    $queryCount = 0;
    $queryTimeMs = 0.0;

    DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs): void {
        $queryCount++;
        $queryTimeMs += $query->time;
    });

    $response = $next($request);

    $request->attributes->set('_telegram_query_count', $queryCount);
    $request->attributes->set('_telegram_query_time_ms', $queryTimeMs);

    return $response;
}
```

Add these imports to the top of the file:

```php
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
```

- [ ] **Step 2: Add DB stats line to message in terminate()**

In `terminate()`, after the `$action` variable assignment and before the `$lines` array, add:

```php
$queryCount = $request->attributes->getInt('_telegram_query_count');
$queryTimeMs = (int) round((float) $request->attributes->get('_telegram_query_time_ms', 0.0));
```

Then update the `$lines` array to insert the DB stats line before the timer line:

```php
$lines = [
    sprintf('🐌 *[%s]* Slow response (%ss)', $appName, $seconds),
    '',
    sprintf('`%s %s`', $request->method(), $request->getRequestUri()),
    sprintf('`%s`', $action),
    '',
    ...($queryCount > 0 ? [sprintf('🗄️ %s queries (%s ms)', number_format($queryCount), number_format($queryTimeMs))] : []),
    sprintf('⏱️ %s ms (threshold: %s ms)', number_format($elapsedMs), number_format($thresholdMs)),
    sprintf('📍 %s (%s)', $appUrl, $appEnv),
];
```

- [ ] **Step 3: Run DB stats tests**

Run: `cd /Users/roberts/code/laravel-telegram-alerts && vendor/bin/pest tests/SlowResponseMiddlewareTest.php --filter='db query stats|omits db stats'`

Expected: Both PASS.

- [ ] **Step 4: Run full existing test suite to check for regressions**

Run: `cd /Users/roberts/code/laravel-telegram-alerts && vendor/bin/pest tests/SlowResponseMiddlewareTest.php`

Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Middleware/SlowResponseMiddleware.php tests/SlowResponseMiddlewareTest.php
git commit -m "feat: add DB query stats to slow response alerts"
```

---

### Task 3: Livewire Context — Tests

**Files:**
- Modify: `tests/SlowResponseMiddlewareTest.php`

- [ ] **Step 1: Add helper function to build Livewire payload**

Add this helper at the top of `SlowResponseMiddlewareTest.php` (after the imports, before the `beforeEach`):

```php
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
```

- [ ] **Step 2: Write failing test — Livewire component and method shown**

```php
it('shows livewire component and method for livewire requests', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-abc12345/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-abc12345/update', livewirePayload('competition-results', 'loadRankings'))
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Component: competition-results::loadRankings')
        && ! str_contains((string) $request['text'], '/livewire-abc12345/update'));
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd /Users/roberts/code/laravel-telegram-alerts && vendor/bin/pest tests/SlowResponseMiddlewareTest.php --filter='shows livewire component'`

Expected: FAIL — current code shows the raw URL, not the component name.

- [ ] **Step 4: Write failing test — defaults method to __render when no calls**

```php
it('defaults livewire method to __render when no calls present', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-abc12345/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-abc12345/update', livewirePayload('counter', null))
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Component: counter::__render'));
});
```

- [ ] **Step 5: Write failing test — malformed payload falls back to standard format**

```php
it('falls back to standard format for malformed livewire payload', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-abc12345/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-abc12345/update', ['_token' => 'csrf', 'components' => [['snapshot' => 'not-json']]])
        ->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '/livewire-abc12345/update'));
});
```

- [ ] **Step 6: Write failing test — Livewire rate-limits by component::method**

```php
it('rate limits livewire alerts by component and method', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-abc12345/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-abc12345/update', livewirePayload('counter', 'increment'))
        ->assertOk();
    $this->postJson('/livewire-abc12345/update', livewirePayload('counter', 'increment'))
        ->assertOk();

    Http::assertSentCount(1);
});

it('sends separate livewire alerts for different components', function (): void {
    config()->set('telegram-alerts.slow_response_threshold', 50);

    Route::middleware(SlowResponseMiddleware::class)->post('/livewire-abc12345/update', function (): string {
        usleep(80_000);

        return 'ok';
    });

    $this->postJson('/livewire-abc12345/update', livewirePayload('counter', 'increment'))
        ->assertOk();
    $this->postJson('/livewire-abc12345/update', livewirePayload('user-profile', 'save'))
        ->assertOk();

    Http::assertSentCount(2);
});
```

- [ ] **Step 7: Run all new Livewire tests to verify they fail**

Run: `cd /Users/roberts/code/laravel-telegram-alerts && vendor/bin/pest tests/SlowResponseMiddlewareTest.php --filter='livewire'`

Expected: All new Livewire tests FAIL (except possibly the malformed one which might pass with current behavior since the URL already appears).

---

### Task 4: Livewire Context — Implementation

**Files:**
- Modify: `src/Middleware/SlowResponseMiddleware.php`

- [ ] **Step 1: Add extractLivewireContext() private method**

Add this method to `SlowResponseMiddleware`:

```php
/** @return array{component: string, method: string}|null */
private function extractLivewireContext(Request $request): ?array
{
    if (! str_contains($request->path(), 'livewire') || ! $request->isMethod('POST')) {
        return null;
    }

    $components = $request->input('components');
    if (! is_array($components) || $components === []) {
        return null;
    }

    $first = $components[0];
    if (! is_array($first)) {
        return null;
    }

    $snapshot = json_decode($first['snapshot'] ?? '', true);
    if (! is_array($snapshot)) {
        return null;
    }

    $component = $snapshot['memo']['name'] ?? null;
    if (! is_string($component)) {
        return null;
    }

    $calls = $first['calls'] ?? [];
    $method = is_array($calls) && $calls !== [] ? ($calls[0]['method'] ?? null) : null;

    return [
        'component' => $component,
        'method' => is_string($method) ? $method : '__render',
    ];
}
```

- [ ] **Step 2: Update terminate() to use Livewire context for message and cache key**

Replace the `terminate()` method body from the `$cacheKey` line through the end of the method. The full updated `terminate()` method should be:

```php
public function terminate(Request $request): void
{
    $thresholdMs = config()->integer('telegram-alerts.slow_response_threshold');
    if ($thresholdMs <= 0) {
        return;
    }

    if (! $this->client->isConfigured()) {
        return;
    }

    $excludedPaths = config()->array('telegram-alerts.slow_response_exclude');
    foreach ($excludedPaths as $path) {
        if (is_string($path) && str_starts_with('/'.$request->path(), $path)) {
            return;
        }
    }

    $startUs = $request->attributes->getInt('_telegram_start_us');
    if ($startUs === 0) {
        return;
    }

    $nowUs = (int) (microtime(true) * 1_000_000);
    $elapsedMs = (int) round(($nowUs - $startUs) / 1000);

    if ($elapsedMs < $thresholdMs) {
        return;
    }

    $livewire = $this->extractLivewireContext($request);

    $cacheKeySuffix = $livewire !== null
        ? $livewire['component'].'::'.$livewire['method']
        : $request->method().$request->getRequestUri();
    $cacheKey = 'telegram_slow_'.md5($cacheKeySuffix);

    if (cache()->has($cacheKey)) {
        return;
    }

    cache()->put($cacheKey, true, 300);

    $appName = config()->string('app.name', 'Laravel');
    $appEnv = config()->string('app.env', 'production');
    $appUrl = config()->string('app.url');

    $seconds = number_format($elapsedMs / 1000, 1);

    $queryCount = $request->attributes->getInt('_telegram_query_count');
    $queryTimeMs = (int) round((float) $request->attributes->get('_telegram_query_time_ms', 0.0));

    if ($livewire !== null) {
        $lines = [
            sprintf('🐌 *[%s]* Slow response (%ss)', $appName, $seconds),
            '',
            sprintf('Component: %s::%s', $livewire['component'], $livewire['method']),
            '',
            ...($queryCount > 0 ? [sprintf('🗄️ %s queries (%s ms)', number_format($queryCount), number_format($queryTimeMs))] : []),
            sprintf('⏱️ %s ms (threshold: %s ms)', number_format($elapsedMs), number_format($thresholdMs)),
            sprintf('📍 %s (%s)', $appUrl, $appEnv),
        ];
    } else {
        $action = $request->route()?->getActionName() ?? 'unknown';

        $lines = [
            sprintf('🐌 *[%s]* Slow response (%ss)', $appName, $seconds),
            '',
            sprintf('`%s %s`', $request->method(), $request->getRequestUri()),
            sprintf('`%s`', $action),
            '',
            ...($queryCount > 0 ? [sprintf('🗄️ %s queries (%s ms)', number_format($queryCount), number_format($queryTimeMs))] : []),
            sprintf('⏱️ %s ms (threshold: %s ms)', number_format($elapsedMs), number_format($thresholdMs)),
            sprintf('📍 %s (%s)', $appUrl, $appEnv),
        ];
    }

    $this->client->send(implode("\n", $lines));
}
```

Note the `$action` line changes from `$request->route()->getActionName()` to `$request->route()?->getActionName() ?? 'unknown'` — the nullsafe operator prevents an error when a route is not resolved (e.g., in test scenarios where the request is manually constructed).

- [ ] **Step 3: Run all Livewire tests**

Run: `cd /Users/roberts/code/laravel-telegram-alerts && vendor/bin/pest tests/SlowResponseMiddlewareTest.php --filter='livewire'`

Expected: All PASS.

- [ ] **Step 4: Run full test suite to check for regressions**

Run: `cd /Users/roberts/code/laravel-telegram-alerts && vendor/bin/pest tests/SlowResponseMiddlewareTest.php`

Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Middleware/SlowResponseMiddleware.php tests/SlowResponseMiddlewareTest.php
git commit -m "feat: add Livewire component context to slow response alerts"
```

---

### Task 5: Quality Gates

**Files:** None (run-only)

- [ ] **Step 1: Run full test suite with coverage**

Run: `cd /Users/roberts/code/laravel-telegram-alerts && composer test`

This runs lint, type coverage, typos, unit tests (100% coverage), static analysis, and rector.

Expected: All gates pass. If coverage drops below 100%, the new code paths need additional test coverage.

- [ ] **Step 2: Fix any lint issues**

If pint reports issues:

Run: `cd /Users/roberts/code/laravel-telegram-alerts && composer lint`

Then re-run: `cd /Users/roberts/code/laravel-telegram-alerts && composer test`

- [ ] **Step 3: Fix any static analysis issues**

If phpstan reports issues, fix them in the middleware. Common issues:
- Mixed type from `$request->attributes->get()` — cast explicitly
- Array shape mismatches — add `@var` annotations if needed

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "fix: resolve lint and static analysis issues"
```

Only create this commit if there were actual fixes. Skip if `composer test` passed clean.
