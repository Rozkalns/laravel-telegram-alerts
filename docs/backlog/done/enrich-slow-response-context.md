# Enrich Slow-Response Alerts with Actionable Context
Priority: Medium | Status: Done

## Background

The slow-response alert tells you **that** a request was slow, but not **who** triggered it, **what** they were acting on, or **why** it was slow. Real examples from production:

```
🐌 [LVVA Masters] Slow response (7.3s)
Component: participants.index::exportStartingLists
🗄️ 32 queries (11 ms)
⏱️ 7,326 ms (threshold: 2,000 ms)
📍 https://sacensibas.lvva.lv (production)
```

You can see it happened — but you don't know which user, which competition, and the DB stat (`32 queries, 11 ms`) actually contains the biggest clue *and buries it*: the database did 11ms of 7,326ms, so ~99.8% of the time is **app code**, not SQL. For Livewire requests there's no useful route (`participants.index::exportStartingLists` is an anonymous `POST /livewire/update`), so the context you need lives in the **Livewire snapshot and the method-call arguments** — both of which `SlowResponseMiddleware` already parses today and throws away.

This is **additive enrichment** of the existing alert — no new channel, no new dependency, no behavior change to detection/rate-limiting. It does **not** route slow responses through GlitchTip: GlitchTip is an error tracker, slow responses are a performance signal, and the generic GlitchTip issue format would discard the rich, purpose-built slow-response message (see `[[glitchtip-webhook-receiver]]`, considered-and-rejected for this).

## Scope

### In scope (core — tiers 1 & 2)

- **Who** — `👤 User #<id>` from `auth()->id()`, in both message branches. Omitted for guest requests.
- **DB-vs-app split** — replace `🗄️ 32 queries (11 ms)` with `🗄️ DB 11ms · app 7,315ms · 32 queries`, where `app = elapsed − dbTime` (clamped ≥ 0). Instantly distinguishes DB-bound from app-bound. Applies to both branches.
- **Livewire entity context** — extracted from the snapshot already decoded in `extractLivewireContext()`:
  - **Method call arguments** from `calls[0].params` → e.g. `exportStartingLists(42)`.
  - **Bound model references** from `snapshot.data` → `ShortClass#key` (e.g. `Competition#42`, `Plan#7`). Confirmed common in kartites: `public Plan $plan`, `public Student $student`, `public Model $observable`.
  - **id/ulid-like scalar props** from `snapshot.data` whose key matches `/(^id$|Id$|_id$|Ulid$|ulid$)/` → `key=value` (e.g. `routingPlanUlid=01J…`).
  - Rendered as a `🔗 …` line, capped at N items.

### In scope (optional — tier 3, may be deferred to a follow-up)

- **Slowest query** — capture the single slowest `QueryExecuted` in the existing `DB::listen` closure; show `🐢 slowest: <sql template> (1,401 ms)` when DB-bound. **SQL template only (`?` placeholders), never bindings** — avoids PII. Truncated.
- **N+1 hint** — when query count is high (e.g. ≥ 100), flag it (`⚠️ N+1?`), since count ≫ time is the N+1 signature (real sample: `public-participants-content::__lazyLoad`, 304 queries / 74 ms).

### Out of scope

