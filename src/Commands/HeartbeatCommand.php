<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Rozkalns\TelegramAlerts\TelegramClient;

#[Signature('telegram:heartbeat')]
#[Description('Send a Telegram heartbeat to confirm the scheduler is running')]
final class HeartbeatCommand extends Command
{
    public function __construct(
        private readonly TelegramClient $client,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->isDownForMaintenance()) {
            return self::SUCCESS;
        }

        if (! $this->client->isConfigured()) {
            return self::SUCCESS;
        }

        $appName = config()->string('app.name', 'Laravel');
        $appEnv = config()->string('app.env', 'production');
        $appUrl = config()->string('app.url');

        $pendingJobs = $this->pendingJobCount();
        $failedJobs = $this->failedJobCount();

        $lines = [
            sprintf('💚 *[%s]* Heartbeat', $appName),
            '',
            sprintf('📊 Queue: %d pending, %d failed', $pendingJobs, $failedJobs),
            sprintf('📍 %s (%s)', $appUrl, $appEnv),
            sprintf('🕐 %s', now()->format('Y-m-d H:i:s T')),
        ];

        $this->client->send(implode("\n", $lines));

        return self::SUCCESS;
    }

    private function pendingJobCount(): int
    {
        return rescue(fn (): int => DB::table('jobs')->count(), 0, false);
    }

    private function failedJobCount(): int
    {
        return rescue(fn (): int => DB::table('failed_jobs')->count(), 0, false);
    }
}
