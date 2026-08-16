# Slow-Response Alerts: Identify The Caller, Shorten The Message, Stop Repeating
Priority: Medium | Status: Done (2026-08-16)
Dependencies: real-client-IP capture behind Cloudflare (per app — see the note in 2026-06-14-incident-triage-page.md)

## Corrections to this spec (2026-08-16, found while implementing)

Two load-bearing claims below were wrong. They are struck through in place; recording them here
because both changed what the work actually was.

1. **"Nothing deduplicates" was false.** `SlowResponseMiddleware` already deduplicated at `:97-101`,
   keyed on `component::method` (Livewire) or `method + URI`, with a **hardcoded 300-second window** —
   and the README documented it ("Slow responses: 1 per unique path+query per 5 minutes"). The
   15–16 August alerts were 7–25 minutes apart, so every one of them cleared a window that was simply
   too short. Phase 3 was therefore *widen, make configurable, and add a count*, not *build dedup*.
2. **The "alerting is already asynchronous" justification was wrong.** `SendTelegramMessageJob` does
   implement `ShouldQueue`, but `SlowResponseMiddleware` never uses it — it calls
   `$this->client->send()` synchronously at `:181`. The conclusion still holds for a different reason:
   the send happens in `terminate()`, which runs after the response is flushed, so the visitor waits
   for nothing. But the PHP-FPM worker *is* held for the duration, which makes the timebox and the
   cache more important than this spec implied, not less.

## Background

On the night of 15–16 August 2026 the LVVA Masters app produced a steady stream of `Slow response`
alerts — 23:49, 00:02, 00:10, 00:35, 00:42, 01:01, 01:09, 01:17 — all for the same page, all looking
identical. The recipient's first reading was "somebody left a browser open refreshing on a timer".

It was **Googlebot**. `66.249.68.37/.38` is Google's crawler range and the user agent
(`Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P)`) is Googlebot Smartphone's signature.
Establishing that took a log query and prior knowledge of Google's IP ranges. The alert already
carries the IP (`SlowResponseMiddleware.php:266`) and the user agent (`:269`) — it has everything
needed to say "this is Googlebot" and doesn't.

Two further problems make the alerts harder to read than they need to be.

**The message is dominated by an unreadable blob.** Lines 130-135 build the component signature by
appending the Livewire method's parameters:

```php
$signature = $livewire['method'];
if ($livewire['params'] !== []) {
    $signature .= '('.implode(', ', $livewire['params']).')';
}
$lines[] = sprintf('Component: <code>%s::%s</code>', e($livewire['component']), e($signature));
```

For a deferred component the method is `__lazyLoad` and its single parameter is a base64-encoded
snapshot. The result is several hundred characters of opaque string — roughly 80% of the Telegram
message — pushing the timing figures, the URL and the release below the fold on a phone. The user
agent is already truncated at 80 characters (`:279`) and the slow SQL at 120 (`:359`); the component
signature is the one unbounded field left.

~~**Nothing deduplicates.**~~ *(Wrong — see Corrections.)* **Dedup exists but its window is too
short.** The same component, on the same route, breaching the same threshold, is suppressed for only
300 seconds. During the crawl above the hits were 7–25 minutes apart, so all eight got through:
near-identical alerts about one already-known problem, overnight. Alerts that repeat without adding
information are how a channel gets muted — and a muted channel is worse than no channel, because it
still looks like coverage.

Worth stating plainly: **those alerts were correct.** The page really was slow. This item is about
signal quality, not about suppressing a true alarm.

## Identifying the caller

The natural instinct is to reuse the "vantage point" block from `platform-probe.sh`
(`~/code/server/`), which reports the probing machine's public IP, geo, edge colo and ASN. That block
works because **the script is the client** — it asks Cloudflare's `/cdn-cgi/trace` about itself. An
inbound visitor cannot be asked anything, so that specific mechanism does not transfer.

Two lookups from the probe script *do* transfer, because they work on any address from our side:

- **ASN via Team Cymru over DNS.** The probe already does this for its own IP against
  `origin.asn.cymru.com` (and `origin6.asn.cymru.com` for IPv6, with nibble-reversal of the expanded
  address). Point it at the request IP instead: `66.249.68.38` → `AS15169 Google`. No API key, no
  HTTP, sub-second.
- **Reverse DNS (PTR).** `66.249.68.38` → `crawl-66-249-68-38.googlebot.com`.

For crawlers, the user agent alone is not evidence — it is trivially spoofed. Google's documented
verification is **forward-confirmed reverse DNS**: resolve the PTR, resolve that hostname back to an
address, and confirm it matches the original. Bing documents the same approach. That is the difference
between "claims to be Googlebot" and "is Googlebot".

