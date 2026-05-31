# Cleaner + Richer CI Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Simplify the generated `telegram-ci.yml` notify step and enrich CI notifications with a per-job breakdown and run timing.

**Architecture:** The webhook payload gains two optional fields — `duration` (total run seconds) and `jobs` (array of `{name, conclusion, duration}`). The generated workflow reads scalars from `env.*` in `jq` (dropping the `--arg` duplication), makes one `gh api .../jobs` call (needs `permissions: actions: read`) to collect per-job timings, and posts them. `CiWebhookController` renders a compact one-liner of jobs plus a total line; everything is backward-compatible (missing fields → v0.4.0 output).

**Tech Stack:** PHP 8.4+, Laravel 13, Pest, pint, phpstan, rector, peck. GitHub Actions YAML, `gh` CLI, `jq`.

**Spec:** `docs/superpowers/specs/2026-05-31-ci-notify-job-timings-design.md`
**Branch:** `feat/ci-notify-job-timings` (already created; spec committed there).

---

## File Structure

- `src/Http/CiWebhookController.php` — modify. Add `formatDuration()` + `buildJobsLine()` helpers; insert jobs/total lines into the message.
- `tests/CiWebhookControllerTest.php` — add tests for jobs/total rendering, backward-compat, malformed input, and duration formatting.
- `src/Commands/SetupCiWebhookCommand.php` — modify `buildNotifyWorkflow()` to emit the cleaner + enriched workflow.
- `tests/SetupCiWebhookCommandTest.php` — update generator assertions.
- `README.md`, `CHANGELOG.md` — docs + v0.5.0 entry.
- Consumer repos (external): regenerate `telegram-ci.yml` in the 4 open PR branches.

---

## Task 1: Controller — render jobs + total

**Files:**
- Modify: `src/Http/CiWebhookController.php`
- Test: `tests/CiWebhookControllerTest.php`

- [ ] **Step 1: Add failing tests**

Append these tests to `tests/CiWebhookControllerTest.php`:

```php
it('renders the jobs line and total duration', function (): void {
    ciPost([
        'status' => 'success',
        'branch' => 'main',
        'sha' => 'a6aa687f1234567890abcdef',
        'commit' => 'fix: tests',
        'actor' => 'Rozkalns',
        'run_url' => 'https://github.com/org/repo/actions/runs/123',
        'duration' => 130,
        'jobs' => [
            ['name' => 'lint', 'conclusion' => 'success', 'duration' => 23],
            ['name' => 'tests', 'conclusion' => 'success', 'duration' => 107],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'lint ✅ 23s · tests ✅ 1m 47s')
        && str_contains((string) $request['text'], '⏱️ total 2m 10s'));
});

it('marks a failed job with a cross', function (): void {
    ciPost([
        'status' => 'failure',
        'duration' => 60,
        'jobs' => [
            ['name' => 'lint', 'conclusion' => 'success', 'duration' => 20],
            ['name' => 'tests', 'conclusion' => 'failure', 'duration' => 40],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'lint ✅ 20s · tests ❌ 40s'));
});

it('omits jobs and total lines when not provided', function (): void {
    ciPost([
        'status' => 'success',
        'branch' => 'main',
        'actor' => 'Rozkalns',
    ])->assertOk();

    Http::assertSent(fn ($request): bool => ! str_contains((string) $request['text'], '⏱️')
        && ! str_contains((string) $request['text'], ' · tests'));
});

it('ignores malformed jobs payload', function (): void {
    ciPost([
        'status' => 'success',
        'jobs' => 'not-an-array',
    ])->assertOk();

    Http::assertSent(fn ($request): bool => ! str_contains((string) $request['text'], '✅ 0s'));
});

it('skips job entries without a name', function (): void {
    ciPost([
        'status' => 'success',
        'jobs' => [
            ['conclusion' => 'success', 'duration' => 10],
            ['name' => 'build', 'conclusion' => 'success', 'duration' => 5],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'build ✅ 5s')
        && ! str_contains((string) $request['text'], ' ✅ 10s'));
});

it('formats durations across seconds, minutes, and hours', function (): void {
    ciPost([
        'status' => 'success',
        'duration' => 45,
        'jobs' => [
            ['name' => 'a', 'conclusion' => 'success', 'duration' => 60],
            ['name' => 'b', 'conclusion' => 'success', 'duration' => 127],
            ['name' => 'c', 'conclusion' => 'success', 'duration' => 3600],
            ['name' => 'd', 'conclusion' => 'success', 'duration' => 3780],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'a ✅ 1m')
        && str_contains((string) $request['text'], 'b ✅ 2m 7s')
        && str_contains((string) $request['text'], 'c ✅ 1h')
        && str_contains((string) $request['text'], 'd ✅ 1h 3m')
        && str_contains((string) $request['text'], '⏱️ total 45s'));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter=CiWebhookController`
