# Changelog

All notable changes to `rozkalns/laravel-telegram-alerts` will be documented in this file.

## v0.2.2

### Fixed

- Generated workflow now detects existing workflow names instead of using unsupported `["*"]` wildcard in `workflow_run`
- Added `--url` flag to specify production URL when running locally (e.g. `--url=https://myapp.com`)
- Skips setting `APP_URL` GitHub secret when it looks like a localhost address, with instructions to re-run with `--url`
- Shows production `.env` instructions after setting GitHub secrets

## v0.2.1

### Fixed

- `telegram:ci-webhook-setup` now shows production `.env` instructions after setting GitHub secrets, so the same secret can be copied to the production server
- Removed the environment guard — the command is designed to run locally (where `gh` is available) and outputs what to add to production

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
