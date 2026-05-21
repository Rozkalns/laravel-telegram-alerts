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

    $workflowPath = base_path('.github/workflows/telegram-ci.yml');
    if (file_exists($workflowPath)) {
        unlink($workflowPath);
    }

    $workflowDir = base_path('.github/workflows');
    if (is_dir($workflowDir)) {
        @rmdir($workflowDir);
    }

    $githubDir = base_path('.github');
    if (is_dir($githubDir)) {
        @rmdir($githubDir);
    }
});

it('writes webhook config to env file', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('', '', 1),
    ]);

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Written to .env: TELEGRAM_CI_WEBHOOK=true')
        ->expectsOutputToContain('Written to .env: TELEGRAM_CI_WEBHOOK_SECRET=')
        ->assertSuccessful();

    $env = file_get_contents($this->envPath);
    expect($env)->toContain('TELEGRAM_CI_WEBHOOK=true')
        ->and($env)->toContain('TELEGRAM_CI_WEBHOOK_SECRET=');
});

it('replaces existing env values on re-run', function (): void {
    file_put_contents($this->envPath, "APP_NAME=TestApp\nTELEGRAM_CI_WEBHOOK=false\nTELEGRAM_CI_WEBHOOK_SECRET=old-secret\n");

    Process::fake([
        'git remote get-url origin' => Process::result('', '', 1),
    ]);

    $this->artisan('telegram:ci-webhook-setup')->assertSuccessful();

    $env = file_get_contents($this->envPath);
    expect($env)->toContain('TELEGRAM_CI_WEBHOOK=true')
        ->and($env)->not->toContain('TELEGRAM_CI_WEBHOOK=false')
        ->and($env)->not->toContain('old-secret');
});

it('sets github secrets when gh is available', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('git@github.com:Rozkalns/my-app.git'),
        'gh auth status' => Process::result(''),
        'gh secret set *' => Process::result(''),
    ]);

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
        ->expectsOutputToContain('Add this step')
        ->assertSuccessful();
});

it('warns when no github remote is detected', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('', '', 1),
    ]);

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Could not detect GitHub remote')
        ->expectsOutputToContain('Add this step')
        ->assertSuccessful();
});

it('sets secrets with env flag', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('git@github.com:Rozkalns/my-app.git'),
        'gh auth status' => Process::result(''),
        'gh secret set *' => Process::result(''),
    ]);

    $this->artisan('telegram:ci-webhook-setup --env=Testing')
        ->expectsOutputToContain('env: Testing')
        ->assertSuccessful();
});

it('writes workflow file with generate-workflow flag', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('git@github.com:Rozkalns/my-app.git'),
        'gh auth status' => Process::result(''),
        'gh secret set *' => Process::result(''),
    ]);

    $this->artisan('telegram:ci-webhook-setup --generate-workflow')->assertSuccessful();

    $workflowPath = base_path('.github/workflows/telegram-ci.yml');
    expect(file_exists($workflowPath))->toBeTrue();

    $content = file_get_contents($workflowPath);
    expect($content)->toContain('Notify Telegram')
        ->and($content)->toContain('jq -n')
        ->and($content)->toContain('TELEGRAM_CI_WEBHOOK_SECRET')
        ->and($content)->toContain('/api/telegram-alerts/ci')
        ->and($content)->toContain('--data-binary @-');
});

it('does not output snippet when generate-workflow is used', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('git@github.com:Rozkalns/my-app.git'),
        'gh auth status' => Process::result(''),
        'gh secret set *' => Process::result(''),
    ]);

    $this->artisan('telegram:ci-webhook-setup --generate-workflow')
        ->doesntExpectOutputToContain('Add this step')
        ->assertSuccessful();
});

it('outputs snippet when generate-workflow is not used', function (): void {
    Process::fake([
        'git remote get-url origin' => Process::result('git@github.com:Rozkalns/my-app.git'),
        'gh auth status' => Process::result(''),
        'gh secret set *' => Process::result(''),
    ]);

    $this->artisan('telegram:ci-webhook-setup')
        ->expectsOutputToContain('Add this step')
        ->expectsOutputToContain('jq -n')
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

it('creates env file when it does not exist', function (): void {
    unlink($this->envPath);

    Process::fake([
        'git remote get-url origin' => Process::result('', '', 1),
    ]);

    $this->artisan('telegram:ci-webhook-setup')->assertSuccessful();

    expect(file_exists($this->envPath))->toBeTrue();
    $env = file_get_contents($this->envPath);
    expect($env)->toContain('TELEGRAM_CI_WEBHOOK=true');
});
