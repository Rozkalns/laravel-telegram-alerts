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
            sprintf('⚠️ <b>[%s]</b> Queue job failed', e($appName)),
            '',
            sprintf('<code>%s</code>', e($event->job->resolveName())),
            sprintf('<code>%s</code>', e(mb_substr($exception->getMessage(), 0, 500))),
            '',
            sprintf('📄 <code>%s:%d</code>', e($file), $exception->getLine()),
            sprintf('🔄 Queue: %s | Attempt: %d', e($event->job->getQueue()), $event->job->attempts()),
            sprintf('📍 %s (%s)', e($appUrl), e($appEnv)),
        ];

        $this->client->send(implode("\n", $lines));
    }
}
