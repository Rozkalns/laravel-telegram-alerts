# Slow-Response Alerts: Identify The Caller, Shorten The Message, Stop Repeating
Priority: Medium | Status: Not started
Dependencies: real-client-IP capture behind Cloudflare (per app — see the note in 2026-06-14-incident-triage-page.md)

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

**Nothing deduplicates.** The same component, on the same route, breaching the same threshold, sends a
fresh message every time. During the crawl above that meant eight near-identical alerts about one
already-known problem, overnight. Alerts that repeat without adding information are how a channel gets
muted — and a muted channel is worse than no channel, because it still looks like coverage.

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

**This is safe to add because alerting is already asynchronous.** `SendTelegramMessageJob` implements
`ShouldQueue`, so enrichment happens after the response has been sent and costs the request nothing.
It must still be timeboxed and cached — a hanging DNS lookup inside a queue worker is its own outage.

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
- [ ] Truncate the Livewire parameter list in the component signature, or omit parameters entirely for
      `__lazyLoad`, keeping `component::method` which is the part with meaning
- [ ] Confirm a real alert fits in a phone notification without scrolling

### Phase 2 — Identify the caller
- [ ] `IpIdentity` service: PTR + ASN + organisation, timeboxed (~1s total) and cached by IP
- [ ] IPv6 support — the nibble-reversal against `origin6.asn.cymru.com` in `platform-probe.sh` is the
      reference implementation, including the trap that v4-style reversal silently yields garbage
- [ ] Bot classification with forward-confirmed reverse DNS for Googlebot and Bingbot
- [ ] Cloudflare-range detection → report "edge IP, real-client-IP not configured"
- [ ] Degrade gracefully: a failed or slow lookup sends the plain alert, never delays or drops it

### Phase 3 — Stop repeating
- [ ] Dedup key: alert type + component + route; configurable window, default ~15 min
- [ ] Suppressed repeats increment a counter surfaced in the next message ("×8 in 30 min") — a silent
      drop is indistinguishable from a broken alerter

### Phase 4 — Bot policy
- [ ] `slow_response_bot_policy`: `alert` (default) | `digest` | `ignore`
- [ ] Follows the existing config conventions (`slow_response_exclude`, `slow_query_threshold`,
      `n_plus_one_threshold`)

## Files Affected

- `src/Middleware/SlowResponseMiddleware.php` — signature construction (`:130-135`), IP (`:266`),
  user agent (`:269-279`)
- `src/Jobs/SendTelegramMessageJob.php` — `ShouldQueue`; the natural place for enrichment
- New: `src/Support/IpIdentity.php` (or similar) — PTR, ASN, classification, caching
- `config/telegram-alerts.php` — dedup window, bot policy
- README — document the Cloudflare real-client-IP prerequisite alongside the enrichment

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

## Open Questions

- Should dedup collapse by route or by component? The same slow component on two different
  competitions is arguably one problem, not two.
- Is country worth including for human visitors, or does it add noise without changing any decision?

## Related

- `docs/backlog/2026-06-14-incident-triage-page.md` — same real-client-IP prerequisite; a triage page
  and an identified caller are complementary
- `~/code/server/platform-probe.sh` — reference implementation for ASN-over-DNS, including IPv6
- lvva-masters `docs/backlog/2026-08-16-timetable-render-performance.md` — the slowness these alerts
  were correctly reporting
