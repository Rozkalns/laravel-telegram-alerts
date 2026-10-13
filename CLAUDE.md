# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laravel package (`rozkalns/laravel-telegram-alerts`) that sends production errors and deploy notifications to Telegram. It registers a Monolog handler as a Laravel log channel and provides an artisan deploy-notify command.

- **Namespace:** `Rozkalns\TelegramAlerts`
- **PHP:** 8.5+, **Laravel:** 13
- **License:** Beerware

## Commands

```bash
# Run full test suite (lint, type coverage, typos, unit tests, static analysis, rector)
composer test

# Individual checks
composer test:lint       # pint --test
composer test:unit       # pest --coverage --exactly=100
composer test:types      # phpstan
composer test:refactor   # rector --dry-run
composer test:type-coverage  # pest --type-coverage --exactly=100
composer test:typos      # peck

# Auto-fix
composer lint            # pint (fix style)
composer refactor        # rector (apply refactors)
```

## Quality Gates

All of these must pass — they are enforced by `composer test`:

- **100% code coverage** (pest `--exactly=100`)
- **100% type coverage** (pest type-coverage `--exactly=100`)
- **Zero lint violations** (pint)
- **Zero phpstan errors**
- **Zero rector suggestions**
- **Zero typos** (peck)

## Architecture

Everything is auto-registered by `TelegramAlertsServiceProvider`. The package is a messaging API: it sends alerts and owns their format. Domain checks (for example "the database backup has not changed") belong in the apps. `telegram:check-backup` predates that rule and is a candidate to move out.

### Sending

- **`TelegramClient`** (scoped singleton) posts to the Bot API with `parse_mode` HTML. `send()` retries on 429 (honours `Retry-After`, capped at 5 s), 5xx and network errors up to `retry_attempts`, logs a warning when it gives up, and never throws. `sendQueued()` renders the message on the caller side and dispatches `SendTelegramMessageJob`, so the time in the footer is when the alert fired.
- **`TelegramMessage`** is the one message format. `TelegramMessage::make($emoji, $title)->line(...)` renders a `<emoji> <b>[APP_NAME]</b> title` header, the body, and a footer with `📍 URL (env)`, `🏷️ release` (`telegram-alerts.release`, falling back to `sentry.release`) and `🕐 time`. `withoutContext()` drops the footer (CI and GlitchTip alerts carry their own). Escape dynamic values with `e()`. A plain string passed to `send()` is sent unchanged; see `docs/backlog/` for the plan to wrap it by default.
- If `bot_token` or `chat_id` is empty, nothing is sent.

### Alert sources

| Source | Class | Turned on by |
|---|---|---|
| Errors from the log | `TelegramHandler` via the `telegram` log channel (`TelegramLogChannel`). One alert per unique message per 60 s, truncated at 3000 characters. | adding `telegram` to `LOG_STACK` |
| Deploys | `telegram:notify-deploy` (`NotifyDeployCommand`), names the latest commit | the deploy script |
| Failed queue jobs | `QueueFailureListener` on `Queue::failing` | `queue_failures` (on by default) |
| Slow responses | `SlowResponseMiddleware`, pushed onto the HTTP kernel. Names the caller (PTR + ASN through `Support/IpIdentity`), deduplicates per route, and applies a policy to verified crawlers. | `slow_response_threshold` > 0 |
| Scheduler heartbeat | `telegram:heartbeat`, scheduled hourly | `scheduler_heartbeat` |
| Backup check | `telegram:check-backup`, scheduled daily at 06:00 | `backup_path` set |
| CI builds | `POST /api/telegram-alerts/ci` (`CiWebhookController`, bearer token checked by `CiWebhookMiddleware`); `telegram:ci-webhook-setup` creates the secret, stores it in GitHub and `.env` | `ci_webhook` + `ci_webhook_secret` |
| GlitchTip issues | `POST /api/telegram-alerts/glitchtip` (`GlitchTipWebhookController` + `GlitchTipWebhookMiddleware`) | `glitchtip_webhook` + `glitchtip_webhook_secret` |

All options live in `config/telegram-alerts.php`, mostly backed by `TELEGRAM_*` env vars.

### Releases

Versions are 0.x, so a minor release (0.10 → 0.11) is not allowed by an app's `^0.10.0` constraint: bump each app by hand. Every tag gets a GitHub release with its CHANGELOG entry. `composer.lock` is not committed, so CI installs the newest Rector and can fail where a stale local install passes; run `composer update` before tagging.

## Code Style

- All files use `declare(strict_types=1)`
- All classes are `final`
- Uses Laravel 13 features: `#[Signature]`/`#[Description]` attributes, `config()->string()`
- Config follows laravel/pao conventions: `phpstan.neon.dist`, `rector.php`, `pint.json`, `phpunit.xml.dist`
- Dev environment uses `orchestra/testbench` for testing and static analysis
- `peck` (typo checker) requires `aspell` system package