Target rendering:

```
🛰️ 66.249.68.38 · Googlebot (AS15169 Google) · verified
```

**This is safe to add because the send already happens after the response is flushed.** ~~`SendTelegramMessageJob`
implements `ShouldQueue`, so enrichment happens after the response has been sent~~ *(wrong — the
middleware sends synchronously; see Corrections)*. The send runs in `terminate()`, so the visitor
waits for nothing — but the worker is held, so enrichment must be timeboxed and cached. A hanging DNS
lookup inside a terminating request is its own outage.

### The Cloudflare caveat

`SlowResponseMiddleware` uses `$request->ip()`. On any app sitting behind Cloudflare without nginx
real-ip and Laravel trusted proxies configured, that is the **Cloudflare edge IP**, not the visitor —
so PTR/ASN enrichment would confidently report "Cloudflare" for every alert. This is the same
prerequisite already documented in `2026-06-14-incident-triage-page.md`, and it is more visible here
because the enrichment names the organisation out loud.

The package should detect an IP inside Cloudflare's published ranges and say so
("edge IP — real-client-IP not configured") rather than reporting it as the caller.

## Scope

### In Scope

- Reverse DNS + ASN/organisation lookup for the request IP, rendered in the alert.
- Bot classification, with forward-confirmed reverse DNS for the major crawlers rather than trusting
  the user agent.
- Cloudflare-range detection, reporting misconfiguration instead of a misleading identity.
- Bounding the component signature so Livewire parameters cannot dominate the message.
- Deduplication of repeat alerts for the same alert type + component + route within a window, with a
  count carried into the next message rather than a silent drop.
- Config for whether crawler-triggered slow responses alert, digest, or stay quiet — defaulting to
  alerting, because the 15 August alerts were true and hiding them by default would have hidden a real
  regression.

### Out of Scope

- Per-route thresholds. A threshold that varies by route hides exactly the regressions the alert
  exists to catch; solve the noise with dedup first and revisit only if that proves insufficient.
- Any change to the error/exception alert path — this item is the slow-response middleware only.
- Persisting alert history. Dedup should work from the cache, in keeping with the package's current
  no-storage posture.

## User Story

> As the person receiving alerts at midnight,
> I want to see at a glance whether a human or a crawler triggered it,
> so that I can distinguish "our users are suffering" from "Google is indexing us"
> without opening a terminal.

## Implementation

### Phase 1 — Bound the message
- [x] Omit parameters entirely for `__lazyLoad`, keeping `component::method`; cap every other
      method's argument list at 60 characters (`SlowResponseMiddleware::describeSignature`)
- [x] Measured rather than eyeballed: a reconstruction of the 16 August alert renders at **368
      characters, down from 965** (the base64 snapshot alone was 512). The phone-screen check itself
      is the recipient's to make — the number is what the package can prove.

### Phase 2 — Identify the caller
- [x] `Support/IpIdentity`: PTR + ASN + organisation, budgeted (default 1000ms) and cached by IP for
      an hour. Cached by IP *alone* — the DNS facts do not vary by user agent, and a crawl is many
      hits from few addresses.
- [x] IPv6 nibble-reversal for `ip6.arpa` and `origin6.asn.cymru.com`. The trap is real: the first
      version of the *test* used v4-style reversal and failed against a correct implementation.
- [x] Forward-confirmed reverse DNS for Googlebot and Bingbot; a user-agent match alone renders as
      `claims X · unverified` and never counts as verified
- [x] `Support/Cloudflare::contains` (v4 + v6 CIDR) → `Cloudflare edge IP — real-client-IP not
      configured`, with no lookups attempted
- [x] Degrades gracefully: `Throwable` from the resolver, a missing client IP, or
      `identify_caller=false` all send the plain alert

### Phase 3 — Stop repeating
- [x] Kept the existing dedup key (Livewire `component::method`, otherwise `method + URI`) and made
      the window configurable: `slow_response_dedup_window`, default 900s — see Open Questions for
      why the key was left alone
- [x] Suppressed repeats increment a counter surfaced in the next message as `🔁 ×9 in 34 min`
      (total occurrences, including the one being reported)
- [x] Identity is resolved *after* the dedup check, so a suppressed repeat costs no DNS

### Phase 4 — Bot policy
- [x] `slow_response_bot_policy`: `alert` (default) | `digest` | `ignore`, applied only to **verified**
      crawlers — spoofing a user agent cannot silence alerts
- [x] `digest` implemented without storage or a scheduler: it widens the dedup window to
      `slow_response_bot_digest_window` (default 3600s) and still carries the count. This is a
      decision the spec left open; a true scheduled digest would have needed the persistence this
      item puts out of scope.
