# Changelog

All notable changes to `rozkalns/laravel-telegram-alerts` will be documented in this file.

## v0.2.1

### Fixed

- `telegram:ci-webhook-setup` now warns when run outside production, showing the `APP_URL` that will be pushed to GitHub secrets and asking for confirmation

## v0.2.0

### Added

- **CI pipeline notifications** — new webhook endpoint `POST /api/telegram-alerts/ci` that CI pipelines can call with build results (status, branch, commit, actor, run URL). The package formats and sends a Telegram message using the existing bot ([#6](https://github.com/Rozkalns/laravel-telegram-alerts/issues/6))
- **Setup command** — `php artisan telegram:ci-webhook-setup` generates a secure secret, writes to `.env`, sets GitHub repository secrets via `gh` CLI, and outputs a workflow snippet. Supports `--env` for GitHub environments and `--generate-workflow` for a standalone workflow file
- **Bearer token middleware** — webhook endpoint is protected by a shared secret with timing-safe `hash_equals()` comparison
- Config keys: `ci_webhook` (bool, default `false`) and `ci_webhook_secret` (string)

### Upgrade notes

No breaking changes. The webhook endpoint is disabled by default. To enable, run:

```bash
php artisan telegram:ci-webhook-setup
```

Or manually set `TELEGRAM_CI_WEBHOOK=true` and `TELEGRAM_CI_WEBHOOK_SECRET` in your `.env`.

## v0.1.3

### Fixed

- Slow response alerts now include the full request URI with query string instead of just the path ([#4](https://github.com/Rozkalns/laravel-telegram-alerts/issues/4))
- Rate-limit cache key for slow responses now includes query parameters, so the same path with different query strings triggers separate alerts

### Upgrade notes

No breaking changes. After updating, slow response alerts will show the full URI:

```diff
- GET /articles/show
+ GET /articles/show?n=1&layout=overlay&width=1920
```

Rate limiting now treats each unique path+query combination separately. If you previously relied on a single path being rate-limited regardless of query string, be aware that different query strings will now produce individual alerts.

## v0.1.2

Initial tagged release with error alerts, deploy notifications, queue failure alerts, slow response detection, scheduler heartbeat, and backup verification.
