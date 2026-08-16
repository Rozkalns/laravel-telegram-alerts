# Laravel Telegram Alerts

Send production errors, deploy notifications, and health monitoring alerts to Telegram. Zero config — install the package, add three env vars, done.

## Features

- **Error alerts** — ERROR+ log entries sent to Telegram with exception class, file, and line number
- **Deploy notifications** — artisan command to announce successful deploys
- **Queue failure alerts** — instant notification when a queued job fails
- **Slow response detection** — alerts when requests exceed a configurable duration threshold, enriched with the user, a DB-vs-app time split, the slowest query, Livewire component/model context, and an identified caller (reverse DNS + ASN, with verified crawler detection)
- **Scheduler heartbeat** — periodic ping to confirm your scheduler is alive
- **Backup verification** — daily check that backup files exist and are recent
- **CI pipeline notifications** — webhook endpoint for GitHub Actions (or any CI) to report build results
- **GlitchTip error tracking** — webhook receiver that turns GlitchTip issue alerts into Telegram messages with a tappable link to the issue
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

# How long the same component/route stays deduplicated (seconds, default 900)
TELEGRAM_SLOW_RESPONSE_DEDUP_WINDOW=900

# What to do when a verified crawler triggers a slow response:
# alert (default) | digest | ignore
TELEGRAM_SLOW_RESPONSE_BOT_POLICY=alert

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
- Generate `.github/workflows/telegram-ci.yml`, a standalone workflow that triggers on your CI workflow's completion (`workflow_run`) and posts the result — including a per-job breakdown and run time — to your app (it reads per-job timings via `actions: read` and the built-in `GITHUB_TOKEN`)

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

### 7. GlitchTip Error Tracking (optional)

