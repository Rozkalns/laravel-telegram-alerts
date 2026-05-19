<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rozkalns\TelegramAlerts\TelegramClient;

beforeEach(function (): void {
    Http::fake();
});

it('sends a heartbeat message', function (): void {
    $this->artisan('telegram:heartbeat')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Heartbeat')
        && str_contains((string) $request['text'], 'TestApp')
        && str_contains((string) $request['text'], 'Queue'));
});

it('is a no-op when not configured', function (): void {
    config()->set('telegram-alerts.bot_token', '');
    config()->set('telegram-alerts.chat_id', '');

    app()->forgetInstance(TelegramClient::class);
    app()->singleton(TelegramClient::class, fn (): TelegramClient => new TelegramClient(token: '', chatId: ''));

    $this->artisan('telegram:heartbeat')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('skips sending during maintenance mode', function (): void {
    $this->app->maintenanceMode()->activate([]);

    $this->artisan('telegram:heartbeat')
        ->assertSuccessful();

    Http::assertNothingSent();

    $this->app->maintenanceMode()->deactivate();
});