- Routing slow responses through GlitchTip / any error tracker (decided against — see Background).
- True performance tracing / span waterfalls (needs Sentry APM, Clockwork, or Xdebug — a much heavier commitment; this feature *narrows* the cause, it doesn't pinpoint a non-DB app hotspot).
- Dumping full component state, request bodies, arrays, or arbitrary string properties (size + PII).
- Changing slow-response detection, thresholds, exclusions, or rate-limiting.

## Message Format

Telegram HTML, escaped per the v0.5.1 convention (`e()` on all dynamic values).

**Livewire branch:**

```
🐌 <b>[{APP_NAME}]</b> Slow response (7.3s)

👤 User #17                                    ← when authenticated
<code>participants.index → exportStartingLists(42)</code>
🔗 Competition #42                             ← when entities found
🗄️ DB 11ms · app 7,315ms · 32 queries
⏱️ 7,326 ms (threshold: 2,000 ms)
📍 https://sacensibas.lvva.lv (production)
```

**Route/controller branch** (e.g. `GET /`, DB-bound):

```
🐌 <b>[{APP_NAME}]</b> Slow response (4.3s)

👤 User #17                                    ← when authenticated
<code>GET /</code>
<code>Closure</code>
🗄️ DB 1,572ms · app 2,732ms · 4 queries
🐢 slowest: <code>select * from … where … = ?</code> (1,401 ms)   ← tier 3, when DB-bound
⏱️ 4,304 ms (threshold: 2,000 ms)
📍 https://sacensibas.lvva.lv (production)
```

Every enriched line is **optional and defensive** — if extraction finds nothing or the payload is malformed, the line is omitted and the alert still sends (never throw from `terminate()`).

## Implementation

- [x] **Lock down the Livewire v4 snapshot shape.** Done by reading Livewire's own `ModelSynth` in kartites' vendor (authoritative) rather than capturing an HTTP payload: a bound model serializes as `[null, {class, key, s: "mdl"}]`; primitives are stored directly.
- [x] Extend `extractLivewireContext()` to also return `params` (from `calls[0].params`) and `entities` (model refs + id-scalars from `snapshot.data`); added `extractCallParams()`, `extractEntities()`, `modelReference()`, `isIdKey()`.
- [x] Universal lines (both branches): `👤 …` + DB-vs-app split line.
- [x] (tier 3) Capture slowest `QueryExecuted` (sql template, time) in the `DB::listen` closure; `🐢 slowest` line + `⚠️ N+1?` hint.
- [x] Tests (extend `SlowResponseMiddlewareTest`): all branches incl. HTML-escape and no-PII assertions; malformed snapshot → still sends, no enriched lines.
- [x] README: updated the "Slow response alert" examples to the enriched format.
- [x] Release (minor version) — shipped in **v0.7.0**.

## Files Affected

- `src/Middleware/SlowResponseMiddleware.php` — extend `extractLivewireContext()`, add `extractEntities()`, enrich both message branches, capture slowest query in the listener
- `tests/SlowResponseMiddlewareTest.php` — new cases + captured Livewire payload fixture(s)
- `README.md` — update the slow-response example
- `CHANGELOG.md` + git tag — release (see `[[release-workflow]]`)

## Technical Considerations

- **PII boundary.** Only emit: model references (`Class#key`), id/ulid-named scalars, method-call params, and SQL **templates** (placeholders, not bindings). Never dump arrays, collections, request bodies, or arbitrary string properties — both for message size and to avoid leaking participant names/emails.
- **Snapshot shape is version-specific.** Livewire v4 serializes a bound model in `snapshot.data` as a `[value, metadata]` tuple carrying `class` + `key`; confirm the exact shape against the captured kartites payload before finalizing. All extraction degrades gracefully — unknown shapes are skipped.
- **"app" time is non-DB wall time**, not pure CPU — it includes view render, external HTTP, filesystem, queue dispatch, etc. Label it honestly (`app`/`other`). It's still the decisive DB-bound-vs-not signal.
- **Listener memory** stays bounded — track only the current slowest query, not a list. Preserve the existing request-attribute deactivation flag (Octane/long-lived worker safety).
- **100% coverage gate** — every extraction branch (model ref / id-scalar / params / none / guest / DB-bound / N+1) needs a fixture or assertion.

## Open Questions

- **User line:** id-only (`User #17`), or include email/name? Default **id-only** (user explicitly said "some ids would already be a lot"; also the most PII-safe).
- **Guest requests:** omit the user line, or show `guest`? Default **omit** (most public-page slow samples are guests; keeps the message clean).
- **N+1 threshold:** fixed constant (e.g. 100) or a new config key? Default **fixed constant**, revisit if noisy.
- **Tier 3 in this release or a follow-up?** Slowest-query + N+1 are independently shippable; could land core (tiers 1–2) first.
- **Entity cap:** how many `🔗` items before truncating? Proposed **5**.

## Related

- `[[glitchtip-webhook-receiver]]` (done, v0.6.0) — where the "route slow responses via GlitchTip?" idea was considered and rejected; also the source of the "capture a real payload before writing the parser" discipline.
- Existing slow-response feature — DB query stats + Livewire `component::method` context shipped in v0.3.0; this builds directly on that parsing.
- kartites (`~/code/kartites`) — real Livewire-v4 consumer on `^0.6.0`; testbed for capturing the payload fixture and validating extraction.

## Completion Notes

Shipped in **v0.7.0**. Open questions were resolved as follows (some diverging from the tentative defaults, per discussion):

- **User line:** shows **name · email · #id** (not id-only) — the user asked to "pull the user too". Falls back to `User (#id)`, omitted for guests.
- **Thresholds:** both became **config keys** (`slow_query_threshold`, `n_plus_one_threshold`, default 100) rather than fixed constants — needed for deterministic tests (you can't force a slow query in-memory) and useful for tuning.
- **Tier 3** (slowest-query + N+1) shipped in the **same release** as tiers 1–2.
- **Entity cap:** 5, as proposed.
- **Bonus refactor:** slow-response timing moved from `microtime()` to `now()` so the threshold is mockable — the test suite no longer really sleeps (slow-response tests dropped from ~3.5s to ~0.25s).

The "capture a real payload" step was satisfied by reading Livewire's `ModelSynth` source directly, which is more authoritative than a single captured payload.
