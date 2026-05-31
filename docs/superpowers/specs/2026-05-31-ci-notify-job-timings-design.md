# Cleaner + Richer CI Notifications — Design

**Date:** 2026-05-31
**Status:** Approved (brainstorm)
**Affects:** `rozkalns/laravel-telegram-alerts` package (→ v0.5.0) + the 4 open consumer `telegram-ci.yml` PRs
**Builds on:** `2026-05-31-ci-notify-workflow-run-design.md` (v0.4.0)

## Motivation

Two improvements to the CI notification, shipped together because they touch the same generated workflow and the same webhook contract:

- **A — remove duplication** in the generated `telegram-ci.yml`. The current `notify` step names every value three times (`env:` → `--arg` → jq field) and normalizes status with a shell `if`. It works but reads poorly.
- **C — show what ran and how long.** Include a per-job breakdown (name, result, duration) plus the total run time in the Telegram message.

### Escaping invariant (carried over from v0.4.0, commit `d419bf6`)

Dynamic values must never be interpolated via `${{ }}` directly into the `run:` script — a commit message with quotes/backticks/`$()`/newlines would break the shell or `jq`. All scalar values continue to route through `env:`. Verified empirically: `jq` reading `env.COMMIT_MSG` directly produces valid, correctly-escaped JSON and performs no shell evaluation of the value (a hostile `$(touch …)` message did not execute). This is at least as safe as the prior `--arg "$VAR"` form, since the value never enters the shell at all.

## A — cleanup

Drop the six `--arg` lines and the `if [ "$STATUS" != "success" ]` shell line. `jq` reads scalars from the environment via `env.*` and normalizes status inline with `if/then/else`. Each value is then named twice (`env:` block + `env.X` in jq) instead of three times.

## C — job/timing enrichment

Per-job name/result/duration are **not** in the `workflow_run` event payload. The notify workflow makes **one** GitHub API call to the finished run's jobs endpoint:

```
GET /repos/{repo}/actions/runs/{workflow_run.id}/jobs
```

This is a single deterministic call against the just-completed run — **not** the multi-workflow polling removed in v0.4.0. It requires `permissions: actions: read` and the built-in `GITHUB_TOKEN` (no new secret). `RUN_ID` is `github.event.workflow_run.id` (the **CI** run, not the notify run), so the returned jobs are the CI workflow's jobs and never include the notify job itself.

- Per-job `duration` = `completed_at − started_at` (seconds), computed with `jq`'s `fromdateiso8601`.
- Jobs with null `started_at`/`completed_at` (e.g. skipped) are filtered out — "jobs that executed."
- **Total** `duration` = `workflow_run.updated_at − workflow_run.run_started_at` (wall-clock; correct even with parallel jobs — not a sum of job durations).

## Generated workflow (final form)

```yaml
name: Telegram CI Notification

on:
  workflow_run:
    workflows: ["CI"]
    types: [completed]

permissions:
  actions: read

jobs:
  notify:
    runs-on: ubuntu-latest
    steps:
      - name: Notify Telegram
        env:
          GH_TOKEN: ${{ github.token }}
          APP_URL: ${{ secrets.APP_URL }}
          WEBHOOK_SECRET: ${{ secrets.TELEGRAM_CI_WEBHOOK_SECRET }}
          STATUS: ${{ github.event.workflow_run.conclusion }}
          BRANCH: ${{ github.event.workflow_run.head_branch }}
          SHA: ${{ github.event.workflow_run.head_sha }}
          COMMIT_MSG: ${{ github.event.workflow_run.head_commit.message }}
          ACTOR: ${{ github.event.workflow_run.actor.login }}
          RUN_URL: ${{ github.event.workflow_run.html_url }}
          RUN_ID: ${{ github.event.workflow_run.id }}
          RUN_STARTED: ${{ github.event.workflow_run.run_started_at }}
          RUN_UPDATED: ${{ github.event.workflow_run.updated_at }}
          REPO: ${{ github.repository }}
        run: |
          jobs=$(gh api "repos/$REPO/actions/runs/$RUN_ID/jobs" --paginate \
            --jq '.jobs[] | select(.started_at != null and .completed_at != null) | {name, conclusion, duration: ((.completed_at | fromdateiso8601) - (.started_at | fromdateiso8601))}' \
            | jq -sc '.') || jobs='[]'
          jq -n --argjson jobs "$jobs" '{
            status: (if env.STATUS == "success" then "success" else "failure" end),
            branch: env.BRANCH,
            sha: env.SHA,
            commit: env.COMMIT_MSG,
            actor: env.ACTOR,
            run_url: env.RUN_URL,
            duration: ((env.RUN_UPDATED | fromdateiso8601) - (env.RUN_STARTED | fromdateiso8601)),
            jobs: $jobs
          }' | curl -s -X POST "$APP_URL/api/telegram-alerts/ci" \
            -H "Authorization: Bearer $WEBHOOK_SECRET" \
            -H "Content-Type: application/json" \
            --data-binary @-
```

`gh` is preinstalled on `ubuntu-latest`. `--argjson` injects an already-valid JSON array (no escaping concern). Durations arrive as numbers (seconds); `duration` may be fractional from ISO timestamps — the controller casts to int.

## Webhook payload contract

Existing fields unchanged: `status`, `branch`, `sha`, `commit`, `actor`, `run_url`.

New **optional** fields:

| Field | Type | Meaning |
|-------|------|---------|
| `duration` | number (seconds) | total run wall-clock time |
| `jobs` | array of `{name: string, conclusion: string, duration: number}` | per-job breakdown, executed jobs only |

Backward compatible: a payload without `jobs`/`duration` renders exactly as in v0.4.0.

