<?php

declare(strict_types=1);

use Rozkalns\TelegramAlerts\TelegramClient;

it('registers telegram client as a singleton', function (): void {
    $clientA = app(TelegramClient::class);
    $clientB = app(TelegramClient::class);

    expect($clientA)->toBe($clientB);
});

it('registers the telegram log channel', function (): void {
    $channels = config('logging.channels');

    expect($channels)->toHaveKey('telegram');
    expect($channels['telegram']['driver'])->toBe('custom');
});
