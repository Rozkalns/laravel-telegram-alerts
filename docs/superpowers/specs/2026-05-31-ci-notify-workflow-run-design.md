# CI Notify via `workflow_run` — Design

**Date:** 2026-05-31
**Status:** Approved (brainstorm)
**Affects:** `rozkalns/laravel-telegram-alerts` package + 4 consumer repos

## Problem

The `notify` job that `telegram:ci-webhook-setup` injects into a consumer's
CI workflow fails on **Dependabot** (and would fail on **fork**) pull requests.

Observed failure: `Rozkalns/rozkalns.xyz` Actions run, job `notify`, exit code 3.

### Root cause

GitHub deliberately runs workflows triggered by untrusted code (Dependabot,
fork PRs) in a **restricted context**:

- Regular **Actions** secrets are *not* injected — only the separate
  **Dependabot** secret store is available (log line: `Secret source: Dependabot`).
- `GITHUB_TOKEN` is read-only regardless of the `permissions:` block.

This is a security boundary: a Dependabot PR's payload is an unreviewed
third-party dependency bump whose code executes during the build
(`composer install`, `npm i`, build scripts). If real secrets were present,
a malicious update could exfiltrate them.

Because `secrets.APP_URL` resolves to an empty string in that context, the
generated job runs:

```
curl -s -X POST "/api/telegram-alerts/ci" ...
```

A bare path with no scheme/host → curl exits **3 ("URL malformed")** → the
`bash -e` step fails the job.

### Why the package is in this state

The package *used* to generate a standalone `workflow_run` workflow (v0.2.x,
the `--generate-workflow` flag still documented in the README). Commit
`eb85943` replaced it with the inline `notify` job ("single-graph notify") to
get **one** notification per push instead of polling the GitHub API to
aggregate **multiple** separate workflow files. That polling fragility came
specifically from having `lint.yml` and `tests.yml` as *separate* workflows.

Those are now consolidated into a single `ci.yml` per repo, so the original
reason to avoid `workflow_run` no longer applies.

## Decision

Return to a standalone `workflow_run` workflow, scoped to the **single** CI
workflow per repo. `github.event.workflow_run.conclusion` is already the
aggregate of every job in that workflow, so we get one clean notification with
**no** API polling, **no** `needs:`, and **no** extra permissions — and it runs
in the trusted default-branch context, so secrets work on Dependabot/fork PRs.

(Approach A of three considered. Approach B — keep both inline + standalone
modes — doubles maintenance and leaves the default broken. Approach C — guard
the inline job to exit 0 when secrets are absent — stops the failure but
delivers *no* notification on Dependabot PRs, the opposite of the goal.)

## 1. Generated file — `.github/workflows/telegram-ci.yml`

```yaml
name: Telegram CI Notification

on:
  workflow_run:
    workflows: ["CI"]          # from the `name:` of the consumer's CI workflow
    types: [completed]

jobs:
  notify:
    runs-on: ubuntu-latest
    steps:
      - name: Notify Telegram
        env:
          APP_URL: ${{ secrets.APP_URL }}
          WEBHOOK_SECRET: ${{ secrets.TELEGRAM_CI_WEBHOOK_SECRET }}
          STATUS: ${{ github.event.workflow_run.conclusion }}
          BRANCH: ${{ github.event.workflow_run.head_branch }}
          SHA: ${{ github.event.workflow_run.head_sha }}
          COMMIT_MSG: ${{ github.event.workflow_run.head_commit.message }}
          ACTOR: ${{ github.event.workflow_run.actor.login }}
          RUN_URL: ${{ github.event.workflow_run.html_url }}
        run: |
          if [ "$STATUS" != "success" ]; then STATUS="failure"; fi
          jq -n \
            --arg status "$STATUS" \
            --arg branch "$BRANCH" \
            --arg sha "$SHA" \
            --arg commit "$COMMIT_MSG" \
            --arg actor "$ACTOR" \
            --arg run_url "$RUN_URL" \
            '{status: $status, branch: $branch, sha: $sha, commit: $commit, actor: $actor, run_url: $run_url}' | \
          curl -s -X POST "$APP_URL/api/telegram-alerts/ci" \
            -H "Authorization: Bearer $WEBHOOK_SECRET" \
            -H "Content-Type: application/json" \
            --data-binary @-
```

Notes:
- All dynamic values go through `env:` (not direct `${{ }}` interpolation in the
  script) — preserves the fix from commit `d419bf6`: commit messages with quotes
  or backticks break `jq`/the shell otherwise.
