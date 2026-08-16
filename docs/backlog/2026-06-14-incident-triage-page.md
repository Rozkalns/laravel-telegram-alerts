# Incident Triage Page
Priority: Medium | Status: Not started
Dependencies: GlitchTip project + API token (per app); real-client-IP capture behind Cloudflare (per app, see Prerequisites)

## Background

Today an alert fired for rozkalns.xyz (`CorruptComponentPayloadException`). Acting on it meant SSH-ing into the server, remembering the right log paths, reconstructing which IP was responsible, eyeballing its request pattern to decide *bot vs. genuine error*, and — only then — hand-typing a `cscli` ban. Every alert is the same manual ritual, and the commands are easy to get wrong — banning the wrong IP is a real risk (a Cloudflare edge IP would take all sites down).

The alert already knows almost everything needed to triage: the offending client IP, the URL, the environment, and the GlitchTip issue. This feature turns a Telegram alert into a one-click **incident triage page** — a per-incident URL, linked from the alert, that shows copy-paste investigation commands (pre-filled with the IP) and a single guarded ban command. It compresses "alert → SSH → investigate → decide → ban" into "tap link → read → copy → paste."

It pairs with the existing error/scanner alerts: the alert tells you *something happened*; the triage page tells you *what to run to find out, and how to stop it if needed*.

## Prerequisites

These are **per-app** concerns the package cannot fully own, but the feature is unsafe without them. Document them in the README and guard against their absence at runtime.

- **Real client IP behind Cloudflare.** All target sites sit behind Cloudflare. By default `request()->ip()` (and therefore the IP in any alert/command) is the **Cloudflare edge IP**, not the visitor. Apps must configure nginx real-ip (`CF-Connecting-IP`) **and** Laravel trusted proxies so `request()->ip()` returns the true client. Without this, a generated ban command would target Cloudflare and take the site down.
- **Cloudflare-range guard (package-owned safety net).** Regardless of config, the page MUST refuse to emit a ban command for an IP inside Cloudflare's published ranges, and instead warn that real-IP capture is misconfigured. This is the package's defensive backstop for the point above.
- **IP on the GlitchTip event.** For the webhook path (the canonical alert path — see Architecture), the client IP must reach GlitchTip. Either enable Sentry `send_default_pii` or attach the real IP as an event tag/scope in the app. The triage page reads it back via the GlitchTip API.

## Scope

### In Scope

- A signed, per-incident triage route + page, served by the package (Blade view, package-namespaced route).
- Stateless security via Laravel **signed URLs** (HMAC over the params, time-limited expiry). No new storage.
- The page renders: a live GlitchTip issue summary (message, count, first/last seen), an *investigate* command block (pre-filled with the client IP), a *decision guide* (bot vs. genuine), a single guarded `cscli` ban command, and a link back to the GlitchTip issue.
- Client IP added to the alert messages: both the `TelegramHandler` (direct Monolog path) and the `GlitchTipWebhookController` (webhook path).
- A "🔍 Triage" inline button on the Telegram alert linking to the signed page.
- A "successful-probe" highlight on the page: if the offending IP got any `2xx` on a scanner-signature path, surface it prominently (with the SPA-fallback caveat — see Technical Considerations).
- Cloudflare-range refusal guard on the ban command.
- Config-driven enable/disable; opt-in, default off.

### Out of Scope

- **Executing anything from the page.** The page is display-only — copy-paste commands the operator runs in their own SSH session. No "run on server" endpoint exists; that would be a remote-code-execution hole far worse than any alert it triages.
- Storing incident history / a dashboard of past incidents (the signed URL is self-contained).
- Bot command handling / acknowledging or silencing alerts from Telegram.
- Auto-banning. The package never bans; it only *generates the command* for a human to run.
- Multi-channel / multi-chat routing (single configured `chat_id`, as elsewhere).
- Live tailing of server logs on the page (the page emits commands; the operator runs them).

## Architecture

Canonical alert path is the **GlitchTip webhook** (decision: alerts for GlitchTip-enabled sites flow through `GlitchTipWebhookController`, the direct Monolog channel is disabled for those sites to avoid double-alerting).

```
exception ──▶ GlitchTip (captures event incl. real client IP)
                 │ webhook (issue id + title_link)
                 ▼
   GlitchTipWebhookController
     ├─ fetch event via GlitchTip API ──▶ client IP, summary
     ├─ mint signed triage URL (IP + issue id + site, HMAC, 7d expiry)
     └─ send Telegram alert  ──▶ message + "🔍 Triage" button
                 │ operator taps button
                 ▼
   TriageController (signed route, package)
     ├─ validate signature (tamper-proof IP — cannot be swapped)
     ├─ fetch live summary via GlitchTip API (by issue id)
     ├─ guard: IP ∈ Cloudflare ranges? → warn, no ban cmd
     └─ render Blade: summary + investigate cmds + ban cmd + GlitchTip link
```

The signed URL carries the IP as a signature-protected param, so the ban command cannot be weaponized by editing the URL.

## Features

### 0. Real-IP resolver + Cloudflare-range guard

A small `ClientIp` helper: resolve the best-known client IP, and a `Cloudflare::contains(string $ip): bool` check against the published v4/v6 ranges (shipped as a static list, refreshable).

**Why first:** every other part (alert line, ban command, the guard) depends on a trustworthy IP and the ability to recognise a Cloudflare edge IP.

> **Already built (2026-08-16).** `src/Support/Cloudflare.php` now exists, shipped by
> `2026-08-16-alert-signal-quality.md`, with `contains()` covering both v4 and v6 ranges. The ban
> guard is a direct call to it — do not write a second one. `src/Support/IpIdentity.php` from the same
> item also provides PTR/ASN lookup, which the triage page can reuse for its "is it a bot?" section.
> Only the `ClientIp` half of this feature remains.