- [x] Follows the existing config conventions

## Outcome

All six quality gates pass: 100% code coverage, 100% type coverage, zero pint/phpstan/rector/peck
findings. 244 tests, up from 170.

New files: `src/Support/{IpIdentity,IpIdentityResult,Resolver,SystemResolver,Cloudflare}.php`.

Two things worth carrying elsewhere:

- **The suite was silently hitting real DNS** the moment enrichment landed (run time went 0.52s →
  1.01s). `TestCase::setUp` now binds a `FakeResolver` for every test, so no test can reach the
  network. `SystemResolver` — the one class that genuinely calls `dns_get_record` — is covered by
  shadowing that function in the package's own namespace (`tests/dns_shim.php`, loaded via
  `autoload-dev.files`). Any future package code doing I/O needs the same treatment to hold 100%
  coverage without flakiness.
- **`Support/Cloudflare` is the helper `2026-06-14-incident-triage-page.md` specifies** under
  "Feature 0 — Real-IP resolver + Cloudflare-range guard". That item can drop its
  `src/Support/Cloudflare.php` line and use this one; the ban-command guard it describes is a direct
  call to `Cloudflare::contains`.

## Files Affected

As built:

- `src/Middleware/SlowResponseMiddleware.php` — signature bounding, dedup window + repeat counter,
  bot policy, caller line
- `src/Support/IpIdentity.php` + `IpIdentityResult.php` — PTR, ASN, classification, caching, budget
- `src/Support/Resolver.php` + `SystemResolver.php` — the DNS seam that keeps tests off the network
- `src/Support/Cloudflare.php` — published v4/v6 ranges + CIDR matching
- `src/TelegramAlertsServiceProvider.php` — binds `Resolver` → `SystemResolver`, scopes `IpIdentity`
- `config/telegram-alerts.php` — dedup window, identify-caller toggle + budget, bot policy + digest window
- `README.md` — caller identification, the Cloudflare prerequisite, repeat suppression, bot policy
- `peck.json` — `ptr`, `asn`, `organisation` added to the dictionary
- `tests/` — `CloudflareTest`, `IpIdentityTest`, `SystemResolverTest`, `FakeResolver`, `DnsShim`,
  `dns_shim.php`, plus 19 new middleware tests

Not touched, contrary to the plan above: `src/Jobs/SendTelegramMessageJob.php`. The middleware does
not route through it (see Corrections), and enrichment sits in `terminate()` instead.

## Technical Considerations

- **Privacy.** Visitor IPs already appear in alerts; adding a hostname and ISP name makes them more
  identifying. For crawlers that is the point. For a real visitor, decide whether the organisation
  name is needed or whether "human" plus country is enough.
- **Cache aggressively.** A crawl produces many hits from a handful of addresses; without a cache that
  is a DNS round-trip per alert for an answer that does not change.
- **Enrichment must never break alerting.** The alert is the product; identity is a nicety. Every
  failure path sends the plain message.
- **Test without network.** The lookups need to be stubbable, or the package's tests become
  DNS-dependent and flaky.

## Open Questions — resolved

- **Should dedup collapse by route or by component?** Left as-is: by `component::method` for Livewire,
  by `method + URI` otherwise. That already collapses the same slow component across two different
  competitions into one alert, which is the behaviour the question was asking for — the key never
  included the route for Livewire requests. Changing it was unnecessary, and widening the window
  turned out to be the whole fix.
- **Is country worth including for human visitors?** No. It would need a geo database or a third
  party, and it changes no decision the recipient makes at 3am — "is this a crawler or a person" does,
  and the ASN/organisation already answers "which network". Not implemented; the organisation name is
  the privacy ceiling.

## Follow-ups (not done here — out of this item's scope)

- **The user-agent line is still the longest field for human visitors** (80 chars, wrapping to two
  lines on a phone). Dropping it for verified crawlers helped the crawler case only. Worth a separate
  look at whether a parsed "iPhone · Safari" beats the raw string.
- **Only Googlebot and Bingbot are classified.** Applebot, Yahoo Slurp, and the AI crawlers all have
  documented PTR suffixes and would be two lines each in `IpIdentity::BOT_HOSTS`.
- **Cloudflare's published ranges are a static list** and will drift. Same refresh question as
  `2026-06-14-incident-triage-page.md` Open Question 3 — now shared between both items.

## Related

- `docs/backlog/2026-06-14-incident-triage-page.md` — same real-client-IP prerequisite; a triage page
  and an identified caller are complementary
- `~/code/server/platform-probe.sh` — reference implementation for ASN-over-DNS, including IPv6
- lvva-masters `docs/backlog/2026-08-16-timetable-render-performance.md` — the slowness these alerts
  were correctly reporting
