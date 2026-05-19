# Auto-Monitoring Alerts
Priority: Medium | Status: Not started

## Background

The package currently handles error log notifications and deploy alerts. The next step is project-level health monitoring that auto-registers when the package is installed — no per-project configuration beyond the existing bot token and chat ID.

All features should be opt-in via config with sensible defaults.

## Scope

### In Scope

- Queue failure notifications via `Queue::failing()` listener
- Slow response detection via terminable middleware
- Scheduler heartbeat via scheduled artisan command
- Backup file verification via scheduled artisan command
- Shared `TelegramClient` extracted from duplicated HTTP logic
- Rate limiting on all alert types to prevent notification storms
- Config-driven enable/disable for each feature independently

### Out of Scope

- Alerting to multiple chat IDs or channels (use the single configured `chat_id`)
- Web dashboard or UI for viewing alert history
- Alert acknowledgment / silencing from Telegram (no bot command handling)
- Custom alert formatters or templating — message format is hardcoded
- Queue failure alerts for retry-able jobs that haven't exhausted retries (we alert on every failure, including retries — the rate limiter handles noise)
- Database-backed alert log (alerts are fire-and-forget to Telegram)

## Features

### 0. Shared TelegramClient

Before adding new features, extract the duplicated Telegram API call logic from `TelegramHandler` and `NotifyDeployCommand` into a shared client class.

**Responsibilities:**
- Send a message to the configured chat via Bot API
- Handle the "not configured" no-op (empty token or chat ID)
- Apply the 5-second HTTP timeout
- Wrap calls in `rescue()` to swallow failures silently
- Format the app name/env/URL footer that all messages share

**Why first:** Every new feature needs to send Telegram messages. Without this, we'd duplicate the HTTP call, config reads, and error handling four more times.

### 1. Queue Failure Alerts

Register a `Queue::failing()` listener in the service provider. When a queued job fails, send a Telegram notification with the job class, exception, and queue name.

**Implementation:**
- Listener registered in `TelegramAlertsServiceProvider::boot()`
- Includes: job class, exception message, file:line, queue name, attempt count
- Rate-limited per unique job+exception (same cache-based approach as `TelegramHandler`: 1 alert per unique key per 60s)

**Config:**
```php
'queue_failures' => true, // default: true
```

**Example notification:**
```
⚠️ [MyApp] Queue job failed

`App\Jobs\SendWelcomeEmail`
`Connection refused (smtp:587)`

📄 `app/Jobs/SendWelcomeEmail.php:42`
🔄 Queue: default | Attempt: 3
📍 https://myapp.com (production)
```

### 2. Slow Response Alerts

Terminable middleware that measures request duration and alerts when it exceeds a configurable threshold.

**Implementation:**
- Terminable middleware auto-registered via service provider using `$kernel->pushMiddleware()` (global middleware, no app-side registration needed)
- Measures wall clock time from `LARAVEL_START` to terminate phase
- Sends notification with URL, method, duration, and controller action
- Rate-limited per unique raw path (1 alert per path per 5 minutes)
- Excludes routes matching configurable path patterns (health checks, asset routes)

**Config:**
```php
'slow_response_threshold' => null, // ms, null = disabled. default: null
'slow_response_exclude' => ['/health', '/up'], // paths to ignore
```

**Example notification:**
```
🐌 [MyApp] Slow response (3.2s)

`GET /students/123/observations`
`App\Http\Controllers\ObservationController@index`

⏱️ 3,200ms (threshold: 2,000ms)
📍 https://myapp.com (production)
```

### 3. Scheduler Heartbeat

A scheduled command that sends a periodic ping to confirm the scheduler is running. If the message stops arriving, something is wrong (cron died, server rebooted, queue worker crashed).

**Implementation:**
- Artisan command: `telegram:heartbeat`
- Schedule registration: in `TelegramAlertsServiceProvider::boot()`, use `$this->app->afterResolving(Schedule::class, ...)` to register `->command('telegram:heartbeat')->hourly()` when enabled in config. This is the standard Laravel package approach for registering scheduled commands without touching the app's `routes/console.php`.
- Sends a lightweight status message (uptime, queue size, basic health)
- Skips sending when the app is in maintenance mode (`app()->isDownForMaintenance()`)

**Config:**
```php
'scheduler_heartbeat' => false, // default: false (opt-in)
```

**Example notification:**
```
💚 [MyApp] Heartbeat

⏱️ Uptime: 14d 3h
📊 Queue: 2 pending, 0 failed
💾 SQLite: 42MB
📍 https://myapp.com (production)
🕐 2026-05-19 12:00:00 UTC
```

### 4. Backup Verification

A scheduled command that checks if a backup file exists and was modified within a configurable window. Useful for SQLite file backups or any file-based backup strategy.

**Implementation:**
- Artisan command: `telegram:check-backup`
- Schedule registration: same `afterResolving(Schedule::class, ...)` pattern as heartbeat, `->dailyAt('06:00')`
- Checks file existence, modification time, and minimum file size
- Alerts only on failure (missing, stale, or suspiciously small)
- Glob pattern support for date-stamped backups (e.g., `database.backup-*.sqlite`)
- Glob patterns are restricted to the configured directory — no `..` traversal allowed

**Config:**
```php
'backup_path' => null,          // set to enable. e.g. '/home/forge/myapp/db/database.backup-*.sqlite'
'backup_max_age_hours' => 25,   // alert if newest backup is older than this
'backup_min_size_bytes' => 1024, // alert if backup is smaller than this (corruption check)
```

**Example notification (failure):**
```
🔴 [MyApp] Backup check failed

No backup file modified in the last 25 hours.
Path: /home/forge/myapp/db/database.backup-*.sqlite

📍 https://myapp.com (production)
🕐 2026-05-19 06:00:00 UTC
```

