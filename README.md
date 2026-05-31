# Laravel Telegram Alerts

Send production errors, deploy notifications, and health monitoring alerts to Telegram. Zero config — install the package, add three env vars, done.

## Features

- **Error alerts** — ERROR+ log entries sent to Telegram with exception class, file, and line number
- **Deploy notifications** — artisan command to announce successful deploys
- **Queue failure alerts** — instant notification when a queued job fails
- **Slow response detection** — alerts when requests exceed a configurable duration threshold
- **Scheduler heartbeat** — periodic ping to confirm your scheduler is alive
- **Backup verification** — daily check that backup files exist and are recent
- **CI pipeline notifications** — webhook endpoint for GitHub Actions (or any CI) to report build results
- **Project identification** — `[APP_NAME]` prefix on every message, so one bot handles all your projects
- **Rate limiting** — deduplication on all alert types to avoid notification storms
- **Auto-registration** — everything registers itself via the service provider

## Requirements

- PHP 8.4+
- Laravel 13

## Installation

```bash
composer require rozkalns/laravel-telegram-alerts
```

The package auto-discovers — no manual service provider registration needed.

## Setup

### 1. Create a Telegram Bot (one-time)

1. Open Telegram, message **@BotFather**
2. Send `/newbot` and follow the prompts
3. Save the bot token

One bot works for all your projects.

### 2. Get Your Chat ID

1. Message your bot on Telegram (send anything)
2. Run on any server with the token configured:
   ```bash
   php artisan tinker --execute '
   $token = config("telegram-alerts.bot_token");
   $response = Http::get("https://api.telegram.org/bot{$token}/getUpdates");
   dump($response->json());
   '
   ```
3. Find `"chat": {"id": 123456789}` in the response

For group chats: add the bot to the group, send a message, then check. Group IDs are negative numbers.

### 3. Configure .env

```env
TELEGRAM_BOT_TOKEN=your-bot-token
TELEGRAM_CHAT_ID=your-chat-id
LOG_STACK=single,telegram
```

Clear the config cache if needed:

```bash
php artisan config:clear
```

That's it. Error alerts and queue failure alerts are now active.

### 4. Deploy Notifications (optional)

Add to the end of your deploy script:

```bash
php artisan telegram:notify-deploy
```

### 5. Monitoring Features (optional)

Enable any of these in `.env` or `config/telegram-alerts.php`:

```env
# Slow response alerts — threshold in milliseconds (0 = disabled)
TELEGRAM_SLOW_RESPONSE_THRESHOLD=2000

# Scheduler heartbeat — sends hourly ping
TELEGRAM_SCHEDULER_HEARTBEAT=true

# Backup verification — checks daily at 06:00
TELEGRAM_BACKUP_PATH=/path/to/backups/database.backup-*.sqlite
```

### 6. CI Pipeline Notifications (optional)

Get Telegram alerts when your GitHub Actions CI workflow passes or fails — on any branch or PR, including Dependabot and fork PRs.

**One-command setup:**

```bash
php artisan telegram:ci-webhook-setup
```

This will:
- Generate a secure webhook secret
- Write `TELEGRAM_CI_WEBHOOK=true` and the secret to `.env`
- Set `TELEGRAM_CI_WEBHOOK_SECRET` and `APP_URL` as GitHub repository secrets (requires `gh` CLI)
- Generate `.github/workflows/telegram-ci.yml`, a standalone workflow that triggers on your CI workflow's completion (`workflow_run`) and posts the result to your app

**Options:**

```bash
# Target a specific GitHub environment for the secrets
php artisan telegram:ci-webhook-setup --env=Testing

# Point at a specific CI workflow file for name detection
php artisan telegram:ci-webhook-setup --ci-file=.github/workflows/tests.yml

# Override the CI workflow name the notifier triggers on
php artisan telegram:ci-webhook-setup --workflow-name="CI"
```

> **Why a separate workflow?** `workflow_run` runs in your repository's trusted context, so repository secrets are available even on Dependabot and fork PRs (where an injected job would receive empty secrets and fail). It also begins firing only once `telegram-ci.yml` is on your **default branch** — commit and merge it before expecting notifications.

**Manual setup** (if you prefer not to use the setup command):

```env
TELEGRAM_CI_WEBHOOK=true
TELEGRAM_CI_WEBHOOK_SECRET=your-secret-here
```

Then add a step to your workflow that posts results to `POST /api/telegram-alerts/ci` with the `Authorization: Bearer <secret>` header.

## What You Get

### Error notification

