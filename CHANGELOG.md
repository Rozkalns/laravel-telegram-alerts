# Changelog

All notable changes to `rozkalns/laravel-telegram-alerts` will be documented in this file.

## v0.5.0

### Added

- **Per-job breakdown and run timing in CI notifications.** Build notifications now list each CI job with its result and duration (e.g. `lint ✅ 23s · tests ✅ 1m 47s`) plus the total run time (`⏱️ total 2m 10s`).

### Changed

- The generated `telegram-ci.yml` is simpler — `jq` reads values from the environment directly (`env.*`) instead of repeating each value as a `--arg`.
- The webhook payload gains two optional fields: `duration` (total run seconds) and `jobs` (array of `{name, conclusion, duration}`). Both are optional and backward-compatible — a workflow that omits them renders as before.
- The generated workflow now requests `permissions: actions: read` and uses the built-in `GITHUB_TOKEN` to read per-job timings via one call to the run's jobs API.
- **Dropped PHP 8.4 support — PHP 8.5+ only.** The minimum is now `^8.5.0`.
- The package's own CI is consolidated into a single `CI` workflow with one job (running `composer test`) instead of separate `tests` (8.4/8.5 matrix) and `linter` workflows.

### Upgrade notes

Re-run `php artisan telegram:ci-webhook-setup` (or regenerate `.github/workflows/telegram-ci.yml`) to get the enriched workflow and the `actions: read` permission. No secret or `.env` changes. Existing v0.4.0 workflows keep working — they simply omit the new job/timing lines.

## v0.4.0

### Changed

- **CI notifications now use a standalone `workflow_run` workflow.** `telegram:ci-webhook-setup` generates `.github/workflows/telegram-ci.yml` instead of injecting a `notify` job into your CI workflow. The previous inline job failed on Dependabot and fork PRs, where GitHub withholds repository secrets from the untrusted run context (empty `APP_URL` produced a malformed `curl` URL and a non-zero exit). The new workflow runs in the trusted default-branch context, so secrets are available for every run. Added `--workflow-name` to override CI workflow-name detection; removed the unused `--generate-workflow` flag.

### Upgrade notes

For each repository already using the injected `notify` job:

1. Delete the `notify:` job from your CI workflow file (e.g. `.github/workflows/ci.yml`).
2. Re-run `php artisan telegram:ci-webhook-setup` (or copy the printed snippet) to add `telegram-ci.yml`.
3. **No secret changes needed** — your existing `APP_URL` and `TELEGRAM_CI_WEBHOOK_SECRET` *Actions* secrets keep working, because `workflow_run` runs in the trusted context.
4. Merge `telegram-ci.yml` to your default branch to activate it (`workflow_run` only fires from the default branch).

## v0.3.0

### Added

- **DB query stats in slow response alerts** — every slow response alert now includes the number of database queries and total query time (e.g. `🗄️ 47 queries (1,840 ms)`). Uses a lightweight `DB::listen()` counter with request-scoped deactivation to prevent listener accumulation in Octane/long-lived workers. The DB stats line is omitted when no queries were executed.
- **Livewire component context** — when a slow request is a Livewire v4 update, the alert shows the component name and method (e.g. `Component: competition-results::loadRankings`) instead of the generic `/livewire-*/update` URL. Rate limiting uses `component::method` as the cache key so different components are tracked independently. Falls back to the standard URL format if the payload can't be parsed.

### Upgrade notes

No breaking changes, no new config keys. DB query stats are included automatically when `slow_response_threshold > 0`. Livewire enrichment activates automatically for Livewire v4 POST requests — no Livewire dependency is required (the payload is parsed as raw JSON).

## v0.2.2

### Fixed

- Generated workflow waits for all workflows to complete before sending a single notification (instead of one per workflow)
- Uses GitHub API to aggregate pass/fail status across all workflows for a commit
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
