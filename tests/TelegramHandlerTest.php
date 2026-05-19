<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use Rozkalns\TelegramAlerts\TelegramClient;
use Rozkalns\TelegramAlerts\TelegramHandler;

beforeEach(function (): void {
    Http::fake();
    Cache::flush();
});

function makeLogRecord(string $message = 'Test error', array $context = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Error,
        message: $message,
        context: $context,
    );
}

it('sends an error log message to telegram', function (): void {
    $client = app(TelegramClient::class);
    $handler = new TelegramHandler($client);

    $handler->handle(makeLogRecord('Something went wrong'));

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Something went wrong')
        && str_contains((string) $request['text'], 'TestApp')
        && str_contains((string) $request['text'], 'Error'));
});

it('rate limits duplicate messages', function (): void {
    $client = app(TelegramClient::class);
    $handler = new TelegramHandler($client);

    $handler->handle(makeLogRecord('Duplicate error'));
    $handler->handle(makeLogRecord('Duplicate error'));

    Http::assertSentCount(1);
});

it('allows different messages through', function (): void {
    $client = app(TelegramClient::class);
    $handler = new TelegramHandler($client);

    $handler->handle(makeLogRecord('Error one'));
    $handler->handle(makeLogRecord('Error two'));

    Http::assertSentCount(2);
});

it('truncates long messages', function (): void {
    $client = app(TelegramClient::class);
    $handler = new TelegramHandler($client);

    $handler->handle(makeLogRecord(str_repeat('x', 4000)));

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '… (truncated)'));
});

it('includes exception file and line when context has exception', function (): void {
    $client = app(TelegramClient::class);
    $handler = new TelegramHandler($client);

    $exception = new RuntimeException('Boom');
    $handler->handle(makeLogRecord('Error with exception', ['exception' => $exception]));

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'RuntimeException')
        && str_contains((string) $request['text'], '📄'));
});

it('is a no-op when client is not configured', function (): void {
    $client = new TelegramClient(token: '', chatId: '');
    $handler = new TelegramHandler($client);

    $handler->handle(makeLogRecord('Should not send'));

    Http::assertNothingSent();
});

it('includes app url and environment in the message', function (): void {
    $client = app(TelegramClient::class);
    $handler = new TelegramHandler($client);

    $handler->handle(makeLogRecord('Test'));

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'https://test.example.com')
        && str_contains((string) $request['text'], 'testing'));
});
