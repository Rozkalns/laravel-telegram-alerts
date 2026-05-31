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

it('creates the workflow directory when it does not exist', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup --workflow-name=Deploy')
        ->assertSuccessful();

    expect(file_exists(base_path('.github/workflows/telegram-ci.yml')))->toBeTrue()
        ->and(file_get_contents(base_path('.github/workflows/telegram-ci.yml')))
        ->toContain('workflows: ["Deploy"]');
});

it('falls back to snippet when only telegram-ci.yml exists in the workflow dir', function (): void {
    file_put_contents(workflowDir().'/telegram-ci.yml', "name: Telegram CI Notification\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Could not detect a CI workflow')
        ->expectsOutputToContain('name: Telegram CI Notification')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.github/workflows/telegram-ci.yml')))
        ->toBe("name: Telegram CI Notification\n");
});

it('auto-detects a single non-standard workflow file', function (): void {
    createCiWorkflow("name: Deploy\non: [push]\njobs:\n  deploy:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo deploy\n", 'deploy.yml');
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Detected CI workflow "Deploy"')
        ->expectsOutputToContain('Generated .github/workflows/telegram-ci.yml')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.github/workflows/telegram-ci.yml')))
        ->toContain('workflows: ["Deploy"]');
});

it('escapes double quotes in the workflow name', function (): void {
    createCiWorkflow("name: 'My \"App\" CI'\n\non: [push]\n\njobs:\n  build:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo build\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')->assertSuccessful();

    expect(file_get_contents(base_path('.github/workflows/telegram-ci.yml')))
        ->toContain('workflows: ["My \\"App\\" CI"]');
});