## Config Schema

```php
// config/telegram-alerts.php
return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'chat_id' => env('TELEGRAM_CHAT_ID', ''),

    // Monitoring (all use the same bot_token and chat_id)
    'queue_failures' => true,
    'slow_response_threshold' => null,      // ms, null = disabled
    'slow_response_exclude' => ['/health', '/up'],
    'scheduler_heartbeat' => false,
    'backup_path' => null,
    'backup_max_age_hours' => 25,
    'backup_min_size_bytes' => 1024,
];
```

## Files Affected

### New Files

| File | Purpose |
|------|---------|
| `src/TelegramClient.php` | Shared Telegram Bot API client (extracted from existing code) |
| `src/Listeners/QueueFailureListener.php` | `Queue::failing()` handler |
| `src/Middleware/SlowResponseMiddleware.php` | Terminable middleware for slow response detection |
| `src/Commands/HeartbeatCommand.php` | `telegram:heartbeat` artisan command |
| `src/Commands/CheckBackupCommand.php` | `telegram:check-backup` artisan command |
| `tests/TelegramClientTest.php` | Tests for shared client |
| `tests/QueueFailureListenerTest.php` | Tests for queue failure alerts |
| `tests/SlowResponseMiddlewareTest.php` | Tests for slow response middleware |
| `tests/HeartbeatCommandTest.php` | Tests for heartbeat command |
| `tests/CheckBackupCommandTest.php` | Tests for backup verification command |

### Modified Files

| File | Changes |
|------|---------|
| `src/TelegramHandler.php` | Refactor to use `TelegramClient` instead of inline HTTP calls |
| `src/Commands/NotifyDeployCommand.php` | Refactor to use `TelegramClient` instead of inline HTTP calls |
| `src/TelegramAlertsServiceProvider.php` | Register queue listener, middleware, scheduled commands, bind `TelegramClient` |
| `config/telegram-alerts.php` | Add new config keys for all monitoring features |

## Testing Strategy

The project enforces 100% code coverage and 100% type coverage. All new code must meet these gates.

**General approach:**
- Use `Http::fake()` to mock Telegram API calls and assert message content/structure
- Use `Cache::spy()` or `Cache::fake()` for rate-limiting assertions
- Use `$this->travelTo()` for time-dependent tests (rate limiting, backup age)
- Use Orchestra Testbench's `defineEnvironment()` to set config per test

**Per feature:**
- **TelegramClient** — test send, no-op when unconfigured, rescue on HTTP failure, message truncation
- **Queue failures** — fake a job failure event via `Queue::failing()` callback, assert Telegram message sent, assert rate-limiting deduplicates
- **Slow response** — test middleware with simulated slow request (travel time), assert threshold comparison, assert excluded paths are skipped
- **Heartbeat** — `artisan('telegram:heartbeat')`, assert message content, assert skip during maintenance mode
- **Backup verification** — create temp files with controlled modification times and sizes, test all failure scenarios (missing, stale, too small), test success (no alert sent)

## Technical Considerations

- **Schedule registration** — Laravel packages register scheduled commands via `$this->app->afterResolving(Schedule::class, fn (Schedule $schedule) => ...)` in the service provider's `boot()`. This hooks into the schedule without requiring the app to modify `routes/console.php`.
- **Middleware ordering** — `SlowResponseMiddleware` should be pushed as global middleware via `$kernel->pushMiddleware()` to avoid requiring manual registration. It must be terminable (uses `terminate()` method) so timing includes the full response lifecycle.
- **Maintenance mode** — the heartbeat command should check `app()->isDownForMaintenance()` and skip sending. Backup verification should still run during maintenance (backups don't stop).
- **Glob safety** — the backup path config supports glob patterns. Validate that the resolved path doesn't escape the intended directory via `..` segments. Use `glob()` with `GLOB_NOSORT` for performance.
- **Rate limit cache driver** — rate limiting uses Laravel's cache. If the cache driver is `null` or `array` (non-persistent), every alert will fire. This is acceptable — in production, apps use a persistent driver.
- **Monolog integration preserved** — `TelegramHandler` stays as the Monolog handler for log channel integration. The `TelegramClient` extraction doesn't change the handler's API, only its internals.

## Implementation Order

Each phase is independently deployable and testable:

1. **TelegramClient extraction** — extract shared client, refactor existing `TelegramHandler` and `NotifyDeployCommand` to use it, add tests. No new features yet, just a refactor. All existing behavior preserved.
2. **Queue failures** — listener + tests. Simplest new feature, highest value.
3. **Slow responses** — middleware + tests. Moderate complexity (middleware registration, path exclusion).
4. **Backup verification** — scheduled command + tests. Needs glob pattern matching and file system interaction.
5. **Scheduler heartbeat** — scheduled command + tests. Lowest priority (useful but can be noisy).

## Related

- [Daily Project Digest](daily-digest.md) — also a monitoring feature using scheduled commands and the same Telegram bot. The digest's middleware (request counting) and this spec's slow-response middleware could coexist as separate global middleware. Both features will use the shared `TelegramClient`. The heartbeat in this spec overlaps with the digest's "zero-activity day still sends" behavior — if both are enabled, the digest subsumes the heartbeat's "scheduler is alive" signal.

## Decisions

1. **Queue failure alerts fire on every attempt**, including retries. The rate limiter (1 per unique job+exception per 60s) handles noise. No need to inspect `maxTries`.

2. **Slow response rate-limiting uses the raw request path.** `/students/123` and `/students/456` rate-limit separately. Simplest implementation, and alerts show the exact URL that was slow.

3. **Backup verification is silent on success.** Only failures trigger a Telegram message. The heartbeat command already confirms the scheduler is alive.
