# CI Notify via `workflow_run` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `telegram:ci-webhook-setup` generate a standalone `workflow_run` workflow (`telegram-ci.yml`) instead of injecting an inline `notify` job, so CI notifications work on Dependabot/fork PRs; then roll the fix out to all 4 consumer repos.

**Architecture:** The setup command stops mutating the consumer's CI file. Instead it detects the CI workflow's `name:` and writes `.github/workflows/telegram-ci.yml`, which triggers `on: workflow_run` of that named workflow. Because `workflow_run` runs in the trusted default-branch context, Actions secrets are available even for untrusted PRs. `github.event.workflow_run.conclusion` aggregates all CI jobs, giving one notification with no API polling. The HTTP endpoint contract is unchanged.

**Tech Stack:** PHP 8.4+, Laravel 13, Pest (orchestra/testbench), pint, phpstan, rector, peck. GitHub Actions YAML. `gh` CLI for consumer-repo PRs.

**Spec:** `docs/superpowers/specs/2026-05-31-ci-notify-workflow-run-design.md`

**Branch:** `fix/ci-notify-workflow-run` (already created; spec already committed there).

---

## File Structure

- `src/Commands/SetupCiWebhookCommand.php` — modify. Remove inline-injection helpers; add workflow-generation helpers; update signature.
- `tests/SetupCiWebhookCommandTest.php` — rewrite the workflow-related tests; keep the secret/.env/GitHub tests.
- `README.md` — modify §6 (CI Pipeline Notifications) + add migration note.
- `CHANGELOG.md` — add v0.4.0 entry with migration steps.
- Consumer repos (external, via `gh`): `Rozkalns/kartites`, `Rozkalns/lvva-masters`, `Rozkalns/rozkalns.xyz`, `Rozkalns/varna` — remove inline `notify` job, add `telegram-ci.yml`.

---

## Task 1: Rewrite the command's workflow-generation logic

**Files:**
- Modify: `src/Commands/SetupCiWebhookCommand.php`

This task replaces the inline-injection code with standalone-workflow generation. We write the tests first (Task 2 below is the test rewrite — but because the command and tests are tightly coupled in one file each, do Task 1's Step 1 = tests, Step 2 = run red, Step 3 = implement, Step 4 = run green).

- [ ] **Step 1: Rewrite `tests/SetupCiWebhookCommandTest.php`**

Replace the entire file with the following. The secret/.env/GitHub tests are unchanged; the workflow tests are new.

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->envPath = base_path('.env');
    file_put_contents($this->envPath, "APP_NAME=TestApp\n");
});

afterEach(function (): void {
    if (file_exists($this->envPath)) {
        unlink($this->envPath);
    }

    $workflowDir = base_path('.github/workflows');
    if (is_dir($workflowDir)) {
        array_map(unlink(...), glob($workflowDir.'/*.yml') ?: []);
        @rmdir($workflowDir);
    }

    $githubDir = base_path('.github');
    if (is_dir($githubDir)) {
        @rmdir($githubDir);
    }
});

function fakeNoGithub(): void
{
    Process::fake([
        'git remote get-url origin' => Process::result('', '', 1),
    ]);
}

function fakeWithGithub(): void
{
    Process::fake([
        'git remote get-url origin' => Process::result('git@github.com:Rozkalns/my-app.git'),
        'gh auth status' => Process::result(''),
        'gh secret set *' => Process::result(''),
    ]);
}

function workflowDir(): string
{
    $dir = base_path('.github/workflows');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

function createCiWorkflow(string $content = '', string $filename = 'ci.yml'): string
{
    if ($content === '') {
        $content = <<<'YAML'
            name: CI

            on: [push]

            jobs:
              lint:
                runs-on: ubuntu-latest
                steps:
                  - run: echo "lint"
            YAML;
    }

    $path = workflowDir().'/'.$filename;
    file_put_contents($path, $content);

    return $path;
}

// --- secret / .env / github (unchanged behavior) ---

it('shows run locally reminder', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Run this command on your local machine where gh CLI is authenticated.')
        ->assertSuccessful();
});

