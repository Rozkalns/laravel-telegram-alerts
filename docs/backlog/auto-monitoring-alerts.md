# Auto-Monitoring Alerts
Priority: Medium | Status: Not started

## Background

The package currently handles error log notifications and deploy alerts. The next step is project-level health monitoring that auto-registers when the package is installed — no per-project configuration beyond the existing bot token and chat ID.

All features should be opt-in via config with sensible defaults.

## Features

### 1. Queue Failure Alerts

Register a `Queue::failing()` listener in the service provider. When a queued job fails, send a Telegram notification with the job class, exception, and queue name.

**Implementation:**
- Listener registered in `TelegramAlertsServiceProvider::boot()`
- Includes: job class, exception message, file:line, queue name, attempt count
- Rate-limited per unique job+exception (same as error handler)

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
- Terminable middleware auto-registered via service provider
- Measures wall clock time from request start to response
- Sends notification with URL, method, duration, and controller action
- Rate-limited per unique route (1 alert per route per 5 minutes)

**Config:**
```php
'slow_response_threshold' => 2000, // ms, null to disable. default: null (disabled)
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
- Auto-registered in the schedule via `$schedule->command('telegram:heartbeat')->hourly()`
- Sends a lightweight status message (uptime, queue size, basic health)

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
- Auto-registered in the schedule: `->dailyAt('06:00')`
- Checks file existence, modification time, and minimum file size
- Alerts only on failure (missing, stale, or suspiciously small)

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
    'slow_response_threshold' => null, // ms, null = disabled
    'scheduler_heartbeat' => false,
    'backup_path' => null,
    'backup_max_age_hours' => 25,
    'backup_min_size_bytes' => 1024,
];
```

## Implementation Order

1. Queue failures — simplest, highest value, just a listener
2. Slow responses — middleware, moderate complexity
3. Backup verification — scheduled command, needs glob pattern matching
4. Scheduler heartbeat — scheduled command, lowest priority (useful but noisy)
