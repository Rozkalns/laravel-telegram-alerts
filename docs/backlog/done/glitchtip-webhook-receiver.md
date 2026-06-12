# GlitchTip Webhook Receiver
Priority: Medium | Status: Done

## Background

GlitchTip (Sentry-API-compatible error tracking, hosted at app.glitchtip.com) is being rolled out across the Forge sites, starting with varna (PR Rozkalns/varna#50). GlitchTip groups errors into issues with stack traces and a web UI — but it has no native Telegram integration, only email and webhooks (Slack-compatible JSON payload).

The package's existing log-channel alerts ping Telegram on every ERROR+ entry, but carry no link to investigate. The main want: **a tappable link in the Telegram message that opens the GlitchTip issue**. GlitchTip's webhook payload provides exactly that (`title_link`).

This feature is **additive**: the log-channel alert path stays. Each site chooses its error-alert source via env — keep `telegram` in `LOG_STACK` (current behavior), or drop it and enable the GlitchTip webhook, or run both during transition. Deploy/queue/heartbeat/backup/CI alerts are unaffected either way.

## Scope

### In scope
- New endpoint `POST /api/telegram-alerts/glitchtip?token=<secret>` — sibling of the CI webhook
- `GlitchTipWebhookMiddleware`: timing-safe (`hash_equals`) check of the `token` **query param** against config secret. Query param, not bearer: GlitchTip webhook config is a bare URL, no custom headers possible. (Secret lands in nginx access logs — accepted standard trade-off for URL-configured webhooks; rotatable.)
- `GlitchTipWebhookController` (final readonly, invokable, matching `CiWebhookController`): gated by config flag → `TelegramClient::isConfigured()` check → parse payload → one Telegram message per attachment, sent via `TelegramClient` directly (sync, like the CI controller)
- Config additions (default off, like `ci_webhook`):
  ```php
  'glitchtip_webhook' => (bool) env('TELEGRAM_GLITCHTIP_WEBHOOK', false),
  'glitchtip_webhook_secret' => env('TELEGRAM_GLITCHTIP_WEBHOOK_SECRET', ''),
  ```
- README section: "Choosing your error alert source" (log channel vs GlitchTip webhook vs both)

### Out of scope
- Multi-provider abstraction (Sentry/other payload parsers) — GlitchTip-only by decision (YAGNI; self-hosted GlitchTip sends the identical payload, so the realistic migration path is already covered)
- Removing or deprecating the log-channel alert path
- Touching deploy/queue/heartbeat/backup/CI alert types
- **Interactive `[✅ Resolve]` button** (Sentry-Slack-app style): possible via Telegram `callback_data` buttons, but requires a bot callback webhook endpoint in the package + a GlitchTip org API token + calling GlitchTip's Sentry-compatible API (`PUT /api/0/issues/{id}/` with `status: resolved`). Separate backlog item if wanted later.
- Event enrichment (browser/user/url tags à la Sentry Slack app) — not in the webhook payload; would require fetching the event from GlitchTip's API. Same future bucket as the Resolve button.

## Payload Reference

GlitchTip sends Slack-compatible JSON (verified against GlitchTip 1.7+ release notes and glitchtip-jira-bridge):

```json
{
  "alias": "GlitchTip",
  "text": "GlitchTip Alert",
  "attachments": [
    {
      "title": "ZeroDivisionError: division by zero",
      "title_link": "https://app.glitchtip.com/<org>/issues/<id>",
      "text": "trigger_error",
      "image_url": null,
      "color": "#e52b50",
      "fields": [
        {"title": "Project", "value": "myproject", "short": true},
        {"title": "Environment", "value": "production", "short": true},
        {"title": "Release", "value": "abc123", "short": false}
      ]
    }
  ],
  "sections": [
    {"activityTitle": "...", "activitySubtitle": "[View Issue PROJ-1](https://...)"}
  ]
}
```

`fields` may be absent/null. Newer GlitchTip versions allow customizing metadata fields/tags included (MR glitchtip-backend!1678) — parser must treat all fields as optional. **Capture a real payload during implementation** (e.g. webhook.site or a temporary log line) to confirm before finalizing the parser.

## Message Format

Telegram HTML, escaped per the v0.5.1 convention (`e()` on all payload values):

```
🐞 <b>[{APP_NAME}]</b> GlitchTip issue

<a href="{title_link}">{title}</a>
📄 {attachment.text}                      ← culprit, when present
📍 {Environment} · {Release}              ← from fields, when present
```

The issue link is the headline requirement — it must be the first tappable element. Additionally, attach a Telegram **inline keyboard with a URL button** (`[🔍 Open in GlitchTip]` → `title_link`) — URL buttons need no callback infrastructure, just `reply_markup.inline_keyboard` in the sendMessage payload (requires extending `TelegramClient::send()` to accept optional reply markup). Include the issue Short ID (e.g. `VARNABEETSKILLS-1`, parseable from `sections[].activitySubtitle`) when present, so issues can be referenced by name.

Malformed/unrecognized payloads: respond `200 OK`, send nothing (don't trigger GlitchTip retries), log a debug line.

## Implementation

- [x] Config keys + route + `GlitchTipWebhookMiddleware` (query-param token, hash_equals)
- [x] Extend `TelegramClient::send()` with optional inline-keyboard reply markup (backwards-compatible)
- [x] `GlitchTipWebhookController`: flag gate, isConfigured gate, attachment parsing, HTML message build + URL button, sync send
- [x] Tests (mirror CI webhook suite): 401 missing/wrong token, 503 flag off, 503 Telegram unconfigured, fixture payloads (full / minimal without fields / multi-attachment), HTML-escape assertions, 200-on-garbage
- [x] README: endpoint setup + "Choosing your error alert source" section
- [x] Release (minor version)

## Files Affected

- `routes/api.php` — register `POST /api/telegram-alerts/glitchtip` with the new middleware (sibling of the CI route)
- `src/Http/GlitchTipWebhookController.php` — new; `final readonly` invokable, mirrors `CiWebhookController`
- `src/Http/GlitchTipWebhookMiddleware.php` — new; query-param token check via `hash_equals` (CI middleware uses `bearerToken()` — diverge here)
- `src/TelegramClient.php` — extend `send(string $text)` with an optional, backwards-compatible inline-keyboard reply-markup argument
- `config/telegram-alerts.php` — add `glitchtip_webhook` + `glitchtip_webhook_secret` keys (default off, like `ci_webhook`)
- `README.md` — endpoint setup + "Choosing your error alert source" section
- `tests/` — new webhook suite mirroring the CI webhook tests (auth, gates, fixture payloads, escaping, 200-on-garbage)

## Rollout (per site, varna first)

- [ ] `composer update rozkalns/laravel-telegram-alerts` + deploy
- [ ] Forge env: add `TELEGRAM_GLITCHTIP_WEBHOOK=true` + `TELEGRAM_GLITCHTIP_WEBHOOK_SECRET=<random>`; remove `telegram` from `LOG_STACK` (this disables the old log-channel error pings — deliberate per-site choice)
- [ ] GlitchTip UI: project → Alerts → Add Alert Recipient → webhook URL `https://<site>/api/telegram-alerts/glitchtip?token=<secret>`
- [ ] Verify: resolve the test issue in GlitchTip, run `php artisan sentry:test` on the server (regression triggers the alert), confirm Telegram message arrives with a working issue link

### kartites (2026-06-12)
- [x] `composer require rozkalns/laravel-telegram-alerts:^0.6.0` (VCS repo, tag pulled from GitHub) — committed to `main`; route `POST /api/telegram-alerts/glitchtip` confirmed registered
- [x] GlitchTip UI: `Čakstes Faili` project → Alerts → webhook recipient `https://kartites.rozkalns.xyz/api/telegram-alerts/glitchtip?token=…` added
- [x] Forge env: `TELEGRAM_GLITCHTIP_WEBHOOK=true` + secret, dropped `telegram` from `LOG_STACK`, deployed + `config:cache`
- [x] Verify: `php artisan sentry:test` → `🐞 [Kartītes] GlitchTip issue` Telegram message arrived with a working `🔍 Open in GlitchTip` button (2026-06-12 15:38 UTC)

## Technical Considerations

- **Topology**: per-site delivery — each GlitchTip project's webhook points at its own site's endpoint. `[APP_NAME]` prefix comes free from the receiving app; no central relay. If a site is hard-down its own alerts can't deliver, but a dead site isn't producing exceptions either — GlitchTip email (and later uptime monitoring) covers that gap.
- **Alert semantics change**: GlitchTip fires on *new issues* and *regressions*, not every event — moving from the log channel means fewer, smarter pings (recurring known errors stay silent while their event count climbs). This is the desired behavior, but worth knowing when "why didn't I get a ping" comes up.
- **Dedup**: GlitchTip's issue grouping replaces the package's own rate-limiting for this alert type; no dedup needed in the controller.

## Related

- Homelab backlog: `~/projects/homelab/docs/backlog/glitchtip-error-tracking.md` (Phase 2/3 of the GlitchTip rollout)
- Existing CI webhook (`src/Http/CiWebhookController.php`, `routes/api.php`) — the structural template

## Completion Notes

Shipped in **v0.6.0**. Implemented as specced — no divergence:

- `TelegramClient::send()` extended with optional inline-keyboard reply markup (backward-compatible).
- `config/telegram-alerts.php`: `glitchtip_webhook` + `glitchtip_webhook_secret` (default off).
- `src/Http/GlitchTipWebhookMiddleware.php`: query-param token, `hash_equals`.
- `src/Http/GlitchTipWebhookController.php` + route: attachment parsing, HTML message, `🔍 Open in GlitchTip` URL button, sync send; malformed → 200.
- Tests mirror the CI webhook suite; full `composer test` green (100% code + type coverage).
- README §7 "GlitchTip Error Tracking" with setup tutorial + "Choosing your error alert source".

The **Rollout (per site, varna first)** checklist above is intentionally left unchecked — that's deployment/config work per site, tracked separately, not part of this package release.
