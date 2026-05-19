<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Rozkalns\TelegramAlerts\TelegramClient;

#[Signature('telegram:check-backup')]
#[Description('Verify backup files exist and are recent, alert via Telegram on failure')]
final class CheckBackupCommand extends Command
{
    public function __construct(
        private readonly TelegramClient $client,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pattern = config()->string('telegram-alerts.backup_path');
        if ($pattern === '') {
            return self::SUCCESS;
        }

        if (! $this->client->isConfigured()) {
            return self::SUCCESS;
        }

        if (str_contains($pattern, '..')) {
            $this->error('Backup path must not contain ".." for security.');

            return self::FAILURE;
        }

        $maxAgeHours = config()->integer('telegram-alerts.backup_max_age_hours', 25);
        $minSizeBytes = config()->integer('telegram-alerts.backup_min_size_bytes', 1024);

        $files = glob($pattern, GLOB_NOSORT);
        if ($files === false || $files === []) {
            $this->sendFailure(sprintf("No backup files found.\nPattern: `%s`", $pattern));

            return self::FAILURE;
        }

        $newest = null;
        $newestTime = 0;

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime > $newestTime) {
                $newestTime = $mtime;
                $newest = $file;
            }
        }

        if ($newest === null) {
            $this->sendFailure(sprintf("Could not read backup file timestamps.\nPattern: `%s`", $pattern));

            return self::FAILURE;
        }

        $ageHours = (time() - $newestTime) / 3600;
        if ($ageHours > $maxAgeHours) {
            $this->sendFailure(sprintf(
                "No backup file modified in the last %d hours.\nNewest: `%s` (%s ago)\nPattern: `%s`",
                $maxAgeHours,
                basename($newest),
                $this->formatAge($ageHours),
                $pattern,
            ));

            return self::FAILURE;
        }

        $size = filesize($newest);
        if ($size !== false && $size < $minSizeBytes) {
            $this->sendFailure(sprintf(
                "Backup file suspiciously small (%s bytes).\nFile: `%s`\nPattern: `%s`",
                number_format($size),
                basename($newest),
                $pattern,
            ));

            return self::FAILURE;
        }

        $this->info('Backup check passed.');

        return self::SUCCESS;
    }

    private function sendFailure(string $detail): void
    {
        $appName = config()->string('app.name', 'Laravel');
        $appEnv = config()->string('app.env', 'production');
        $appUrl = config()->string('app.url');

        $lines = [
            sprintf('🔴 *[%s]* Backup check failed', $appName),
            '',
            $detail,
            '',
            sprintf('📍 %s (%s)', $appUrl, $appEnv),
            sprintf('🕐 %s', now()->format('Y-m-d H:i:s T')),
        ];

        $this->client->send(implode("\n", $lines));
        $this->error('Backup check failed — Telegram alert sent.');
    }

    private function formatAge(float $hours): string
    {
        if ($hours < 1) {
            return sprintf('%d min', (int) round($hours * 60));
        }

        if ($hours < 24) {
            return sprintf('%dh', (int) round($hours));
        }

        return sprintf('%dd %dh', (int) ($hours / 24), (int) $hours % 24);
    }
}
