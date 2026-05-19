<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rozkalns\TelegramAlerts\TelegramClient;

it('sends a message to the telegram api', function (): void {
    Http::fake();

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello world');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/botbot-token/sendMessage'
        && $request['chat_id'] === '12345'
        && $request['text'] === 'Hello world'
        && $request['parse_mode'] === 'Markdown'
        && $request['disable_web_page_preview'] === true);
});

it('is a no-op when token is empty', function (): void {
    Http::fake();

    $client = new TelegramClient(token: '', chatId: '12345');
    $client->send('Hello');

    Http::assertNothingSent();
});

it('is a no-op when chat id is empty', function (): void {
    Http::fake();

    $client = new TelegramClient(token: 'bot-token', chatId: '');
    $client->send('Hello');

    Http::assertNothingSent();
});

it('swallows http exceptions silently', function (): void {
    Http::fake(fn () => throw new RuntimeException('Connection failed'));

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');

    expect(true)->toBeTrue();
});

it('reports configured when both token and chat id are set', function (): void {
    $client = new TelegramClient(token: 'bot-token', chatId: '12345');

    expect($client->isConfigured())->toBeTrue();
});

it('reports not configured when token is empty', function (): void {
    $client = new TelegramClient(token: '', chatId: '12345');

    expect($client->isConfigured())->toBeFalse();
});

it('reports not configured when chat id is empty', function (): void {
    $client = new TelegramClient(token: 'bot-token', chatId: '');

    expect($client->isConfigured())->toBeFalse();
});
