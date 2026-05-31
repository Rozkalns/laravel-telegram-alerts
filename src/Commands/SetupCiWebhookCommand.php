<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('telegram:ci-webhook-setup {--url= : Production APP_URL for GitHub secret} {--env= : GitHub environment for secrets (e.g. Testing)} {--ci-file= : Path to CI workflow file used to detect the workflow name} {--workflow-name= : Override the CI workflow name to trigger on}')]
#[Description('Generate a CI webhook secret, configure .env and GitHub secrets')]
final class SetupCiWebhookCommand extends Command
{
    public function handle(): int
    {
        $this->info('Run this command on your local machine where gh CLI is authenticated.');
        $this->newLine();

        $secret = $this->resolveSecret();

        $repo = $this->detectGitHubRepo();
        if ($repo === '') {
            $this->warn('Could not detect GitHub remote — skipping GitHub secret setup.');
        } elseif (! $this->ghIsAvailable()) {
            $this->warn('gh CLI not found — skipping GitHub secret setup.');
            $this->warn('Install gh: https://cli.github.com then re-run this command.');
        } else {
            $this->setGitHubSecret('TELEGRAM_CI_WEBHOOK_SECRET', $secret, $repo);
            $appUrl = $this->resolveAppUrl();
            if ($appUrl !== '') {
                $this->setGitHubSecret('APP_URL', $appUrl, $repo);
            }
        }

        $this->writeEnvValue('TELEGRAM_CI_WEBHOOK', 'true');
        $this->writeEnvValue('TELEGRAM_CI_WEBHOOK_SECRET', $secret);
        $maskedSecret = substr($secret, 0, 8).'...'.substr($secret, -4);
        $this->info('Written to .env: TELEGRAM_CI_WEBHOOK=true');
        $this->info(sprintf('Written to .env: TELEGRAM_CI_WEBHOOK_SECRET=%s', $maskedSecret));

        $this->newLine();
        $this->warn('On your production server:');
        $this->line('  1. Add to .env: TELEGRAM_CI_WEBHOOK=true');
        $this->line(sprintf('  2. Add to .env: TELEGRAM_CI_WEBHOOK_SECRET=%s', $maskedSecret));
        $this->line('  3. Run: php artisan config:cache');

        $this->handleWorkflowGeneration();

        return self::SUCCESS;
    }

    private function resolveSecret(): string
    {
        $existing = $this->readEnvValue('TELEGRAM_CI_WEBHOOK_SECRET');

        if ($existing !== '' && ! $this->confirm('A webhook secret already exists. Regenerate it?')) {
            $this->info('Keeping existing webhook secret.');

            return $existing;
        }

        return bin2hex(random_bytes(32));
    }

    private function readEnvValue(string $key): string
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            return '';
        }

        $contents = (string) file_get_contents($envPath);
        $pattern = sprintf('/^%s=(.*)$/m', preg_quote($key, '/'));

        if (preg_match($pattern, $contents, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

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
                        --jq '.jobs[] | select(.started_at != null and .completed_at != null) | {name, conclusion, duration: ((.completed_at | fromdateiso8601) - (.started_at | fromdateiso8601))}' \\
                        | jq -sc '.') || jobs='[]'
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

    private function outputWorkflowSnippet(string $workflowName): void
    {
        $this->newLine();
        $this->info('Add this file as .github/workflows/telegram-ci.yml:');
        $this->newLine();
        $this->line($this->buildNotifyWorkflow($workflowName));
    }

    private function detectCiWorkflowFile(): string
    {
        $ciFileOption = $this->option('ci-file');
        if (is_string($ciFileOption) && $ciFileOption !== '') {
            $path = base_path($ciFileOption);

            if (! file_exists($path)) {
                $this->warn(sprintf('Specified CI file not found: %s', $ciFileOption));

                return '';
            }

            return $path;
        }

        $workflowDir = base_path('.github/workflows');
        if (! is_dir($workflowDir)) {
            return '';
        }

        foreach (['ci.yml', 'ci.yaml'] as $name) {
            $path = $workflowDir.'/'.$name;
            if (file_exists($path)) {
                return $path;
            }
        }

        $files = glob($workflowDir.'/*.{yml,yaml}', GLOB_BRACE | GLOB_NOSORT) ?: [];

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
    }

    private function relativePath(string $absolutePath): string
    {
        return str_replace(base_path().'/', '', $absolutePath);
    }

    private function writeEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            file_put_contents($envPath, sprintf("%s=%s\n", $key, $value));

            return;
        }

        $contents = (string) file_get_contents($envPath);

        $pattern = sprintf('/^%s=.*/m', preg_quote($key, '/'));

        if (preg_match($pattern, $contents)) {
            $contents = preg_replace($pattern, sprintf('%s=%s', $key, $value), $contents) ?? $contents;
        } else {
            $contents = rtrim($contents, "\n").sprintf("\n%s=%s\n", $key, $value);
        }

        file_put_contents($envPath, $contents);
    }

    private function detectGitHubRepo(): string
    {
        $result = Process::run('git remote get-url origin');
        if (! $result->successful()) {
            return '';
        }

        $url = trim($result->output());
        if (preg_match('#github\.com[:/](.+?)(?:\.git)?$#', $url, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function resolveAppUrl(): string
    {
        $urlOption = $this->option('url');
        if (is_string($urlOption) && $urlOption !== '') {
            return $urlOption;
        }

        $configUrl = config()->string('app.url');
        if ($configUrl !== '' && ! str_contains($configUrl, 'localhost')) {
            return $configUrl;
        }

        $this->warn('APP_URL looks like a local address — skipping APP_URL GitHub secret.');
        $this->warn('Re-run with --url=https://your-production-domain.com or set APP_URL manually in GitHub secrets.');

        return '';
    }

    private function ghIsAvailable(): bool
    {
        return Process::run('gh auth status')->successful();
    }

    private function setGitHubSecret(string $name, string $value, string $repo): void
    {
        $ghEnv = is_string($this->option('env')) ? $this->option('env') : '';
        $envOption = $ghEnv !== '' ? sprintf(' --env %s', escapeshellarg($ghEnv)) : '';

        $command = sprintf(
            'gh secret set %s --repo %s%s --body %s',
            escapeshellarg($name),
            escapeshellarg($repo),
            $envOption,
            escapeshellarg($value),
        );

        $result = Process::run($command);
        if ($result->successful()) {
            $label = $ghEnv !== '' ? sprintf('%s (env: %s)', $name, $ghEnv) : $name;
            $this->info(sprintf('Set GitHub secret: %s (repo: %s)', $label, $repo));
        } else {
            $this->error(sprintf('Failed to set GitHub secret %s: %s', $name, trim($result->errorOutput())));
        }
    }
}
