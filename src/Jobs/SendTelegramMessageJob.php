<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rozkalns\TelegramAlerts\TelegramClient;

final class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $text,
    ) {}

    public function handle(TelegramClient $client): void
    {
        $client->send($this->text);
    }
}
