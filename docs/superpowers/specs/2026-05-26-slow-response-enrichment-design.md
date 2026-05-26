# Slow Response Alert Enrichment — Design Spec

## Problem

The current `SlowResponseMiddleware` alerts are not actionable for Livewire projects. All Livewire requests hit the same endpoint (`/livewire-{hash}/update` → `HandleRequests@handleUpdate`), so the alert doesn't tell you which component or action caused the slowness. There's also no visibility into whether the slowness is DB-bound.

## Solution

Enrich the existing `SlowResponseMiddleware` with two data sources:

1. **DB query stats** — lightweight counter via `DB::listen()` tracking query count and total time
2. **Livewire context** — extract component name and method from the Livewire v4 JSON request payload

## Approach

Direct enrichment of the existing middleware (Approach A). No new files, no new dependencies, no new config keys.

## Data Collection — `handle()`

Register a `DB::listen()` closure at middleware start that increments a counter and sums `QueryExecuted::$time`. After `$next($request)` returns, store both values on request attributes:

```php
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

## Livewire Context Extraction

Private method on the middleware. Livewire v4 POSTs a JSON body with `components[].snapshot` (JSON string containing `memo.name`) and `components[].calls[].method`.

```php
/** @return array{component: string, method: string}|null */
private function extractLivewireContext(Request $request): ?array
```

Detection: `str_contains($request->path(), 'livewire') && $request->isMethod('POST')`.

Parsing:
- Decode `components[0].snapshot` JSON string
- Read `memo.name` for component name (kebab-case, e.g. `competition-results`)
- Read `calls[0].method` for action name (e.g. `loadRankings`)
- Default method to `__render` when no calls are present
- Return `null` on any parse failure — alert falls back to current format

Only the first component is inspected. Livewire batches components but typically one dominates the slow response.

## Message Format

### Livewire request

```
🐌 [LVVA Masters] Slow response (2.3s)

Component: competition-results::loadRankings

🗄️ 47 queries (1,840 ms)
⏱️ 2,329 ms (threshold: 2,000 ms)
📍 https://sacensibas.lvva.lv (production)
```

### Non-Livewire request

```
🐌 [App] Slow response (3.1s)

`GET /api/reports/export`
`ReportController@export`

🗄️ 12 queries (2,800 ms)
⏱️ 3,100 ms (threshold: 2,000 ms)
📍 https://app.example.com (production)
```

DB stats line only appears when query count > 0.

## Rate Limiting

- Non-Livewire: unchanged — cache key based on `method + URI`
- Livewire: cache key based on `component::method` instead of the raw URL (since all Livewire requests share the same endpoint)

## Dependencies

None. The Livewire payload is parsed as raw JSON — no Livewire classes are imported. The package remains usable on non-Livewire projects (Livewire enrichment simply never activates).

## Config

No new config keys. DB stats are automatic when `slow_response_threshold > 0`.

## Files Changed

| File | Change |
|------|--------|
| `src/Middleware/SlowResponseMiddleware.php` | Add `DB::listen` counter in `handle()`, Livewire extraction + enriched message in `terminate()`, new private `extractLivewireContext()` |
| `tests/SlowResponseMiddlewareTest.php` | New tests for DB stats, Livewire extraction, malformed payload fallback, Livewire rate-limiting |

## Test Cases

- DB stats appear in alert when queries are executed during a slow request
- DB stats line omitted when no queries are executed
- Livewire component name and method are extracted and shown
- Livewire request with no calls defaults method to `__render`
- Malformed Livewire payload falls back to standard (URL + action) format
- Rate limiting uses `component::method` for Livewire requests
- Non-Livewire requests are unaffected (existing tests still pass)