Expected: FAIL — message has no jobs/total lines yet.

- [ ] **Step 3: Implement the controller changes**

In `src/Http/CiWebhookController.php`, locate the block that builds `$lines` (the `$lines = [ ... 'Branch: ... Actor: ...' ];` array) and the following `if ($runUrl !== '')` block. Between them, insert the jobs/total block. Replace:

```php
        $lines = [
            sprintf('%s *[%s]* CI build %s', $emoji, $appName, $label),
            '',
            $commitLine,
            sprintf('Branch: `%s` · Actor: `%s`', $branch !== '' ? $branch : 'unknown', $actor !== '' ? $actor : 'unknown'),
        ];

        if ($runUrl !== '') {
            $lines[] = '';
            $lines[] = sprintf('🔗 %s', $runUrl);
        }
```

with:

```php
        $lines = [
            sprintf('%s *[%s]* CI build %s', $emoji, $appName, $label),
            '',
            $commitLine,
            sprintf('Branch: `%s` · Actor: `%s`', $branch !== '' ? $branch : 'unknown', $actor !== '' ? $actor : 'unknown'),
        ];

        $jobsLine = $this->buildJobsLine($request->input('jobs'));
        $hasDuration = $request->has('duration');

        if ($jobsLine !== '' || $hasDuration) {
            $lines[] = '';

            if ($jobsLine !== '') {
                $lines[] = $jobsLine;
            }

            if ($hasDuration) {
                $lines[] = sprintf('⏱️ total %s', $this->formatDuration((int) $request->input('duration')));
            }
        }

        if ($runUrl !== '') {
            $lines[] = '';
            $lines[] = sprintf('🔗 %s', $runUrl);
        }
```

Then add these two private methods to the class (after `__invoke`):

```php
    private function buildJobsLine(mixed $jobs): string
    {
        if (! is_array($jobs)) {
            return '';
        }

        $parts = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            $name = (string) ($job['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $conclusion = (string) ($job['conclusion'] ?? '');
            $jobEmoji = $conclusion === 'success' ? '✅' : '❌';
            $parts[] = sprintf('%s %s %s', $name, $jobEmoji, $this->formatDuration((int) ($job['duration'] ?? 0)));
        }

        return implode(' · ', $parts);
    }

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

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter=CiWebhookController`
Expected: PASS (existing + new tests).

Also run `composer test:lint` and `composer test:types`; fix any issues (`composer lint` to auto-fix style).

- [ ] **Step 5: Commit**

```bash
git add src/Http/CiWebhookController.php tests/CiWebhookControllerTest.php
git commit -m "feat: render per-job breakdown and run duration in CI notifications"
```

---

## Task 2: Command — emit cleaner + enriched workflow

**Files:**
- Modify: `src/Commands/SetupCiWebhookCommand.php` (method `buildNotifyWorkflow`)
- Test: `tests/SetupCiWebhookCommandTest.php`

- [ ] **Step 1: Update the generator assertions (failing)**

In `tests/SetupCiWebhookCommandTest.php`, find the test `it('generates telegram-ci.yml from the detected workflow name', ...)`. Replace its `expect($content)` chain with:

```php
    $content = file_get_contents(base_path('.github/workflows/telegram-ci.yml'));
    expect($content)->toStartWith('name: Telegram CI Notification')
        ->and($content)->toContain('workflow_run:')
        ->and($content)->toContain('workflows: ["CI"]')
        ->and($content)->toContain('types: [completed]')
        ->and($content)->toContain('permissions:')
        ->and($content)->toContain('actions: read')
        ->and($content)->toContain('GH_TOKEN: ${{ github.token }}')
        ->and($content)->toContain('github.event.workflow_run.conclusion')
        ->and($content)->toContain('COMMIT_MSG: ${{ github.event.workflow_run.head_commit.message }}')
        ->and($content)->toContain('gh api "repos/$REPO/actions/runs/$RUN_ID/jobs"')
        ->and($content)->toContain('--argjson jobs')
        ->and($content)->toContain('jobs: $jobs')
        ->and($content)->toContain('curl -s -X POST "$APP_URL/api/telegram-alerts/ci"')
        ->and($content)->not->toContain('--arg status')
        ->and($content)->not->toContain('needs:');
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test:unit -- --filter="generates telegram-ci.yml from the detected workflow name"`
Expected: FAIL — current workflow has `--arg status`, no `permissions`, no `gh api`.

