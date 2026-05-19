# Daily Project Digest
Priority: Medium | Status: Not started
Dependencies: Redis (for HyperLogLog and sorted sets)

## Background

A scheduled daily report sent via Telegram summarizing each project's health and activity. Useful for spotting trends (rising error rates, slowing endpoints) without having to SSH into servers. On zero-activity days the report still sends — doubling as a scheduler heartbeat.

## Data Collection

A terminable middleware auto-registered by the service provider. Runs after every response with near-zero overhead — just Redis increments.

### What the middleware collects

All keys are prefixed with the date and auto-expire after 48 hours.

**Request counts by status bucket:**
```php
cache()->increment("telegram_stats:{$date}:2xx");
cache()->increment("telegram_stats:{$date}:4xx");
cache()->increment("telegram_stats:{$date}:5xx");
```

**Unique active users (HyperLogLog — O(1), ~12KB regardless of user count):**
```php
if ($user = auth()->id()) {
    Redis::pfadd("telegram_stats:{$date}:users", [$user]);
}
```

**Top 5 slowest requests (sorted set, auto-trimmed):**
```php
$duration = microtime(true) - LARAVEL_START;
$key = $request->method().' '.$request->path();
Redis::zadd("telegram_stats:{$date}:slowest", $duration * 1000, $key);
Redis::zremrangeByRank("telegram_stats:{$date}:slowest", 0, -6);
```

### What the digest command collects at send time

These don't need the middleware — queried directly when the report runs:

- **DB size** — `filesize()` for SQLite, `pg_database_size()` for Postgres
- **Queue stats** — count from `jobs` and `failed_jobs` tables
- **Cache key count** — `Redis::dbsize()` (approximate)

## Scheduled Command

`telegram:daily-digest` — auto-registered in the schedule, runs daily at a configurable time (default 06:00).

Sends the previous day's stats (runs at 06:00, reports on yesterday).

## Example Notification

```
📊 [MyApp] Daily Report — 2026-05-19

👥 12 active users
📨 347 requests — 339 ✅ 2xx · 5 ⚠️ 4xx · 3 🔴 5xx (0.9% error rate)

🐌 Slowest requests
  1. GET /students/123/observations — 3.2s
  2. POST /surveys/respond/abc — 2.8s
  3. GET /classes/5b — 1.9s
  4. GET /platform/users — 1.4s
  5. GET /students — 1.1s

💾 DB: 38MB
📮 Queue: 23 processed, 0 failed

📍 https://myapp.com
🕐 2026-05-20 06:00:00 UTC
```

### Zero-activity day

```
📊 [MyApp] Daily Report — 2026-05-19

👥 0 active users
📨 0 requests

💾 DB: 38MB
📮 Queue: 0 processed, 0 failed

📍 https://myapp.com
🕐 2026-05-20 06:00:00 UTC
```

## Config

```php
// config/telegram-alerts.php
'daily_digest' => false,           // default: false (opt-in)
'daily_digest_time' => '06:00',    // when to send (server timezone)
'daily_digest_slowest' => 5,       // how many slowest requests to include
```

## Considerations

- **Redis required** — HyperLogLog and sorted sets need Redis. If cache driver isn't Redis, the middleware skips user tracking and top-5 slowest, falls back to simple counters only.
- **Path normalization** — `/students/123` and `/students/456` should be grouped as `/students/{id}`. Use Laravel's route name or route URI pattern instead of raw path.
- **TTL** — all stat keys expire after 48 hours to avoid accumulating stale data.
- **Privacy** — no user-identifying data is stored or sent. Just counts and URL patterns.
- **Middleware exclusions** — skip asset requests, health checks, and other noise. Configurable via `exclude_paths` array.
