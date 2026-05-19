<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

#[Signature('telegram:notify-deploy')]
#[Description('Send a Telegram notification after a successful deploy')]
final class NotifyDeployCommand extends Command
{
    public function handle(): int
    {
        $token = config()->string('telegram-alerts.bot_token');
        $chatId = config()->string('telegram-alerts.chat_id');

        if ($token === '' || $chatId === '') {
            $this->warn('Telegram not configured — skipping notification.');

            return self::SUCCESS;
        }

        $appName = config()->string('app.name', 'Laravel');
        $appUrl = config()->string('app.url');
        $commit = trim(Process::run('git log -1 --format="%h %s"')->output());

        $text = implode("\n", [
            sprintf('✅ *[%s]* deployed', $appName),
            '',
            sprintf('`%s`', $commit),
            '',
            sprintf('📍 %s', $appUrl),
            sprintf('🕐 %s', now()->format('Y-m-d H:i:s T')),
        ]);

        Http::timeout(5)->post(sprintf('https://api.telegram.org/bot%s/sendMessage', $token), [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ]);

        $this->info('Deploy notification sent.');

        return self::SUCCESS;
    }
}