it('generates a new secret on first run', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Written to .env: TELEGRAM_CI_WEBHOOK_SECRET=')
        ->assertSuccessful();

    expect(file_get_contents($this->envPath))->toContain('TELEGRAM_CI_WEBHOOK_SECRET=');
});

it('prompts to regenerate when secret exists and user accepts', function (): void {
    file_put_contents($this->envPath, "APP_NAME=TestApp\nTELEGRAM_CI_WEBHOOK_SECRET=old-secret\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('A webhook secret already exists. Regenerate it?', 'yes')
        ->expectsOutputToContain('Written to .env: TELEGRAM_CI_WEBHOOK_SECRET=')
        ->assertSuccessful();

    expect(file_get_contents($this->envPath))->not->toContain('old-secret');
});

it('keeps existing secret when user declines regeneration', function (): void {
    file_put_contents($this->envPath, "APP_NAME=TestApp\nTELEGRAM_CI_WEBHOOK_SECRET=old-secret\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('A webhook secret already exists. Regenerate it?', 'no')
        ->expectsOutputToContain('Keeping existing webhook secret.')
        ->assertSuccessful();

    expect(file_get_contents($this->envPath))->toContain('TELEGRAM_CI_WEBHOOK_SECRET=old-secret');
});

it('replaces existing env values on re-run', function (): void {
    file_put_contents($this->envPath, "APP_NAME=TestApp\nTELEGRAM_CI_WEBHOOK=false\nTELEGRAM_CI_WEBHOOK_SECRET=old-secret\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('A webhook secret already exists. Regenerate it?', 'yes')
        ->assertSuccessful();

    $env = file_get_contents($this->envPath);
    expect($env)->toContain('TELEGRAM_CI_WEBHOOK=true')
        ->and($env)->not->toContain('TELEGRAM_CI_WEBHOOK=false')
        ->and($env)->not->toContain('old-secret');
});

it('creates env file when it does not exist', function (): void {
    unlink($this->envPath);
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')->assertSuccessful();

    expect(file_exists($this->envPath))->toBeTrue()
        ->and(file_get_contents($this->envPath))->toContain('TELEGRAM_CI_WEBHOOK=true');
});

it('sets github secrets when gh is available', function (): void {
    fakeWithGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Set GitHub secret: TELEGRAM_CI_WEBHOOK_SECRET (repo: Rozkalns/my-app)')
        ->expectsOutputToContain('Set GitHub secret: APP_URL')
        ->assertSuccessful();
});

it('supports https remote url', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('https://github.com/Rozkalns/my-app.git'),
        'gh auth status' => Process::result(''),
        'gh secret set *' => Process::result(''),
    ]);

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('repo: Rozkalns/my-app')
        ->assertSuccessful();
});

it('warns when gh cli is not available', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('git@github.com:Rozkalns/my-app.git'),
        'gh auth status' => Process::result('', 'not logged in', 1),
    ]);

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('gh CLI not found')
        ->assertSuccessful();
});

it('warns when no github remote is detected', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Could not detect GitHub remote')
        ->assertSuccessful();
});

it('sets secrets with env flag', function (): void {
    fakeWithGithub();

    $this->artisan('telegram:ci-webhook-setup --env=Testing')
        ->expectsOutputToContain('env: Testing')
        ->assertSuccessful();
});

it('reports error when gh secret set fails', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('git@github.com:Rozkalns/my-app.git'),
        'gh auth status' => Process::result(''),
        'gh secret set *' => Process::result('', 'permission denied', 1),
    ]);

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Failed to set GitHub secret')
        ->assertSuccessful();
});

it('shows production setup checklist', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('On your production server:')
        ->expectsOutputToContain('Add to .env: TELEGRAM_CI_WEBHOOK=true')
        ->expectsOutputToContain('Run: php artisan config:cache')
        ->assertSuccessful();
});

it('masks secret in output', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('...')
        ->doesntExpectOutputToContain('TELEGRAM_CI_WEBHOOK_SECRET='.str_repeat('0', 64))
        ->assertSuccessful();
});