```
🚨 [MyApp] ERROR

`Class "SomeClass" not found`

📄 `app/Http/Controllers/OrderController.php:42`
💥 `Error`

📍 https://myapp.com (production)
🕐 2026-05-19 10:06:55 UTC
```

### Deploy notification

```
✅ [MyApp] deployed

`a1b2c3d feat: add payment processing`

📍 https://myapp.com (production)
🕐 2026-05-19 10:14:20 UTC
```

### Queue failure alert

```
⚠️ [MyApp] Queue job failed

`App\Jobs\SendWelcomeEmail`
`Connection refused (smtp:587)`

📄 `app/Jobs/SendWelcomeEmail.php:42`
🔄 Queue: default | Attempt: 3
📍 https://myapp.com (production)
```

### Slow response alert

```
🐌 [MyApp] Slow response (3.2s)

`GET /students/123/observations?semester=2026-spring`
`App\Http\Controllers\ObservationController@index`

⏱️ 3,200 ms (threshold: 2,000 ms)
📍 https://myapp.com (production)
```

### Scheduler heartbeat

```
💚 [MyApp] Heartbeat

📊 Queue: 2 pending, 0 failed
📍 https://myapp.com (production)
🕐 2026-05-19 12:00:00 UTC
```

### Backup check failure

```
🔴 [MyApp] Backup check failed

No backup file modified in the last 25 hours.
Newest: `database.backup-20260517.sqlite` (30h ago)
Pattern: `/home/forge/myapp/db/database.backup-*.sqlite`

📍 https://myapp.com (production)
🕐 2026-05-19 06:00:00 UTC
```

### CI build passed

```
✅ [MyApp] CI build passed

Branch: `feature/payments`
Commit: `feat: add Stripe integration`
Actor: `Rozkalns`
🔗 https://github.com/org/repo/actions/runs/123
```

### CI build failed

```
❌ [MyApp] CI build failed

Branch: `main`
Commit: `fix: update validation rules`
Actor: `Rozkalns`
🔗 https://github.com/org/repo/actions/runs/456
```

## Configuration

Publish the config to customize:

```bash
php artisan vendor:publish --tag=telegram-alerts-config
```

This creates `config/telegram-alerts.php`:

```php
return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    'log_level' => env('TELEGRAM_LOG_LEVEL', 'error'),

    // Queue failure alerts (enabled by default)
    'queue_failures' => true,

    // Slow response threshold in ms (0 = disabled)
    'slow_response_threshold' => 0,
    'slow_response_exclude' => ['/health', '/up'],

    // Scheduler heartbeat (disabled by default)
    'scheduler_heartbeat' => false,

    // Backup verification (disabled when path is empty)
    'backup_path' => env('TELEGRAM_BACKUP_PATH', ''),
    'backup_max_age_hours' => 25,
    'backup_min_size_bytes' => 1024,

    // CI webhook endpoint (disabled by default)
    'ci_webhook' => false,
    'ci_webhook_secret' => env('TELEGRAM_CI_WEBHOOK_SECRET', ''),
];
```

### Feature defaults

| Feature | Default | Enable with |
|---------|---------|-------------|
| Error alerts | **On** when `telegram` is in `LOG_STACK` | `LOG_STACK=single,telegram` |
| Queue failures | **On** | Set `queue_failures` to `false` to disable |
| Deploy notifications | Manual | `php artisan telegram:notify-deploy` |
| Slow responses | **Off** | Set `slow_response_threshold` to ms value |
| Heartbeat | **Off** | Set `scheduler_heartbeat` to `true` |
| Backup verification | **Off** | Set `backup_path` to a file/glob pattern |
| CI notifications | **Off** | `php artisan telegram:ci-webhook-setup` |

## How It Works

The package registers a `telegram` channel in Laravel's logging system via its service provider. When `LOG_STACK` includes `telegram`, any log entry at the configured level or above is sent to your Telegram chat.

All Telegram sends go through a shared `TelegramClient`:
- Uses Laravel's `Http` facade with a 5-second timeout
- Failed API calls are silently swallowed (via `rescue()`) so they never break your app
- If `bot_token` or `chat_id` is empty, all features silently no-op

Rate limiting uses the cache to deduplicate:
- Error logs: 1 per unique message per 60 seconds
- Queue failures: 1 per unique job+exception per 60 seconds
- Slow responses: 1 per unique path+query per 5 minutes
- If cache is unavailable, rate limiting is skipped and messages send anyway

Scheduled commands (heartbeat, backup verification) are auto-registered via the service provider when enabled in config. They use `callAfterResolving(Schedule::class)` — no changes to your `routes/console.php` needed.

## License

[Beerware](LICENSE.md) — do whatever you want. If we meet and you think this is worth it, buy me a beer.