- [ ] **Step 3: Rewrite `buildNotifyWorkflow()`**

Replace the entire `buildNotifyWorkflow()` method in `src/Commands/SetupCiWebhookCommand.php` with:

```php
    private function buildNotifyWorkflow(string $workflowName): string
    {
        $escapedName = addcslashes($workflowName, '"\\');

        return <<<YAML
            name: Telegram CI Notification

            on:
              workflow_run:
                workflows: ["{$escapedName}"]
                types: [completed]

            permissions:
              actions: read

            jobs:
              notify:
                runs-on: ubuntu-latest
                steps:
                  - name: Notify Telegram
                    env:
                      GH_TOKEN: \${{ github.token }}
                      APP_URL: \${{ secrets.APP_URL }}
                      WEBHOOK_SECRET: \${{ secrets.TELEGRAM_CI_WEBHOOK_SECRET }}
                      STATUS: \${{ github.event.workflow_run.conclusion }}
                      BRANCH: \${{ github.event.workflow_run.head_branch }}
                      SHA: \${{ github.event.workflow_run.head_sha }}
                      COMMIT_MSG: \${{ github.event.workflow_run.head_commit.message }}
                      ACTOR: \${{ github.event.workflow_run.actor.login }}
                      RUN_URL: \${{ github.event.workflow_run.html_url }}
                      RUN_ID: \${{ github.event.workflow_run.id }}
                      RUN_STARTED: \${{ github.event.workflow_run.run_started_at }}
                      RUN_UPDATED: \${{ github.event.workflow_run.updated_at }}
                      REPO: \${{ github.repository }}
                    run: |
                      jobs=\$(gh api "repos/\$REPO/actions/runs/\$RUN_ID/jobs" --paginate \\
                        --jq '[.jobs[] | select(.started_at != null and .completed_at != null) | {name, conclusion, duration: ((.completed_at | fromdateiso8601) - (.started_at | fromdateiso8601))}]')
                      jq -n --argjson jobs "\$jobs" '{
                        status: (if env.STATUS == "success" then "success" else "failure" end),
                        branch: env.BRANCH,
                        sha: env.SHA,
                        commit: env.COMMIT_MSG,
                        actor: env.ACTOR,
                        run_url: env.RUN_URL,
                        duration: ((env.RUN_UPDATED | fromdateiso8601) - (env.RUN_STARTED | fromdateiso8601)),
                        jobs: \$jobs
                      }' | curl -s -X POST "\$APP_URL/api/telegram-alerts/ci" \\
                        -H "Authorization: Bearer \$WEBHOOK_SECRET" \\
                        -H "Content-Type: application/json" \\
                        --data-binary @-
            YAML;
    }
```