it('uses url flag for app url github secret', function (): void {
    fakeWithGithub();

    $this->artisan('telegram:ci-webhook-setup --url=https://myapp.com')
        ->expectsOutputToContain('Set GitHub secret: APP_URL')
        ->assertSuccessful();
});

it('skips app url when localhost detected', function (): void {
    config()->set('app.url', 'http://localhost:8000');

    Process::fake([
        'git remote get-url origin' => Process::result('git@github.com:Rozkalns/my-app.git'),
        'gh auth status' => Process::result(''),
        'gh secret set *' => Process::result(''),
    ]);

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('APP_URL looks like a local address')
        ->assertSuccessful();
});

it('warns when remote is not a github url', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('git@gitlab.com:Rozkalns/my-app.git'),
    ]);

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Could not detect GitHub remote')
        ->assertSuccessful();
});

it('writes env values on first run', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Written to .env: TELEGRAM_CI_WEBHOOK=true')
        ->expectsOutputToContain('Written to .env: TELEGRAM_CI_WEBHOOK_SECRET=')
        ->assertSuccessful();

    $env = file_get_contents($this->envPath);
    expect($env)->toContain('TELEGRAM_CI_WEBHOOK=true')
        ->and($env)->toContain('TELEGRAM_CI_WEBHOOK_SECRET=');
});

// --- workflow_run generation (new behavior) ---

it('generates telegram-ci.yml from the detected workflow name', function (): void {
    createCiWorkflow();
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Detected CI workflow "CI"')
        ->expectsOutputToContain('Generated .github/workflows/telegram-ci.yml')
        ->assertSuccessful();

    $content = file_get_contents(base_path('.github/workflows/telegram-ci.yml'));
    expect($content)->toStartWith('name: Telegram CI Notification')
        ->and($content)->toContain('workflow_run:')
        ->and($content)->toContain('workflows: ["CI"]')
        ->and($content)->toContain('types: [completed]')
        ->and($content)->toContain('github.event.workflow_run.conclusion')
        ->and($content)->toContain('COMMIT_MSG: ${{ github.event.workflow_run.head_commit.message }}')
        ->and($content)->toContain('curl -s -X POST "$APP_URL/api/telegram-alerts/ci"')
        ->and($content)->not->toContain('needs:');
});

it('reads the workflow name from a quoted name field', function (): void {
    createCiWorkflow("name: \"My Pipeline\"\n\non: [push]\n\njobs:\n  build:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo build\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')->assertSuccessful();

    expect(file_get_contents(base_path('.github/workflows/telegram-ci.yml')))
        ->toContain('workflows: ["My Pipeline"]');
});

it('defaults to CI when the workflow file has no name', function (): void {
    createCiWorkflow("on: [push]\n\njobs:\n  build:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo build\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('No "name:" found')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.github/workflows/telegram-ci.yml')))
        ->toContain('workflows: ["CI"]');
});

it('honors the workflow-name override', function (): void {
    createCiWorkflow();
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup --workflow-name=Build')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.github/workflows/telegram-ci.yml')))
        ->toContain('workflows: ["Build"]');
});

it('uses ci-file option for name detection', function (): void {
    createCiWorkflow("name: Tests\n\non: [push]\n\njobs:\n  tests:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo tests\n", 'tests.yml');
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup --ci-file=.github/workflows/tests.yml')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.github/workflows/telegram-ci.yml')))
        ->toContain('workflows: ["Tests"]');
});

it('outputs the snippet when no ci workflow is found', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Could not detect a CI workflow')
        ->expectsOutputToContain('name: Telegram CI Notification')
        ->assertSuccessful();

    expect(file_exists(base_path('.github/workflows/telegram-ci.yml')))->toBeFalse();
});

it('warns when the specified ci-file does not exist', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup --ci-file=.github/workflows/nope.yml')
        ->expectsOutputToContain('Specified CI file not found')
        ->expectsOutputToContain('name: Telegram CI Notification')
        ->assertSuccessful();
});

