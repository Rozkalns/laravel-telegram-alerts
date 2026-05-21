<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('telegram:ci-webhook-setup {--env= : GitHub environment for secrets (e.g. Testing)} {--generate-workflow : Write .github/workflows/telegram-ci.yml}')]
#[Description('Generate a CI webhook secret, configure .env and GitHub secrets')]
final class SetupCiWebhookCommand extends Command
{
    public function handle(): int
    {
        $appEnv = config()->string('app.env', 'production');
        if ($appEnv !== 'production') {
            $this->warn(sprintf('Running in [%s] environment — APP_URL will be set from this environment.', $appEnv));
            $this->warn(sprintf('APP_URL: %s', config()->string('app.url')));

            if (! $this->confirm('Continue?')) {
                return self::SUCCESS;
            }
        }

        $secret = bin2hex(random_bytes(32));

        $this->writeEnvValue('TELEGRAM_CI_WEBHOOK', 'true');
        $this->writeEnvValue('TELEGRAM_CI_WEBHOOK_SECRET', $secret);
        $this->info('Written to .env: TELEGRAM_CI_WEBHOOK=true');
        $this->info(sprintf('Written to .env: TELEGRAM_CI_WEBHOOK_SECRET=%s', $secret));

        $repo = $this->detectGitHubRepo();
        if ($repo === '') {
            $this->warn('Could not detect GitHub remote — skipping GitHub secret setup.');
            $this->warn('Set TELEGRAM_CI_WEBHOOK_SECRET manually in your CI environment.');
        } elseif (! $this->ghIsAvailable()) {
            $this->warn('gh CLI not found — skipping GitHub secret setup.');
            $this->warn('Install gh: https://cli.github.com then re-run this command.');
        } else {
            $this->setGitHubSecret('TELEGRAM_CI_WEBHOOK_SECRET', $secret, $repo);
            $this->setGitHubSecret('APP_URL', config()->string('app.url'), $repo);
        }

        if ($this->option('generate-workflow')) {
            $this->writeWorkflowFile();
        } else {
            $this->outputWorkflowSnippet();
        }

        return self::SUCCESS;
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

    private function writeWorkflowFile(): void
    {
        $workflowDir = base_path('.github/workflows');
        if (! is_dir($workflowDir)) {
            mkdir($workflowDir, 0755, true);
        }

        $workflow = $this->buildWorkflowContent();
        $path = $workflowDir.'/telegram-ci.yml';
        file_put_contents($path, $workflow);
        $this->info(sprintf('Written workflow: %s', $path));
    }

    private function buildWorkflowContent(): string
    {
        return <<<'YAML'
            name: Notify Telegram

            on:
              workflow_run:
                workflows: ["*"]
                types: [completed]

            jobs:
              notify:
                runs-on: ubuntu-latest
                steps:
                  - name: Notify Telegram
                    run: |
                      jq -n \
                        --arg status "${{ github.event.workflow_run.conclusion }}" \
                        --arg branch "${{ github.event.workflow_run.head_branch }}" \
                        --arg commit "${{ github.event.workflow_run.head_commit.message }}" \
                        --arg actor "${{ github.event.workflow_run.actor.login }}" \
                        --arg run_url "${{ github.event.workflow_run.html_url }}" \
                        '{status: $status, branch: $branch, commit: $commit, actor: $actor, run_url: $run_url}' | \
                      curl -s -X POST "${{ secrets.APP_URL }}/api/telegram-alerts/ci" \
                        -H "Authorization: Bearer ${{ secrets.TELEGRAM_CI_WEBHOOK_SECRET }}" \
                        -H "Content-Type: application/json" \
                        --data-binary @-

            YAML;
    }

    private function outputWorkflowSnippet(): void
    {
        $this->newLine();
        $this->info('Add this step to your GitHub Actions workflow:');
        $this->newLine();
        $this->line(<<<'SNIPPET'
              - name: Notify Telegram
                if: always()
                run: |
                  jq -n \
                    --arg status "${{ job.status }}" \
                    --arg branch "${{ github.ref_name }}" \
                    --arg commit "${{ github.event.head_commit.message }}" \
                    --arg actor "${{ github.actor }}" \
                    --arg run_url "https://github.com/${{ github.repository }}/actions/runs/${{ github.run_id }}" \
                    '{status: $status, branch: $branch, commit: $commit, actor: $actor, run_url: $run_url}' | \
                  curl -s -X POST "${{ secrets.APP_URL }}/api/telegram-alerts/ci" \
                    -H "Authorization: Bearer ${{ secrets.TELEGRAM_CI_WEBHOOK_SECRET }}" \
                    -H "Content-Type: application/json" \
                    --data-binary @-
            SNIPPET);
    }
}
