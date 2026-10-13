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

        $message = $record->message;
        if (mb_strlen($message) > 3000) {
            $message = mb_substr($message, 0, 3000).'… (truncated)';
        }

        $lines = [sprintf('<code>%s</code>', e($message))];

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $file = str_replace(base_path().'/', '', $exception->getFile());
            $lines[] = '';
            $lines[] = sprintf('📄 <code>%s:%d</code>', e($file), $exception->getLine());
            $lines[] = sprintf('💥 <code>%s</code>', e($exception::class));
        }

        $this->client->send(
            TelegramMessage::make('🚨', e($record->level->name))->lines($lines)->at($record->datetime),
        );
    }
}