### 1. Client IP in alert messages

Add an IP line to both alert formatters.

- `TelegramHandler`: read `request()->ip()` when in an HTTP context (guard for console/queue — no request).
- `GlitchTipWebhookController`: read the IP from the fetched GlitchTip event.

**Example (error alert):**
```
🚨 [rozkalns.xyz] Error

`Livewire encountered corrupt data…`

📄 `vendor/livewire/.../Checksum.php:30`
💥 `CorruptComponentPayloadException`
🌐 162.214.66.67   (dedi-1942424.grandfant.com)
📍 https://rozkalns.xyz (production)
🕐 2026-06-14 10:55:41 UTC

         [ 🔍 Triage ]
```

### 2. Signed triage route + controller

- Route registered by the service provider, package-namespaced (e.g. `telegram-alerts/triage`).
- `URL::temporarySignedRoute(...)` with a configurable TTL (default 7 days).
- Controller validates the signature (Laravel's `signed` middleware), reads `ip`, `issue`, `site` params.
- Fetches the live issue summary from the GlitchTip API (token from config).
- Applies the Cloudflare guard.
- Returns the Blade view.

### 3. Triage Blade view

Display-only. Sections:

```
Incident — rozkalns.xyz                      [open in GlitchTip ↗]
CorruptComponentPayloadException · 91× · first 06-12 · last 06-14

Client: 162.214.66.67  (dedi-1942424.grandfant.com)

① INVESTIGATE  (run on the server)
   web-who 162.214.66.67
   web-probes 162.214.66.67
   web-hits                       # did any probe get a 2xx?

② IS IT A BOT?  mostly 4xx/405 on /vendor, /.env, /wp-* → yes, bot
                 normal paths, real UA, your own IP range → genuine

③ BLOCK (only if hostile — temporary, expiring)
   sudo cscli decisions add --ip 162.214.66.67 --duration 720h \
        --reason "scanner: phpunit/.env probes"
```

When the IP is in a Cloudflare range, ③ is replaced by a red warning: *"This is a Cloudflare edge IP — real-client-IP capture is misconfigured. Do NOT ban; fix trusted proxies first."*

### 4. Config

```php
'triage_page'        => false,             // default off; opt-in
'triage_url_ttl'     => 60 * 60 * 24 * 7,  // signed-URL lifetime (seconds)
'glitchtip_api_url'  => env('GLITCHTIP_API_URL'),
'glitchtip_api_token'=> env('GLITCHTIP_API_TOKEN'),
```

## Files Affected

- `src/Http/TriageController.php` — new (signed route handler)
- `resources/views/triage.blade.php` — new
- `src/Support/ClientIp.php`, `src/Support/Cloudflare.php` — new helpers
- `src/Http/GlitchTipWebhookController.php` — fetch IP + summary via API, mint signed URL, add Triage button
- `src/TelegramHandler.php` — add IP line (HTTP-context guard)
- `src/TelegramAlertsServiceProvider.php` — register route + view namespace
- `config/telegram-alerts.php` — new keys
- `routes/api.php` (or a new `routes/web.php`) — signed route
- `tests/` — feature tests for signature validation, CF guard, command rendering, IP-context guard
- `README.md` — prerequisites (trusted proxies, GlitchTip token, PII)

## Technical Considerations

- **Tamper-proofing the ban target.** The IP is a signed param; an attacker who somehow obtained the link cannot rewrite it to ban an arbitrary IP — the signature would fail.
- **2xx false positives (learned 2026-06-14).** A Laravel/SPA catch-all returns `200` + homepage for unknown paths, so "successful probe" detection over-reports. On the page, when surfacing 2xx-on-probe, note that identical response sizes indicate the fallback, not a leak; never present it as a confirmed breach.
- **PII / privacy.** Storing/showing client IPs (and enabling `send_default_pii`) has privacy implications — document it; keep the feature opt-in.
- **GlitchTip API availability.** If the API call fails, the page should still render the static command block (degrade gracefully) using the signed params; only the live summary is skipped.
- **Quality gates.** Must hold the package's 100% code + type coverage, zero phpstan/rector/pint/peck — including the new controller and Blade-adjacent logic (extract logic out of the view to keep it testable).
- **Signed-URL leakage.** The link sits in a private Telegram channel; expiry (default 7d) limits exposure if it leaks.

## Open Questions

1. **Route location** — reuse `routes/api.php` or add a dedicated `routes/web.php`? The page is browser-facing (HTML + signed middleware), which leans `web`.
2. **GlitchTip client IP source** — rely on `send_default_pii` (captures `user.ip_address` automatically) or require apps to set an explicit `client_ip` tag/scope? The tag is more privacy-explicit; PII is less code.
3. **Cloudflare range list** — ship a static list and refresh via a command, or fetch lazily and cache? Static-with-refresh-command matches the package's fire-and-forget style.
4. **Command set portability** — the investigate commands assume the `defense_aliases.sh` helpers (`web-who`, `web-probes`, `web-hits`) exist on the server. Offer a config toggle to emit raw `grep`/`awk` equivalents instead for servers without the aliases?
5. **Direct-Monolog sites** — for sites without GlitchTip, should the Monolog path also mint a triage link (minus the live summary), or is the IP line in the message enough?

## Related

- `auto-monitoring-alerts.md` — shares the `TelegramClient` and rate-limiting patterns; queue/slow-response alerts could also link to a triage page.
- `daily-digest.md` — the digest's per-status counts could deep-link into triage for the day's worst offenders.
