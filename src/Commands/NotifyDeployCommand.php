<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Rozkalns\TelegramAlerts\TelegramClient;
use Rozkalns\TelegramAlerts\TelegramMessage;

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

        $shortSha = trim(Process::run('git log -1 --format="%h"')->output());
        $commitMsg = trim(Process::run('git log -1 --format="%s"')->output());

        $commitLine = match (true) {
            $shortSha !== '' && $commitMsg !== '' => sprintf('<code>%s</code> %s', e($shortSha), e($commitMsg)),
            $shortSha !== '' => sprintf('<code>%s</code>', e($shortSha)),
            default => 'unknown',
        };

        $this->client->sendQueued(TelegramMessage::make('✅', 'deployed')->line($commitLine));

        $this->info('Deploy notification sent.');

        return self::SUCCESS;
    }
}
