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

function createCiWorkflow(string $content = ''): string
{
    $dir = base_path('.github/workflows');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if ($content === '') {
        $content = <<<'YAML'
            name: CI

            on: [push]

            jobs:
              lint:
                runs-on: ubuntu-latest
                steps:
                  - run: echo "lint"

              tests:
                runs-on: ubuntu-latest
                steps:
                  - run: echo "tests"
            YAML;
    }

    $path = $dir.'/ci.yml';
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

    $env = file_get_contents($this->envPath);
    expect($env)->toContain('TELEGRAM_CI_WEBHOOK_SECRET=');
});

it('prompts to regenerate when secret exists and user accepts', function (): void {
    file_put_contents($this->envPath, "APP_NAME=TestApp\nTELEGRAM_CI_WEBHOOK_SECRET=old-secret\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('A webhook secret already exists. Regenerate it?', 'yes')
        ->expectsOutputToContain('Written to .env: TELEGRAM_CI_WEBHOOK_SECRET=')
        ->assertSuccessful();

    $env = file_get_contents($this->envPath);
    expect($env)->not->toContain('old-secret');
});

it('keeps existing secret when user declines regeneration', function (): void {
    file_put_contents($this->envPath, "APP_NAME=TestApp\nTELEGRAM_CI_WEBHOOK_SECRET=old-secret\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('A webhook secret already exists. Regenerate it?', 'no')
        ->expectsOutputToContain('Keeping existing webhook secret.')
        ->assertSuccessful();

    $env = file_get_contents($this->envPath);
    expect($env)->toContain('TELEGRAM_CI_WEBHOOK_SECRET=old-secret');
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

    expect(file_exists($this->envPath))->toBeTrue();
    $env = file_get_contents($this->envPath);
    expect($env)->toContain('TELEGRAM_CI_WEBHOOK=true');
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

it('injects notify job into existing ci workflow', function (): void {
    $ciPath = createCiWorkflow();
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('Add the notify job to .github/workflows/ci.yml?', 'yes')
        ->expectsOutputToContain('Added notify job to .github/workflows/ci.yml (needs: [lint, tests]).')
        ->assertSuccessful();

    $content = file_get_contents($ciPath);
    expect($content)->toContain('notify:')
        ->and($content)->toContain('needs: [lint, tests]')
        ->and($content)->toContain('if: always()')
        ->and($content)->toContain('jq -n')
        ->and($content)->toContain('/api/telegram-alerts/ci')
        ->and($content)->toContain('TELEGRAM_CI_WEBHOOK_SECRET');
});

it('builds failed check lines for each job', function (): void {
    createCiWorkflow();
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('Add the notify job to .github/workflows/ci.yml?', 'yes')
        ->assertSuccessful();

    $content = file_get_contents(base_path('.github/workflows/ci.yml'));
    expect($content)->toContain('needs.lint.result')
        ->and($content)->toContain('needs.tests.result')
        ->and($content)->toContain('FAILED="lint"')
        ->and($content)->toContain('${FAILED:+$FAILED, }tests');
});

it('handles single job in workflow', function (): void {
    createCiWorkflow(<<<'YAML'
        name: CI

        on: [push]

        jobs:
          build:
            runs-on: ubuntu-latest
            steps:
              - run: echo "build"
        YAML);
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('Add the notify job to .github/workflows/ci.yml?', 'yes')
        ->assertSuccessful();

    $content = file_get_contents(base_path('.github/workflows/ci.yml'));
    expect($content)->toContain('needs: [build]')
        ->and($content)->toContain('FAILED="build"')
        ->and($content)->not->toContain('${FAILED:+$FAILED, }');
});

it('warns and skips when notify job already exists', function (): void {
    createCiWorkflow(<<<'YAML'
        name: CI

        on: [push]

        jobs:
          tests:
            runs-on: ubuntu-latest
            steps:
              - run: echo "tests"

          notify:
            needs: [tests]
            if: always()
            runs-on: ubuntu-latest
            steps:
              - run: echo "notify"
        YAML);
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('A notify job already exists in .github/workflows/ci.yml')
        ->assertSuccessful();
});

it('outputs snippet when user declines injection', function (): void {
    createCiWorkflow();
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('Add the notify job to .github/workflows/ci.yml?', 'no')
        ->expectsOutputToContain('Add this job to your CI workflow')
        ->expectsOutputToContain('jq -n')
        ->assertSuccessful();
});

it('outputs snippet when no ci workflow found', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('No CI workflow file found')
        ->expectsOutputToContain('Add this job to your CI workflow')
        ->assertSuccessful();
});

it('outputs snippet when workflow dir exists but has no yaml files', function (): void {
    $dir = base_path('.github/workflows');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('No CI workflow file found')
        ->assertSuccessful();
});

it('warns about multiple workflow files', function (): void {
    $dir = base_path('.github/workflows');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($dir.'/tests.yml', "name: Tests\non: [push]\njobs:\n  tests:\n    runs-on: ubuntu-latest\n");
    file_put_contents($dir.'/lint.yml', "name: Lint\non: [push]\njobs:\n  lint:\n    runs-on: ubuntu-latest\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Multiple workflow files found')
        ->expectsOutputToContain('Add this job to your CI workflow')
        ->assertSuccessful();
});

it('uses ci-file option to target specific workflow', function (): void {
    $dir = base_path('.github/workflows');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($dir.'/tests.yml', "name: Tests\n\non: [push]\n\njobs:\n  tests:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo \"tests\"\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup --ci-file=.github/workflows/tests.yml')
        ->expectsConfirmation('Add the notify job to .github/workflows/tests.yml?', 'yes')
        ->expectsOutputToContain('Added notify job to .github/workflows/tests.yml (needs: [tests]).')
        ->assertSuccessful();

    $content = file_get_contents($dir.'/tests.yml');
    expect($content)->toContain('needs: [tests]');
});

it('warns when specified ci-file does not exist', function (): void {
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup --ci-file=.github/workflows/nonexistent.yml')
        ->expectsOutputToContain('Specified CI file not found')
        ->expectsOutputToContain('Add this job to your CI workflow')
        ->assertSuccessful();
});

it('outputs snippet when workflow has no jobs section', function (): void {
    createCiWorkflow("name: CI\non: [push]\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Could not detect jobs')
        ->expectsOutputToContain('Add this job to your CI workflow')
        ->assertSuccessful();
});

it('detects single workflow file automatically', function (): void {
    $dir = base_path('.github/workflows');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($dir.'/pipeline.yml', "name: Pipeline\n\non: [push]\n\njobs:\n  build:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo \"build\"\n");
    fakeNoGithub();

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsConfirmation('Add the notify job to .github/workflows/pipeline.yml?', 'yes')
        ->expectsOutputToContain('Added notify job to .github/workflows/pipeline.yml (needs: [build]).')
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
