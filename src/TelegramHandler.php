<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

final class TelegramHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly string $token,
        private readonly string $chatId,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->token === '' || $this->chatId === '') {
            return;
        }

        $cacheKey = 'telegram_log_'.md5($record->message);
        if (cache()->has($cacheKey)) {
            return;
        }

        cache()->put($cacheKey, true, 60);

        $appName = config('app.name', 'Laravel');
        $appEnv = config('app.env', 'production');
        $appUrl = config('app.url', '');
        $level = $record->level->name;

        $message = $record->message;
        if (mb_strlen($message) > 3000) {
            $message = mb_substr($message, 0, 3000).'… (truncated)';
        }

        $lines = [
            sprintf('🚨 *[%s]* %s', $appName, $level),
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

        $text = implode("\n", $lines);

        rescue(fn () => Http::timeout(5)->post(sprintf('https://api.telegram.org/bot%s/sendMessage', $this->token), [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ]));
    }
}