CRITICAL — heredoc escaping: this is a double-quoted (`<<<YAML`) heredoc. Every literal `$` that must survive into the file is written as `\$` (so `\${{ github.token }}`, `\$REPO`, `\$RUN_ID`, `\$jobs`, `\$APP_URL`, `\$WEBHOOK_SECRET`, `\$(gh ...)`). The jq variable `$jobs` and shell vars all use `\$`. `{$escapedName}` interpolates intentionally. Shell line continuations are `\\` (→ `\`). Keep the relative indentation so the flexible heredoc strips to column-0 output beginning with `name: Telegram CI Notification`. The jq `--jq '...'` filter contains no `$`, so no escaping needed there.

- [ ] **Step 4: Run the command suite to verify pass**

Run: `composer test:unit -- --filter=SetupCiWebhookCommand`
Expected: PASS — all command tests (the escaping test, mkdir test, multiple-files tests, and the updated content assertions).

Also run `composer test:lint` and `composer test:types`; fix any issues.

- [ ] **Step 5: Manually verify the rendered output is valid**

Run this to render the workflow and confirm the shape (no PHP needed beyond the package autoloader):

```bash
php -r 'require "vendor/autoload.php"; $r=new ReflectionMethod(\Rozkalns\TelegramAlerts\Commands\SetupCiWebhookCommand::class,"buildNotifyWorkflow"); $r->setAccessible(true); echo $r->invoke((new \Rozkalns\TelegramAlerts\Commands\SetupCiWebhookCommand())->setLaravel(app()), "CI");' 2>/dev/null | sed -n '1,3p'
```
Expected: first line is `name: Telegram CI Notification` (column 0). If the `php -r` bootstrap is awkward, instead rely on the test assertion `toStartWith('name: Telegram CI Notification')` from Task 2 Step 1, which already guarantees this.

- [ ] **Step 6: Commit**

```bash
git add src/Commands/SetupCiWebhookCommand.php tests/SetupCiWebhookCommandTest.php
git commit -m "feat: generated workflow reads job timings and uses jq env"
```

---

## Task 3: README + CHANGELOG

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update README "What You Get" CI examples**

In `README.md`, find the `### CI build passed` and `### CI build failed` example blocks. Replace the passed example body with:

```
✅ *[MyApp]* CI build passed

`a6aa687` fix: handle null route
Branch: `main` · Actor: `dependabot[bot]`

lint ✅ 23s · tests ✅ 1m 47s
⏱️ total 2m 10s

🔗 https://github.com/org/repo/actions/runs/123
```

and the failed example body with:

```
❌ *[MyApp]* CI build failed

`a6aa687` wip: broken test
Branch: `feature/x` · Actor: `Rozkalns`

lint ✅ 19s · tests ❌ 41s
⏱️ total 1m 0s

🔗 https://github.com/org/repo/actions/runs/124
```

- [ ] **Step 2: Add a note to README section 6**

In section `### 6. CI Pipeline Notifications (optional)`, in the "This will:" bullet about generating `telegram-ci.yml`, append the parenthetical so it reads:

```
- Generate `.github/workflows/telegram-ci.yml`, a standalone workflow that triggers on your CI workflow's completion (`workflow_run`) and posts the result — including a per-job breakdown and run time — to your app (it reads per-job timings via `actions: read` and the built-in `GITHUB_TOKEN`)
```

- [ ] **Step 3: Add the CHANGELOG v0.5.0 entry**

In `CHANGELOG.md`, insert above `## v0.4.0`:

```markdown
## v0.5.0

### Added

- **Per-job breakdown and run timing in CI notifications.** Build notifications now list each CI job with its result and duration (e.g. `lint ✅ 23s · tests ✅ 1m 47s`) plus the total run time (`⏱️ total 2m 10s`).

### Changed

- The generated `telegram-ci.yml` is simpler — `jq` reads values from the environment directly (`env.*`) instead of repeating each value as a `--arg`.
- The webhook payload gains two optional fields: `duration` (total run seconds) and `jobs` (array of `{name, conclusion, duration}`). Both are optional and backward-compatible — a workflow that omits them renders as before.
- The generated workflow now requests `permissions: actions: read` and uses the built-in `GITHUB_TOKEN` to read per-job timings via one call to the run's jobs API.

### Upgrade notes

Re-run `php artisan telegram:ci-webhook-setup` (or regenerate `.github/workflows/telegram-ci.yml`) to get the enriched workflow and the `actions: read` permission. No secret or `.env` changes. Existing v0.4.0 workflows keep working — they simply omit the new job/timing lines.
```

- [ ] **Step 4: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "docs: document job breakdown and run timing (v0.5.0)"
```

---

## Task 4: Full quality gate

**Files:** none (verification + fixups)

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected: PASS — pint, 100% type coverage, peck, 100% unit coverage, phpstan, rector.

- [ ] **Step 2: Auto-fix and re-run if anything failed**

If lint/refactor flagged issues: `composer lint && composer refactor`, then `composer test` again. Expected: PASS.

- [ ] **Step 3: Commit any fixups (only if files changed)**

```bash
git add -A
git commit -m "style: apply pint/rector fixups"
```

---

## Task 5: Package PR + release

**Files:** none

- [ ] **Step 1: Push the branch**

Run: `git push -u origin feat/ci-notify-job-timings`
Expected: branch pushed.

- [ ] **Step 2: Open the PR**

```bash
gh pr create --repo Rozkalns/laravel-telegram-alerts --base main --head feat/ci-notify-job-timings \
  --title "feat: per-job breakdown + run timing in CI notifications (v0.5.0)" \
  --body "$(cat <<'EOF'
## What

- Cleaner generated `telegram-ci.yml`: `jq` reads `env.*` directly (drops the `--arg` duplication; escaping invariant preserved).
- CI notifications now show a per-job breakdown and total run time. Workflow makes one `gh api .../jobs` call (`permissions: actions: read`, built-in token) and posts new optional payload fields `jobs` / `duration`; `CiWebhookController` renders them. Fully backward-compatible.

Spec: `docs/superpowers/specs/2026-05-31-ci-notify-job-timings-design.md`
Migration: v0.5.0 notes in `CHANGELOG.md`.

All gates green: 100% coverage, pint/phpstan/rector/peck.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```
Expected: PR URL returned.

- [ ] **Step 3: After merge — tag v0.5.0** (do only once the PR is merged to `main`)

```bash
git checkout main && git pull --ff-only origin main
git tag -a v0.5.0 -m "v0.5.0 — per-job breakdown and run timing in CI notifications"
git push origin v0.5.0
```
Expected: tag pushed.

---

## Task 6: Regenerate the 4 consumer PRs

**Files (external repos):** `.github/workflows/telegram-ci.yml` on each `fix/ci-notify-workflow-run` branch.

The clones from the prior rollout may still be in `/tmp/notify-rollout`. Re-clone fresh to be safe.

Canonical enriched file content (identical for all 4; workflow name is `CI` everywhere):

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
            --jq '[.jobs[] | select(.started_at != null and .completed_at != null) | {name, conclusion, duration: ((.completed_at | fromdateiso8601) - (.started_at | fromdateiso8601))}]')
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

Write that content to `/tmp/notify-rollout/telegram-ci.yml`, then for **each** repo (`kartites`, `lvva-masters`, `rozkalns.xyz`, `varna`):

- [ ] **Step 1: Re-clone and check out the existing PR branch**

```bash
rm -rf /tmp/notify-rollout/<REPO> && git clone -q "git@github.com:Rozkalns/<REPO>.git" /tmp/notify-rollout/<REPO>
git -C /tmp/notify-rollout/<REPO> checkout fix/ci-notify-workflow-run
```
Expected: branch checked out (it exists from the prior rollout).

- [ ] **Step 2: Overwrite telegram-ci.yml with the enriched version**

```bash
cp /tmp/notify-rollout/telegram-ci.yml /tmp/notify-rollout/<REPO>/.github/workflows/telegram-ci.yml
git -C /tmp/notify-rollout/<REPO> diff --stat
```
Expected: only `.github/workflows/telegram-ci.yml` changed.

- [ ] **Step 3: Commit and push (updates the open PR in place)**

```bash
git -C /tmp/notify-rollout/<REPO> add .github/workflows/telegram-ci.yml
git -C /tmp/notify-rollout/<REPO> commit -m "feat: add per-job breakdown and run timing to notify workflow"
git -C /tmp/notify-rollout/<REPO> push
```
Expected: push succeeds; the existing PR (kartites #51, lvva-masters #35, rozkalns.xyz #36, varna #42) updates.

- [ ] **Step 4: After all 4 are pushed, report the updated PR URLs to the user.**

---

## Self-Review (completed by plan author)

- **Spec coverage:** A-cleanup → Task 2 Step 3 (jq env.*, no --arg). C-enrichment workflow → Task 2 Step 3 (`gh api`, `--argjson jobs`, `permissions: actions: read`). Payload contract → Tasks 1+2. Controller render + `formatDuration` + `buildJobsLine` → Task 1. Backward-compat + malformed input → Task 1 tests. Docs/CHANGELOG/version → Task 3 + Task 5 Step 3. Rollout → Task 6. Edge cases (skipped jobs filtered, total = wall-clock, fractional→int) → workflow `select(...)` in Task 2 + `(int)` casts in Task 1.
- **Placeholder scan:** none — all code blocks concrete; `<REPO>` is an explicit substitution token with a fixed list.
- **Type/name consistency:** helpers named consistently — `buildJobsLine(mixed)`, `formatDuration(int)`; payload fields `jobs`/`duration` consistent across controller, workflow, and tests; `buildNotifyWorkflow(string)` signature unchanged.
```