## `CiWebhookController` changes

`src/Http/CiWebhookController.php` — after the existing `Branch/Actor` line, append (when data present):

- **Jobs line:** each job as `{name} {emoji} {dur}` where emoji is `✅` if `conclusion === 'success'` else `❌`, `dur` via `formatDuration`. Join with ` · `. Omitted entirely when `jobs` is empty/absent.
- **Total line:** `⏱️ total {formatDuration(duration)}`. Omitted when `duration` absent (treat absent as: not provided; `0` is still shown as `0s` only if the field was explicitly sent — use presence check via `$request->has('duration')`).

Reading input (untrusted — validate/normalize types):
- `$request->has('duration')` + `(int) $request->input('duration')`.
- `$jobs = $request->input('jobs', []);` then iterate defensively: each entry coerced — `name` via `(string) ($job['name'] ?? '')`, `conclusion` via `(string) ($job['conclusion'] ?? '')`, `duration` via `(int) ($job['duration'] ?? 0)`. Skip entries with empty `name`. Guard that `jobs` is an array.

New private helper:

```php
private function formatDuration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds.'s';
    }

    $minutes = intdiv($seconds, 60);
    $remSeconds = $seconds % 60;

    if ($minutes < 60) {
        return $remSeconds > 0 ? sprintf('%dm %ds', $minutes, $remSeconds) : sprintf('%dm', $minutes);
    }

    $hours = intdiv($minutes, 60);
    $remMinutes = $minutes % 60;

    return $remMinutes > 0 ? sprintf('%dh %dm', $hours, $remMinutes) : sprintf('%dh', $hours);
}
```

Resulting message:

```
✅ *[MyApp]* CI build passed

`abc1234` fix: handle null route
Branch: `main` · Actor: `dependabot[bot]`

lint ✅ 23s · tests ✅ 1m 47s
⏱️ total 2m 10s

🔗 https://github.com/…/runs/123
```

The `Branch/Actor` line and the jobs/total block are separated from the link by the existing blank-line-before-link logic. Jobs/total block sits directly under `Branch/Actor`.

## `SetupCiWebhookCommand` changes

`buildNotifyWorkflow()` is rewritten to emit the workflow above (adds top-level `permissions: actions: read`, the extra `env:` entries, the `gh api` line, and the `--argjson jobs` jq call; removes the `--arg` lines and the status `if`). `outputWorkflowSnippet()` reuses `buildNotifyWorkflow()` (unchanged call site). No signature/option changes. Escaping of the workflow name (`addcslashes`) is retained.

## Tests

- `CiWebhookControllerTest`:
  - jobs + duration → message contains `lint ✅ 23s · tests ✅ 1m 47s` and `⏱️ total 2m 10s`.
  - a failed job renders `❌`.
  - backward-compat: payload without `jobs`/`duration` → no jobs line, no total line (matches v0.4.0 output).
  - malformed `jobs` (non-array, or entries missing keys) → no crash, degrades gracefully.
  - `formatDuration` boundaries: `45 → "45s"`, `60 → "1m"`, `127 → "2m 7s"`, `3600 → "1h"`, `3780 → "1h 3m"`.
- `SetupCiWebhookCommandTest`:
  - generated workflow contains `permissions:` with `actions: read`, `gh api "repos/$REPO/actions/runs/$RUN_ID/jobs"`, `--argjson jobs`, `jobs: $jobs`, `GH_TOKEN: ${{ github.token }}`.
  - generated workflow no longer contains ` --arg ` (the per-field args are gone).
  - existing assertions (`name: Telegram CI Notification`, `workflows: ["..."]`, no `needs:`, the curl line) still hold.
- All gates stay green: 100% code + type coverage, pint, phpstan, rector, peck.

## Docs / version

- **README** "What You Get" → update the CI passed/failed examples to include the jobs line + total. Section 6 gets a one-line note that the workflow reads per-job timings via `actions: read` + the built-in token.
- **CHANGELOG v0.5.0**:
  - *Added* — per-job breakdown and run timing in CI notifications.
  - *Changed* — generated `telegram-ci.yml` is simpler (`jq` reads `env.*`); webhook payload gains optional `jobs`/`duration`; the generated workflow now requests `permissions: actions: read` and uses the built-in `GITHUB_TOKEN`.
  - *Upgrade notes* — re-run `php artisan telegram:ci-webhook-setup` (or regenerate `telegram-ci.yml`) to get the enriched workflow and the `actions: read` permission. No secret or `.env` changes. Existing v0.4.0 workflows keep working (they just omit the new lines).
- Tag **v0.5.0** on `main` after merge.

## Rollout

Regenerate the enriched `telegram-ci.yml` into the 4 open consumer PRs (`kartites`, `lvva-masters`, `rozkalns.xyz`, `varna`) on their existing `fix/ci-notify-workflow-run` branches — the PRs update in place. Each gains `permissions: actions: read`.

## Out of scope

- Effort B (full CI workflow equalization across repos) — separate brainstorm.
- Reusable-workflow / composite-action extraction — considered and deferred; the inline form stays self-contained.

## Edge cases / risks

- **`actions: read` token scope:** on `workflow_run`, the built-in token can read the same repo's runs/jobs. Set explicitly at workflow level so it works regardless of the repo's default token permissions.
- **Skipped/never-started jobs:** filtered out by the `select(.started_at != null and .completed_at != null)` guard.
- **Fractional durations:** ISO-timestamp subtraction can yield fractions; controller casts to int (floor). Acceptable for display.
- **Empty jobs array** (e.g. API hiccup returns nothing): jobs line omitted; total still shown from the event timestamps.
```