[GlitchTip](https://glitchtip.com) (Sentry-API-compatible error tracking) groups your exceptions into issues with stack traces and a web UI. This package can receive GlitchTip's webhook alerts and forward them to Telegram — with a tappable link that opens the issue directly. Unlike the log-channel error alerts (which ping on every ERROR+ entry), GlitchTip fires on **new issues and regressions**, so you get fewer, smarter notifications.

**1. Enable the webhook in `.env`:**

```env
TELEGRAM_GLITCHTIP_WEBHOOK=true
TELEGRAM_GLITCHTIP_WEBHOOK_SECRET=your-secret-here
```

Generate a secret with:

```bash
php -r "echo bin2hex(random_bytes(16)).PHP_EOL;"
```

Then clear the config cache (`php artisan config:clear`).

**2. Point GlitchTip at your app:**

In GlitchTip, open your **Project → Alerts → Add An Alert Recipient**, then:

- **Recipient Type:** *General (slack-compatible) Webhook*
- **Webhook URL:** `https://<your-app>/api/telegram-alerts/glitchtip?token=<your-secret>`

Click **Add Recipient**, then **Update** to save the alert. Trigger a test error to confirm the message arrives with a working issue link:

```bash
php artisan tinker --execute 'throw new Exception("glitchtip test");'
```

> **Why a query-string token?** GlitchTip's webhook config is a bare URL with no custom headers, so the secret travels as a `?token=` query param (timing-safe compared on the server). It will appear in your web server's access logs — rotate it if that's a concern.

**Choosing your error alert source:** the log-channel error alerts and the GlitchTip webhook are independent, so each project can pick one:

- Keep `telegram` in `LOG_STACK` — every ERROR+ log entry pings Telegram (no issue link).
- Drop `telegram` from `LOG_STACK` and enable the GlitchTip webhook — fewer, grouped, tappable alerts.
- Run both during a transition.

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

Alerts carry context to help you investigate: the authenticated user, a DB-vs-app time split (so you can tell instantly whether the database is the bottleneck), the slowest query when one dominates, and an N+1 hint when the query count is high.

Route/controller request:

```
🐌 [MyApp] Slow response (4.3s)

👤 Rūdolfs · rudolfs@example.com (#17)
`GET /students/123/observations?semester=2026-spring`
`App\Http\Controllers\ObservationController@index`

🗄️ DB 1,572ms · app 2,732ms · 4 queries
🐢 slowest: `select * from observations where student_id = ?` (1,401 ms)
⏱️ 4,304 ms (threshold: 2,000 ms)
📍 https://myapp.com (production)
```

Livewire request — there's no real route, so the component, the called method with its arguments, and any bound models are shown instead:

```
🐌 [MyApp] Slow response (2.6s)

👤 Rūdolfs · rudolfs@example.com (#17)
Component: participants.index::exportStartingLists(42)
🔗 Competition #42

🗄️ DB 74ms · app 2,538ms · 304 queries ⚠️ N+1?
⏱️ 2,612 ms (threshold: 2,000 ms)
📍 https://myapp.com (production)
```

The slowest-query line logs the SQL template only (with `?` placeholders) — never the bound values. Bound models surface as `Model #key`; id/ulid properties as `key=value`. Arrays and other state are never dumped.

Method arguments are capped at 60 characters, and dropped entirely for Livewire's `__lazyLoad` — its only argument is a base64 component snapshot that can run to hundreds of opaque characters and push everything else off a phone screen.

#### Who called?

The caller line resolves the request IP to a hostname and network, so a 3am alert says who triggered it:

```
📡 66.249.68.38 · Googlebot (AS15169 GOOGLE) · verified
```

- **Reverse DNS (PTR)** for the hostname, and **Team Cymru's DNS zones** for the ASN and organisation. No API key, no HTTP, no third-party account.
- **`verified` means forward-confirmed reverse DNS** — the PTR hostname was resolved back to an address and it matched. A user agent claiming to be Googlebot is trivially spoofed, and renders as `claims Googlebot · unverified` instead.
- The user agent is omitted for a verified crawler, since Googlebot Smartphone advertises itself as a Nexus 5X — exactly the string that misleads a half-awake reader.
- Lookups run in `terminate()`, after the response has been flushed, under a total budget (default 1000ms) and cached per IP for an hour.
- Every failure path sends the plain alert. Enrichment never delays or drops one.

> **Behind Cloudflare:** `request()->ip()` is the Cloudflare edge IP unless you have configured nginx real-ip (`CF-Connecting-IP`) **and** Laravel trusted proxies. The package detects addresses inside Cloudflare's published ranges and reports `Cloudflare edge IP — real-client-IP not configured` rather than confidently naming the wrong caller.

Disable the lookups with `TELEGRAM_IDENTIFY_CALLER=false`.

#### Repeat suppression

The same component (or route) alerts at most once per `slow_response_dedup_window` (default 15 minutes). Suppressed repeats are **counted, not dropped** — the next alert through leads with how many it stands for:

```
🐌 [MyApp] Slow response (2.3s)

🔁 ×8 in 34 min
📡 66.249.68.38 · Googlebot (AS15169 GOOGLE) · verified
…
```

`slow_response_bot_policy` decides what a **verified** crawler does to a slow page:

| Policy | Behaviour |
|--------|-----------|
| `alert` (default) | Alerts normally. A crawler hitting a slow page is still a slow page. |
| `digest` | Widens the dedup window to `slow_response_bot_digest_window` (default 1 hour); the count is still carried. |
| `ignore` | Stays quiet. |

The policy applies only to crawlers proven by forward-confirmed reverse DNS — an unverified claim is treated as an ordinary visitor, so spoofing a user agent cannot silence your alerts.

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
✅ *[MyApp]* CI build passed

`a6aa687` fix: handle null route
Branch: `main` · Actor: `dependabot[bot]`

lint ✅ 23s · tests ✅ 1m 47s
⏱️ total 2m 10s

🔗 https://github.com/org/repo/actions/runs/123
```

### CI build failed

```
❌ *[MyApp]* CI build failed

`a6aa687` wip: broken test
Branch: `feature/x` · Actor: `Rozkalns`

lint ✅ 19s · tests ❌ 41s
⏱️ total 1m 0s

🔗 https://github.com/org/repo/actions/runs/124
```

### GlitchTip issue

```
🐞 *[MyApp]* GlitchTip issue

PROJ-1 ZeroDivisionError: division by zero
📄 trigger_error
📍 production · abc123

[🔍 Open in GlitchTip]
```

The issue title and the **Open in GlitchTip** button both link straight to the issue in GlitchTip.

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
    // How long one component/route stays deduplicated (seconds)
    'slow_response_dedup_window' => 900,
    // Resolve PTR + ASN for the request IP so alerts name the caller
    'identify_caller' => true,
    'identify_caller_budget_ms' => 1000,
    // Verified crawlers: 'alert' | 'digest' | 'ignore'
    'slow_response_bot_policy' => 'alert',
    'slow_response_bot_digest_window' => 3600,
    // Show the slowest query when it took at least this many ms
    'slow_query_threshold' => 100,
    // Flag a possible N+1 when a request runs at least this many queries
    'n_plus_one_threshold' => 100,

    // Scheduler heartbeat (disabled by default)
    'scheduler_heartbeat' => false,

    // Backup verification (disabled when path is empty)
    'backup_path' => env('TELEGRAM_BACKUP_PATH', ''),
    'backup_max_age_hours' => 25,
    'backup_min_size_bytes' => 1024,

    // CI webhook endpoint (disabled by default)
    'ci_webhook' => false,
    'ci_webhook_secret' => env('TELEGRAM_CI_WEBHOOK_SECRET', ''),

    // GlitchTip webhook endpoint (disabled by default)
    'glitchtip_webhook' => false,
    'glitchtip_webhook_secret' => env('TELEGRAM_GLITCHTIP_WEBHOOK_SECRET', ''),
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
| GlitchTip alerts | **Off** | Set `glitchtip_webhook` to `true` + add a webhook in GlitchTip |

## How It Works

The package registers a `telegram` channel in Laravel's logging system via its service provider. When `LOG_STACK` includes `telegram`, any log entry at the configured level or above is sent to your Telegram chat.

All Telegram sends go through a shared `TelegramClient`:
- Uses Laravel's `Http` facade with a 5-second timeout
- Failed API calls are silently swallowed (via `rescue()`) so they never break your app
- If `bot_token` or `chat_id` is empty, all features silently no-op

Rate limiting uses the cache to deduplicate:
- Error logs: 1 per unique message per 60 seconds
- Queue failures: 1 per unique job+exception per 60 seconds
- Slow responses: 1 per unique path+query (or Livewire component+method) per `slow_response_dedup_window`, default 15 minutes — suppressed repeats are counted and carried into the next alert
- If cache is unavailable, rate limiting is skipped and messages send anyway

Scheduled commands (heartbeat, backup verification) are auto-registered via the service provider when enabled in config. They use `callAfterResolving(Schedule::class)` — no changes to your `routes/console.php` needed.

## License

[Beerware](LICENSE.md) — do whatever you want. If we meet and you think this is worth it, buy me a beer.
