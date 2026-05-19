<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

final class TelegramHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly TelegramClient $client,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (! $this->client->isConfigured()) {
            return;
        }

        $cacheKey = 'telegram_log_'.md5($record->message);
        if (cache()->has($cacheKey)) {
            return;
        }

        cache()->put($cacheKey, true, 60);

        $appName = config()->string('app.name', 'Laravel');
        $appEnv = config()->string('app.env', 'production');
        $appUrl = config()->string('app.url');

        $message = $record->message;
        if (mb_strlen($message) > 3000) {
            $message = mb_substr($message, 0, 3000).'… (truncated)';
        }

        $lines = [
            sprintf('🚨 *[%s]* %s', $appName, $record->level->name),
            '',
            sprintf('`%s`', $message),
        ];

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $file = str_replace(base_path().'/', '', $exception->getFile());
            $lines[] = '';
            $lines[] = sprintf('📄 `%s:%d`', $file, $exception->getLine());
            $lines[] = sprintf('💥 `%s`', $exception::class);
        }

        $lines[] = '';
        $lines[] = sprintf('📍 %s (%s)', $appUrl, $appEnv);
        $lines[] = '🕐 '.$record->datetime->format('Y-m-d H:i:s T');

        $this->client->send(implode("\n", $lines));
    }
}
