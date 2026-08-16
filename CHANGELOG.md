# Changelog

All notable changes to `rozkalns/laravel-telegram-alerts` will be documented in this file.

## v0.9.0

### Added

- **Slow-response alerts identify the caller.** Each alert resolves the request IP to a hostname and network via reverse DNS and Team Cymru's DNS zones — no API key, no HTTP, no third-party account — and renders it as `📡 66.249.68.38 · Googlebot (AS15169 GOOGLE) · verified`. `verified` means **forward-confirmed reverse DNS**, the method Google and Bing both document: resolve the PTR, resolve that hostname back, require a match. A user-agent claim on its own renders as `claims Googlebot · unverified` and never counts as verified, so spoofing a user agent cannot influence alerting. The user agent is omitted for a verified crawler — Googlebot Smartphone advertises itself as a Nexus 5X, which is precisely the string that misleads a reader at 3am. Configure with `identify_caller` (default `true`) and `identify_caller_budget_ms`.
- **Cloudflare edge detection.** An IP inside Cloudflare's published v4/v6 ranges is reported as `Cloudflare edge IP — real-client-IP not configured` instead of being confidently named as the caller. This is the package's backstop for apps behind Cloudflare that have not configured nginx real-ip and Laravel trusted proxies.
- **Repeat suppression that counts instead of dropping.** Suppressed repeats are carried into the next alert as `🔁 ×9 in 34 min`. A silent drop is indistinguishable from a broken alerter.
- **`slow_response_bot_policy`** — `alert` (default) | `digest` | `ignore`, applied only to *verified* crawlers. Default stays `alert`: a crawler hitting a slow page is still a slow page. `digest` widens the dedup window to `slow_response_bot_digest_window` (default 1 hour) and still carries the count.

### Changed

- **The slow-response dedup window is configurable and now defaults to 15 minutes** (`slow_response_dedup_window`), up from a hardcoded 5. The window is the reason near-identical alerts arrived 7–25 minutes apart overnight.
- **Livewire method arguments no longer dominate the message.** Arguments are dropped for `__`-prefixed internals — `__lazyLoad` carries a base64 component snapshot that ran to hundreds of opaque characters, roughly 80% of the message — and capped at 60 characters for every other method. Measured on a real alert: 965 → 368 characters.
- **The Livewire originating URL keeps its query string** (`…/timetable?search=892&event=DT`), read from the `Referer` and only when its path matches the component snapshot's. Route requests already included it.
- `Cloudflare::contains()` uses Symfony's `IpUtils::checkIp` rather than hand-rolled CIDR matching.

### Performance

- Concurrent slow requests on the same route claim the alert slot atomically, so a burst enriches once rather than once per request.
- Forward-confirmation queries a single address family instead of `DNS_A|DNS_AAAA`, which PHP issues as two sequential queries.
- An ASN's organisation is cached per ASN for a week rather than once per IP — a crawl from one network costs one description lookup, not one per address.

### Known limitation

- **`identify_caller_budget_ms` is not a latency bound.** PHP's `dns_get_record` accepts no timeout, so the budget stops the *next* lookup from starting but cannot interrupt one in flight; a single unanswered query runs to the system resolver's own limit while `terminate()` holds a PHP-FPM worker. Lookups only occur when an alert is actually firing (past threshold, past dedup, cache miss), so the frequency is low — but set `TELEGRAM_IDENTIFY_CALLER=false` if your resolver is unreliable or worker slots are tight.

### Upgrade notes

No breaking changes and no required config. Caller identification is on by default and adds DNS lookups after the response is flushed; disable with `TELEGRAM_IDENTIFY_CALLER=false`. The dedup window widening from 5 to 15 minutes means fewer, denser alerts — each now says how many occurrences it stands for. **If you are behind Cloudflare without real-client-IP configured**, alerts will now say so explicitly rather than reporting the edge IP as the visitor.

## v0.8.0

### Added

- **Client IP and user agent on every slow-response alert**, so anonymous traffic is identifiable — previously only authenticated users were named.
- **Originating URL for Livewire requests**, read from the snapshot's `memo.path`, instead of the opaque `/livewire/update` path.
- **Optional release identifier** (`telegram-alerts.release`, `TELEGRAM_ALERTS_RELEASE`), intended to hold the deployed git SHA so an alert can be tied to a specific deploy.

## v0.7.0

### Added

- **Slow-response alerts now carry actionable context.** Each alert includes the authenticated user (`name · email · #id`), a DB-vs-app time split (`🗄️ DB 11ms · app 7,315ms · 32 queries`) so you can tell at a glance whether the database is the bottleneck, the slowest query when one dominates (`🐢 slowest: …`), and a `⚠️ N+1?` hint when the query count is high. For Livewire requests — which have no meaningful route — the alert shows the called method with its arguments and any bound models (`Component: participants.index::exportStartingLists(42)` / `🔗 Competition #42`) extracted from the request snapshot. Two new config keys tune the thresholds: `slow_query_threshold` (default `100` ms) and `n_plus_one_threshold` (default `100` queries).

### Changed

- Slow-response timing now uses the framework clock (`now()`) instead of `microtime()`. No behavioral change in production; it makes the elapsed time mockable so the test suite no longer sleeps.

### Privacy

- The slowest-query line logs the SQL **template only** (`?` placeholders), never bound values. Livewire extraction is limited to model references and id/ulid-named scalars — arrays, strings, and other component state are never included.

### Upgrade notes

No breaking changes and no required config. The new context appears automatically wherever `slow_response_threshold > 0`. Adjust `slow_query_threshold` / `n_plus_one_threshold` if the defaults are too noisy or too quiet for your app.

## v0.6.0

### Added

- **GlitchTip error tracking via webhook.** A new endpoint `POST /api/telegram-alerts/glitchtip?token=<secret>` receives GlitchTip's Slack-compatible issue alerts and forwards them to Telegram with a tappable link — both the issue title and a `🔍 Open in GlitchTip` inline button open the issue directly. Disabled by default; enable with `TELEGRAM_GLITCHTIP_WEBHOOK=true` and `TELEGRAM_GLITCHTIP_WEBHOOK_SECRET`. The message includes the culprit, environment, release, and issue short ID when present; malformed payloads return `200` without sending. This is additive — the log-channel error alerts are unchanged, so each project can keep `telegram` in `LOG_STACK`, switch to the GlitchTip webhook, or run both.
- `TelegramClient::send()` accepts an optional inline-keyboard reply markup (backward-compatible — existing callers are unaffected).

### Upgrade notes

No breaking changes. Error alerts and all other features keep working as before. To use GlitchTip alerts, see the "GlitchTip Error Tracking" section in the README.

## v0.5.1

### Fixed

- **Telegram messages no longer silently fail when content contains special characters.** Messages are now sent with `parse_mode=HTML` and every dynamic value (commit message, branch, actor, error text, file paths, job names, URLs) is HTML-escaped. Previously a value containing `_`, `*`, `` ` `` or `[` — e.g. a commit message mentioning `workflow_run` — made Telegram reject the whole message with HTTP 400 ("can't parse entities"); the failure was logged and swallowed, so the alert never arrived.

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
