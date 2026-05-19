<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Rozkalns\TelegramAlerts\TelegramClient;

final readonly class QueueFailureListener
{
    public function __construct(
        private TelegramClient $client,
    ) {}

    public function handle(JobFailed $event): void
    {
        if (! $this->client->isConfigured()) {
            return;
        }

        $cacheKey = 'telegram_queue_'.md5($event->job->resolveName().$event->exception->getMessage());
        if (cache()->has($cacheKey)) {
            return;
        }

        cache()->put($cacheKey, true, 60);

        $appName = config()->string('app.name', 'Laravel');
        $appEnv = config()->string('app.env', 'production');
        $appUrl = config()->string('app.url');

        $exception = $event->exception;
        $file = str_replace(base_path().'/', '', $exception->getFile());

        $lines = [
            sprintf('⚠️ *[%s]* Queue job failed', $appName),
            '',
            sprintf('`%s`', $event->job->resolveName()),
            sprintf('`%s`', mb_substr($exception->getMessage(), 0, 500)),
            '',
            sprintf('📄 `%s:%d`', $file, $exception->getLine()),
            sprintf('🔄 Queue: %s | Attempt: %d', $event->job->getQueue(), $event->job->attempts()),
            sprintf('📍 %s (%s)', $appUrl, $appEnv),
        ];

        $this->client->send(implode("\n", $lines));
    }
}
