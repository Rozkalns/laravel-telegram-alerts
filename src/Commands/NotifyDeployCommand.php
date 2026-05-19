<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Rozkalns\TelegramAlerts\TelegramClient;

#[Signature('telegram:notify-deploy')]
#[Description('Send a Telegram notification after a successful deploy')]
final class NotifyDeployCommand extends Command
{
    public function __construct(
        private readonly TelegramClient $client,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->client->isConfigured()) {
            $this->warn('Telegram not configured — skipping notification.');

            return self::SUCCESS;
        }

        $appName = config()->string('app.name', 'Laravel');
        $appEnv = config()->string('app.env', 'production');
        $appUrl = config()->string('app.url');
        $commit = trim(Process::run('git log -1 --format="%h %s"')->output());

        $text = implode("\n", [
            sprintf('✅ *[%s]* deployed', $appName),
            '',
            sprintf('`%s`', $commit),
            '',
            sprintf('📍 %s (%s)', $appUrl, $appEnv),
            sprintf('🕐 %s', now()->format('Y-m-d H:i:s T')),
        ]);

        $this->client->sendQueued($text);

        $this->info('Deploy notification sent.');

        return self::SUCCESS;
    }
}
