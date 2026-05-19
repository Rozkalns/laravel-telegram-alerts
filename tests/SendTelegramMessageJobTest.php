<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rozkalns\TelegramAlerts\Jobs\SendTelegramMessageJob;
use Rozkalns\TelegramAlerts\TelegramClient;

it('sends the message via the telegram client', function (): void {
    Http::fake();

    $job = new SendTelegramMessageJob('Hello from queue');
    $job->handle(new TelegramClient(token: 'bot-token', chatId: '12345'));

    Http::assertSent(fn ($request): bool => $request['text'] === 'Hello from queue');
});

it('has tries set to one since send handles retries', function (): void {
    $job = new SendTelegramMessageJob('Hello');

    expect($job->tries)->toBe(1);
});