it('warns about multiple workflow files', function (): void {
    createCiWorkflow("name: Tests\non: [push]\njobs:\n  t:\n    runs-on: ubuntu-latest\n", 'tests.yml');
    createCiWorkflow("name: Lint\non: [push]\njobs:\n  l:\n    runs-on: ubuntu-latest\n", 'lint.yml');
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Multiple workflow files found')
        ->expectsOutputToContain('name: Telegram CI Notification')
        ->assertSuccessful();
});

it('ignores its own telegram-ci.yml when detecting the source workflow', function (): void {
    createCiWorkflow();
    file_put_contents(base_path('.github/workflows/telegram-ci.yml'), "name: Telegram CI Notification\non: [workflow_run]\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->doesntExpectOutputToContain('Multiple workflow files found')
        ->expectsConfirmation('telegram-ci.yml already exists — overwrite it?', 'yes')
        ->expectsOutputToContain('Generated .github/workflows/telegram-ci.yml')
        ->assertSuccessful();
});

it('does not overwrite an existing telegram-ci.yml when declined', function (): void {
    createCiWorkflow();
    file_put_contents(base_path('.github/workflows/telegram-ci.yml'), "existing-content\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('telegram-ci.yml already exists — overwrite it?', 'no')
        ->expectsOutputToContain('name: Telegram CI Notification')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.github/workflows/telegram-ci.yml')))
        ->toBe("existing-content\n");
});
```

- [ ] **Step 2: Run the suite to confirm the new tests fail**

Run: `composer test:unit -- --filter=SetupCiWebhookCommand`
Expected: FAIL — new tests reference `telegram-ci.yml` generation and messages that don't exist yet.

- [ ] **Step 3: Rewrite `SetupCiWebhookCommand.php`**

(a) Update the signature attribute (add `--workflow-name`, remove any `--generate-workflow` reference):

```php
#[Signature('telegram:ci-webhook-setup {--url= : Production APP_URL for GitHub secret} {--env= : GitHub environment for secrets (e.g. Testing)} {--ci-file= : Path to CI workflow file used to detect the workflow name} {--workflow-name= : Override the CI workflow name to trigger on}')]
```

(b) Delete these methods entirely: `handleWorkflowInjection`, `hasExistingNotifyJob`, `parseJobNames`, `buildNotifyJob`, `buildFailedCheckLines`, `outputNotifySnippet`.

(c) In `handle()`, replace the `$this->handleWorkflowInjection();` call with `$this->handleWorkflowGeneration();` (same position, just before `return self::SUCCESS;`).

(d) Modify `detectCiWorkflowFile()` so the glob branch ignores the generated file. Replace its glob section with:

```php
        $files = glob($workflowDir.'/*.{yml,yaml}', GLOB_BRACE | GLOB_NOSORT);
        if ($files === false) {
            return '';
        }

        $files = array_values(array_filter(
            $files,
            fn (string $file): bool => basename($file) !== 'telegram-ci.yml',
        ));

        if ($files === []) {
            return '';
        }

        if (count($files) === 1) {
            return $files[0];
        }

        $this->warn('Multiple workflow files found — use --ci-file or --workflow-name to specify.');

        return '';
```

(e) Add the new methods:

```php
    private function handleWorkflowGeneration(): void
    {
        $override = $this->option('workflow-name');
        $override = is_string($override) ? $override : '';

        $ciFile = $this->detectCiWorkflowFile();

        if ($ciFile === '' && $override === '') {
            $this->newLine();
            $this->warn('Could not detect a CI workflow to trigger on.');
            $this->line('  Re-run with --workflow-name=<your CI workflow name>, or add the file manually:');
            $this->outputWorkflowSnippet('CI');

            return;
        }

        $workflowName = $override !== '' ? $override : $this->resolveWorkflowName($ciFile);

        $targetPath = base_path('.github/workflows/telegram-ci.yml');

        if (file_exists($targetPath) && ! $this->confirm('telegram-ci.yml already exists — overwrite it?', true)) {
            $this->outputWorkflowSnippet($workflowName);

            return;
        }

        $dir = dirname($targetPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($targetPath, $this->buildNotifyWorkflow($workflowName)."\n");

        $this->newLine();
        $this->info(sprintf('Generated %s (triggers on workflow "%s").', $this->relativePath($targetPath), $workflowName));
        $this->warn('Commit and merge it to your default branch — workflow_run only fires from the default branch.');
    }

    private function resolveWorkflowName(string $ciFile): string
    {
        $name = $this->parseWorkflowName((string) file_get_contents($ciFile));

        if ($name === '') {
            $this->warn(sprintf('No "name:" found in %s — defaulting trigger to "CI".', $this->relativePath($ciFile)));
            $this->warn('Add a `name:` to your CI workflow or re-run with --workflow-name=<name>.');

            return 'CI';
        }

        $this->info(sprintf('Detected CI workflow "%s" from %s.', $name, $this->relativePath($ciFile)));

        return $name;
    }

    private function parseWorkflowName(string $content): string
    {
        if (preg_match('/^name:\s*(.+?)\s*$/m', $content, $matches)) {
            return trim($matches[1], "\"'");
        }

        return '';
    }

    private function buildNotifyWorkflow(string $workflowName): string
    {
        return <<<YAML
            name: Telegram CI Notification

            on:
              workflow_run:
                workflows: ["{$workflowName}"]
                types: [completed]

            jobs:
              notify:
                runs-on: ubuntu-latest
                steps:
                  - name: Notify Telegram
                    env:
                      APP_URL: \${{ secrets.APP_URL }}
                      WEBHOOK_SECRET: \${{ secrets.TELEGRAM_CI_WEBHOOK_SECRET }}
                      STATUS: \${{ github.event.workflow_run.conclusion }}
                      BRANCH: \${{ github.event.workflow_run.head_branch }}
                      SHA: \${{ github.event.workflow_run.head_sha }}
                      COMMIT_MSG: \${{ github.event.workflow_run.head_commit.message }}
                      ACTOR: \${{ github.event.workflow_run.actor.login }}
                      RUN_URL: \${{ github.event.workflow_run.html_url }}
                    run: |
                      if [ "\$STATUS" != "success" ]; then STATUS="failure"; fi
                      jq -n \\
                        --arg status "\$STATUS" \\
                        --arg branch "\$BRANCH" \\
                        --arg sha "\$SHA" \\
                        --arg commit "\$COMMIT_MSG" \\
                        --arg actor "\$ACTOR" \\
                        --arg run_url "\$RUN_URL" \\
                        '{status: \$status, branch: \$branch, sha: \$sha, commit: \$commit, actor: \$actor, run_url: \$run_url}' | \\
                      curl -s -X POST "\$APP_URL/api/telegram-alerts/ci" \\
                        -H "Authorization: Bearer \$WEBHOOK_SECRET" \\
                        -H "Content-Type: application/json" \\
                        --data-binary @-
            YAML;
    }

    private function outputWorkflowSnippet(string $workflowName): void
    {
        $this->newLine();
        $this->info('Add this file as .github/workflows/telegram-ci.yml:');
        $this->newLine();
        $this->line($this->buildNotifyWorkflow($workflowName));
    }
```

Note on the heredoc: it is a flexible heredoc — the closing `YAML;` is indented to match the method body, and PHP strips that common indentation, so the emitted file begins at column 0 with `name: Telegram CI Notification`. Keep the relative indentation shown above intact.

- [ ] **Step 4: Run the command-suite to confirm green**

Run: `composer test:unit -- --filter=SetupCiWebhookCommand`
Expected: PASS, all command tests.

- [ ] **Step 5: Commit**

```bash
git add src/Commands/SetupCiWebhookCommand.php tests/SetupCiWebhookCommandTest.php
git commit -m "feat: generate standalone workflow_run notify workflow"
```

---

## Task 2: Update README and CHANGELOG

**Files:**
- Modify: `README.md` (§6, lines ~95–130)
- Modify: `CHANGELOG.md` (new top entry)

- [ ] **Step 1: Update README §6**

Replace the "Options" block that mentions `--generate-workflow` and the surrounding description so it reads:

```markdown
### 6. CI Pipeline Notifications (optional)

Get Telegram alerts when your GitHub Actions CI workflow passes or fails — on any branch or PR, including Dependabot and fork PRs.

**One-command setup:**

\`\`\`bash
php artisan telegram:ci-webhook-setup
\`\`\`

This will:
- Generate a secure webhook secret
- Write `TELEGRAM_CI_WEBHOOK=true` and the secret to `.env`
- Set `TELEGRAM_CI_WEBHOOK_SECRET` and `APP_URL` as GitHub repository secrets (requires `gh` CLI)
- Generate `.github/workflows/telegram-ci.yml`, a standalone workflow that triggers on your CI workflow's completion (`workflow_run`) and posts the result to your app

**Options:**

\`\`\`bash
# Target a specific GitHub environment for the secrets
php artisan telegram:ci-webhook-setup --env=Testing

# Point at a specific CI workflow file for name detection
php artisan telegram:ci-webhook-setup --ci-file=.github/workflows/tests.yml

# Override the CI workflow name the notifier triggers on
php artisan telegram:ci-webhook-setup --workflow-name="CI"
\`\`\`

> **Why a separate workflow?** `workflow_run` runs in your repository's trusted
> context, so repository secrets are available even on Dependabot and fork PRs
> (where an injected job would receive empty secrets and fail). It also begins
> firing only once `telegram-ci.yml` is on your **default branch** — commit and
> merge it before expecting notifications.
```

(Keep the existing "Manual setup" `.env` block that follows.)

- [ ] **Step 2: Add the CHANGELOG entry**

Insert below the `# Changelog` header block, above `## v0.3.0`:

```markdown
## v0.4.0

### Changed

- **CI notifications now use a standalone `workflow_run` workflow.** `telegram:ci-webhook-setup` generates `.github/workflows/telegram-ci.yml` instead of injecting a `notify` job into your CI workflow. The previous inline job failed on Dependabot and fork PRs, where GitHub withholds repository secrets from the untrusted run context (empty `APP_URL` produced a malformed `curl` URL and a non-zero exit). The new workflow runs in the trusted default-branch context, so secrets are available for every run. Added `--workflow-name` to override CI workflow-name detection; removed the unused `--generate-workflow` flag.

### Upgrade notes

For each repository already using the injected `notify` job:

1. Delete the `notify:` job from your CI workflow file (e.g. `.github/workflows/ci.yml`).
2. Re-run `php artisan telegram:ci-webhook-setup` (or copy the printed snippet) to add `telegram-ci.yml`.
3. **No secret changes needed** — your existing `APP_URL` and `TELEGRAM_CI_WEBHOOK_SECRET` *Actions* secrets keep working, because `workflow_run` runs in the trusted context.
4. Merge `telegram-ci.yml` to your default branch to activate it (`workflow_run` only fires from the default branch).
```

- [ ] **Step 3: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "docs: document workflow_run notify setup and v0.4.0 migration"
```

---

## Task 3: Run the full quality-gate suite

**Files:** none (verification + any fixups)

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected: PASS on lint, type-coverage (100%), typos, unit (100% coverage), phpstan, rector.

- [ ] **Step 2: Auto-fix style/refactors if anything failed**

If `test:lint` or `test:refactor` reported issues:
Run: `composer lint && composer refactor`
Then re-run: `composer test`
Expected: PASS.

- [ ] **Step 3: Commit any fixups (only if files changed)**

```bash
git add -A
git commit -m "style: apply pint/rector fixups"
```

---

## Task 4: Open the package PR

**Files:** none

- [ ] **Step 1: Push the branch**

Run: `git push -u origin fix/ci-notify-workflow-run`
Expected: branch pushed.

- [ ] **Step 2: Open the PR**

```bash
gh pr create --repo Rozkalns/laravel-telegram-alerts \
  --title "fix: CI notify works on Dependabot/fork PRs via workflow_run" \
  --body "$(cat <<'EOF'
## Problem
The injected `notify` job fails on Dependabot/fork PRs: GitHub withholds Actions secrets from the untrusted run context, so `secrets.APP_URL` is empty, `curl` gets a bare path, and exits 3.

## Fix
`telegram:ci-webhook-setup` now generates a standalone `.github/workflows/telegram-ci.yml` that triggers `on: workflow_run` of the CI workflow. It runs in the trusted default-branch context (secrets available for every run) and uses `workflow_run.conclusion` for a single aggregated notification — no API polling, no `needs:`, no extra permissions.

See `docs/superpowers/specs/2026-05-31-ci-notify-workflow-run-design.md`.

## Migration
See v0.4.0 upgrade notes in CHANGELOG.md.
EOF
)"
```
Expected: PR URL returned.

---

## Task 5: Roll out to consumer repos (kartites, lvva-masters, rozkalns.xyz, varna)

**Files (external repos):** per repo, the CI workflow file + a new `telegram-ci.yml`.

Repo data (all use workflow `name: CI`):

| Repo | CI file |
|------|---------|
| `Rozkalns/kartites` | `.github/workflows/ci.yml` |
| `Rozkalns/lvva-masters` | `.github/workflows/tests.yml` |
| `Rozkalns/rozkalns.xyz` | `.github/workflows/ci.yml` |
| `Rozkalns/varna` | `.github/workflows/ci.yml` |

Repeat Steps 1–6 for **each** repo, substituting `<REPO>` and `<CI_FILE>`.

- [ ] **Step 1: Clone into a scratch dir**

```bash
rm -rf /tmp/notify-rollout/<REPO> && git clone "https://github.com/Rozkalns/<REPO>.git" /tmp/notify-rollout/<REPO>
```
Expected: clone succeeds.

- [ ] **Step 2: Create a branch**

```bash
git -C /tmp/notify-rollout/<REPO> checkout -b fix/ci-notify-workflow-run
```

- [ ] **Step 3: Remove the inline `notify` job from `<CI_FILE>`**

Read `/tmp/notify-rollout/<REPO>/<CI_FILE>` and delete the entire `  notify:` job block (from the `  notify:` line through its last `--data-binary @-` line, including the blank line above it). Leave the build/test jobs intact. Use the Edit tool with the exact `notify:` block as `old_string` and `""` (trimming the preceding blank line) as the replacement.

- [ ] **Step 4: Add `telegram-ci.yml`**

Create `/tmp/notify-rollout/<REPO>/.github/workflows/telegram-ci.yml` with exactly this content:

```yaml
name: Telegram CI Notification

on:
  workflow_run:
    workflows: ["CI"]
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

- [ ] **Step 5: Commit and push**

```bash
git -C /tmp/notify-rollout/<REPO> add -A
git -C /tmp/notify-rollout/<REPO> commit -m "fix: move CI notify to standalone workflow_run workflow"
git -C /tmp/notify-rollout/<REPO> push -u origin fix/ci-notify-workflow-run
```
Expected: branch pushed.

- [ ] **Step 6: Open the PR**

```bash
gh pr create --repo Rozkalns/<REPO> \
  --title "fix: move CI notify to standalone workflow_run workflow" \
  --body "Replaces the inline notify job (which fails on Dependabot/fork PRs due to withheld secrets) with a standalone telegram-ci.yml triggered on workflow_run. Secrets unchanged. Merge to default branch to activate."
```
Expected: PR URL returned.

- [ ] **Step 7: After all 4 PRs exist, report the list of PR URLs back to the user for review/merge.**

---

## Self-Review (completed by plan author)

- **Spec coverage:** §1 generated file → Task 1 Step 3(e) `buildNotifyWorkflow` + Task 5 Step 4. §2 command changes → Task 1 Step 3(a)–(e). §3 docs/migration/changelog → Task 2. §4 4-repo rollout → Task 5. §5 testing/gates → Task 1 Steps 1–4 + Task 3. Edge cases (activation lag, missing name, re-run detection, multiple workflows) → covered by tests in Task 1 Step 1 and command logic in Step 3.
- **Placeholder scan:** none — all code blocks are concrete; `<REPO>`/`<CI_FILE>` are explicit substitution tokens with a lookup table.
- **Type consistency:** method names used consistently — `handleWorkflowGeneration`, `resolveWorkflowName`, `parseWorkflowName`, `buildNotifyWorkflow`, `outputWorkflowSnippet`, `detectCiWorkflowFile`, `relativePath`. Removed methods are not referenced anywhere after removal.
```