- `conclusion` values other than `success` (failure, cancelled, timed_out,
  skipped, neutral, …) normalize to `failure`, matching the controller's
  `status === 'success'` check. Endpoint contract is **unchanged**.

## 2. Command changes — `SetupCiWebhookCommand`

**Remove** (inline-injection path):
- `buildNotifyJob`, `buildFailedCheckLines`, `parseJobNames`,
  `hasExistingNotifyJob`, and the `ci.yml`-mutating part of
  `handleWorkflowInjection`.

**Add / change:**
- `parseWorkflowName(string $content): string` — read the first `^name:` line of
  the detected CI workflow. If absent: warn (GitHub then matches on file path),
  fall back to `"CI"`, and instruct the user to add a `name:` to their CI file.
- Generate `.github/workflows/telegram-ci.yml` from that name. Do **not**
  overwrite an existing `telegram-ci.yml` without confirmation. If no CI file is
  found or the user declines, print the snippet for manual paste.
- Source-CI detection (`detectCiWorkflowFile`) must **exclude `telegram-ci.yml`**
  so a re-run doesn't treat the generated file as a candidate (avoids a false
  "multiple workflow files" warning on the second run).
- Signature: keep `--url`, `--env`, `--ci-file`; **remove** `--generate-workflow`
  (standalone generation is now the only mode); **add** optional
  `--workflow-name=` to override detection in edge cases.

Secret/.env handling (`setGitHubSecret`, `writeEnvValue`, production checklist)
is unchanged — existing **Actions** secrets work as-is with `workflow_run`.

## 3. Docs, migration, changelog

- **README §6** — replace the inline-job description with the standalone-workflow
  flow; drop `--generate-workflow`; note that `telegram-ci.yml` only begins
  firing once it is on the **default branch**.
- **Migration guide** (README + CHANGELOG upgrade notes) for existing consumers:
  1. Delete the `notify:` job from your CI workflow file.
  2. Re-run `php artisan telegram:ci-webhook-setup` (or paste the snippet) to add
     `telegram-ci.yml`.
  3. **No secret changes** — existing `APP_URL` / `TELEGRAM_CI_WEBHOOK_SECRET`
     *Actions* secrets keep working (trusted context).
  4. Merge `telegram-ci.yml` to the default branch to activate it.
- **CHANGELOG — v0.4.0**, "Changed": CI notifications now use a standalone
  `workflow_run` workflow instead of an injected job; fixes notify failures on
  Dependabot/fork PRs (secrets unavailable in untrusted run context). Include the
  migration steps above.

## 4. Consumer-repo rollout (4 PRs)

All four sites name their CI workflow `"CI"`, so each generated file is identical
except where noted. Per repo: remove the inline `notify` job from the CI file,
add `telegram-ci.yml`, open a PR for review.

| Repo | CI file | Workflow name |
|------|---------|---------------|
| `Rozkalns/kartites` | `ci.yml` | CI |
| `Rozkalns/lvva-masters` | `tests.yml` | CI |
| `Rozkalns/rozkalns.xyz` | `ci.yml` | CI |
| `Rozkalns/varna` | `ci.yml` | CI |

`lvva-masters` keeps its CI in `tests.yml`; the `workflows: ["CI"]` filter matches
the workflow **name**, not the filename, so the generated file is the same.

Existing Actions secrets on each repo are already correct; no secret changes.

## 5. Testing & quality gates

- Rework `SetupCiWebhookCommandTest` for the new behavior:
  - generates `telegram-ci.yml` with the detected workflow name,
  - respects the no-overwrite confirmation,
  - missing `name:` → defaults to `"CI"` with a warning,
  - `--workflow-name` override is honored,
  - prints the snippet when no CI file exists,
  - source detection ignores `telegram-ci.yml`.
- `CiWebhookControllerTest` / middleware tests unchanged (contract unchanged).
- All gates stay green: 100% code + type coverage, pint, phpstan, rector, peck.

## Edge cases / risks

- **Activation lag:** `workflow_run` only fires when `telegram-ci.yml` is on the
  default branch. First adoption requires a merge before notifications resume.
  Documented.
- **No `name:` in CI file:** handled via default + warning + `--workflow-name`.
- **Multiple CI workflows a consumer wants aggregated:** out of scope — that was
  the abandoned multi-workflow case. Single-workflow assumption is explicit.
- Not in scope: the Forge production-server git token observation (separate
  infra/security concern; code flows repo → server only).
```

