# Wrap Every Message By Default: `Telegram` Facade, Plain Strings Get Header And Footer
Priority: Medium | Status: Not started

## Background

v0.10.0 (#13) introduced `TelegramMessage`, which renders the `<emoji> <b>[APP_NAME]</b> title` header and the
shared `📍 URL (env)` · `🏷️ release` · `🕐 time` footer. It is opt-in: `TelegramClient::send('Hello world')`
still sends exactly `Hello world`, with no project name and no footer. That is the same failure that started this
work. On 10–12 October the ServerAlerts chat got `Database backup is byte-identical to the previous one — verify
that writes are being captured.` every night at 05:00, and nothing said whether kartites or lvva-masters sent it.
Two apps had hand-built the alert, and neither added the header.

The package should make the right shape the default: any message, however it is sent, says which project and which
deployment it came from. Getting a bare message should take an explicit choice (`sendRaw()`), not happen by accident.
The call should also be short. Today an app injects `TelegramClient`; Roberts expects `Telegram::send(...)`.

A second, smaller issue showed up at the same time: a deploy notice now names the commit **twice**. The body has
`<code>1700f20</code> feat(observations): …` (7 characters, from `git log -1` in `NotifyDeployCommand`). The new
footer adds `🏷️ 082bc1f53a0c` (12 characters, from `sentry.release`, which kartites derives with
`App\Support\Release::fromGit()`). Same commit, two lengths, so it reads like two releases.

## Scope

### In scope
- **`Telegram` facade** (`Rozkalns\TelegramAlerts\Facades\Telegram`) over `TelegramClient`, so
  `Telegram::send()`, `Telegram::sendQueued()` and `Telegram::sendRaw()` work anywhere. Registered as an alias.
- **Plain strings are wrapped.** `send(string)` and `sendQueued(string)` turn the text into a `TelegramMessage`:
  the first line becomes the title, any remaining lines become the body, and the default emoji is `ℹ️`. The text
  stays HTML, as today (no escaping).
- **`sendRaw(string $text, ?array $replyMarkup = null)`**: the escape hatch that sends the text unchanged. The
  current `send()` body moves here.
- **`SendTelegramMessageJob` uses `sendRaw()`.** It carries text that `sendQueued()` already rendered, so going
  through `send()` would add a second header.
- **Deploy notices drop the `🏷️` line**, because the body already names the commit. Add a
  `TelegramMessage::withoutRelease()` (or a context option) and use it in `NotifyDeployCommand`.
- Tests: string wrapping (one line, several lines, empty string), facade calls, `sendRaw()` sends unchanged, the
  queued path renders exactly once, and the deploy notice has no `🏷️` line. Keep 100% line and type coverage.
- README ("Your own alerts" section, deploy example) and CHANGELOG entry for **v0.12.0** (v0.11.0 went to slow-response host stats on 2026-10-27), then a tag and a GitHub
  release (releases are back-filled to v0.10.0, so keep them in step).

### Out of scope (for now)
- A message severity level (info / warning / error) that picks the emoji. Callers pass the emoji themselves.
- Changing CI-build and GlitchTip alerts. They keep `withoutContext()` (a branch build, and an issue with its own
  environment and release).
- Moving `telegram:check-backup` out of the package (see Related).
- Making all SHA lengths the same. Only the deploy notice's duplicate goes away.

## User Story

> As the person who reads the ServerAlerts chat for four apps, I want every message to say which app and which
> deployment sent it, even when an app sends a one-line string, so that I never have to guess where a 05:00 alert
> came from.

## Implementation

### Phase 1 — Raw path and wrapping (package)
- [ ] Move the current `TelegramClient::send()` body to `sendRaw()`.
- [ ] `send(string|TelegramMessage)`: wrap a string with `TelegramMessage::fromText()` (first line = title, rest
      = body, `ℹ️`), then `sendRaw($message->render())`.
- [ ] `sendQueued()` renders before dispatch (as now, but strings are wrapped first).
- [ ] `SendTelegramMessageJob::handle()` calls `sendRaw()`.
- [ ] Tests for the four paths, including "queued text is wrapped exactly once".

### Phase 2 — Facade
- [ ] `src/Facades/Telegram.php` (`getFacadeAccessor()` → `TelegramClient::class`), with `@method` tags for
      phpstan / IDE.
- [ ] Register the alias in `composer.json` `extra.laravel.aliases`.
- [ ] Test that `Telegram::send('x')` reaches the HTTP fake wrapped.

### Phase 3 — Deploy notice without duplicate SHA
- [ ] `TelegramMessage::withoutRelease()`: the footer keeps `📍` and `🕐` and skips `🏷️`.
- [ ] `NotifyDeployCommand` uses it. Test that the deploy text contains the commit once and no `🏷️`.

### Phase 4 — Release and roll-out
- [ ] README + CHANGELOG (v0.12.0, upgrade note: string sends now look different; `sendRaw()` keeps the old
      behaviour).
- [ ] Tag v0.12.0 and publish the GitHub release.
- [ ] Apps: bump to `^0.12.0`. If these are still open, change them in place instead of opening new PRs:
      kartites #469, lvva-masters #172, varna #130, rozkalns.xyz #101. Merge them **one at a time** (overlapping
      in-place deploys corrupt `vendor/` on rozkalns.xyz).
- [ ] Optional in kartites / lvva-masters: replace `TelegramClient` injection with the facade where it reads better.

## Files Affected

- `src/TelegramClient.php`: `sendRaw()`, string wrapping in `send()` / `sendQueued()`
- `src/TelegramMessage.php`: `fromText()`, `withoutRelease()`
- `src/Jobs/SendTelegramMessageJob.php`: `send()` → `sendRaw()`
- `src/Facades/Telegram.php`: new
- `src/Commands/NotifyDeployCommand.php`: `->withoutRelease()`
- `composer.json`: facade alias
- `tests/TelegramClientTest.php`, `tests/TelegramMessageTest.php`, `tests/NotifyDeployCommandTest.php`,
  `tests/SendTelegramMessageJobTest.php`, new `tests/TelegramFacadeTest.php`
- `README.md`, `CHANGELOG.md`

## Technical Considerations

- **Double wrapping is the main risk.** Every path must render exactly once. `sendQueued()` renders on the caller
  side (so the `🕐` footer shows when the alert fired, not when the worker ran), and the job must then send that
  text raw. Add a test that fails if the header appears twice.
- **Behaviour change for string callers, so a minor bump (0.12.0), not a patch.** On 2026-10-13 nothing in the package or the four
  apps sends a plain string (all pass `TelegramMessage`), so nothing visible changes today. Other consumers would
  see the new header. On 0.x, `^0.11.0` does not allow 0.12, so every app needs a constraint bump (Dependabot never
  offers one).
- **The title is HTML.** A first line from user input must already be escaped by the caller, same as today's
  `send()`. Document this; don't escape automatically, or existing `<code>` / `<b>` in strings would break.
- **Empty string:** send just the header with an empty title, or skip? Must not throw. Decide in Open Questions.
- **`sentry.release` is evaluated when the config is cached**, which happens at deploy, so the footer is the deployed
  SHA. Long-running queue workers keep the old value until restarted (Forge restarts them on deploy).
- Keep `send()` signature-compatible (`string|TelegramMessage`, optional `$replyMarkup`), so existing callers
  compile unchanged.

## Open Questions

1. **Default for a plain string: what becomes the title?**
   - Option A (proposed): first line = title, the rest = body. Short alerts read naturally; long text keeps a
     headline.
   - Option B: the whole string is the body, under a fixed title such as `Message`. Predictable, but every
     header reads the same.
   - Decision owner: Roberts
2. **Default emoji:** `ℹ️` (proposed), `💬`, or none (`<b>[App]</b> Hello world`)?
   - Decision owner: Roberts
3. **Empty string:** send header only, or no-op with a log warning?
4. **Facade name:** `Telegram` (short, matches how Roberts wrote it) vs `TelegramAlerts` (avoids clashing with
   another Telegram SDK's facade in the same app)?

## Related

- `done/` — v0.10.0 `TelegramMessage` (#13) is the base this builds on.
- `2026-08-16-alert-signal-quality.md`: same goal of alerts that are clear at a glance.
- Not in this repo: a shared SQLite backup package that owns `db:backup` (copied in kartites and lvva-masters).
  Roberts decided on 2026-10-13 that this package stays a domain-agnostic messaging API, so `telegram:check-backup`
  is a candidate to move out of the package in the future.
