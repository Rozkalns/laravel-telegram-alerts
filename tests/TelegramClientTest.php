<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Rozkalns\TelegramAlerts\Jobs\SendTelegramMessageJob;
use Rozkalns\TelegramAlerts\TelegramClient;

it('sends a message to the telegram api', function (): void {
    Http::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->once()->with('Telegram alert sent', ['text' => 'Hello world']);
    Log::shouldReceive('channel')->with('single')->andReturn($logger);

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

it('retries on 429 and respects retry-after header', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->once();
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    Http::fake([
        'api.telegram.org/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '2'])
            ->push('', 200),
    ]);

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('retries on 5xx with exponential backoff', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->once();
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    Http::fake([
        'api.telegram.org/*' => Http::sequence()
            ->push('', 500)
            ->push('', 502)
            ->push('', 200),
    ]);

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds()]);
});

it('does not retry on 4xx client error', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('warning')->once();
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    Http::fake([
        'api.telegram.org/*' => Http::response('', 400),
    ]);

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');

    Http::assertSentCount(1);
    Sleep::assertSleptTimes(0);
});

it('does not retry on 401 unauthorized', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('warning')->once();
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    Http::fake([
        'api.telegram.org/*' => Http::response('', 401),
    ]);

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');

    Http::assertSentCount(1);
    Sleep::assertSleptTimes(0);
});

it('retries on network exception', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->once();
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    $callCount = 0;
    Http::fake(function () use (&$callCount) {
        $callCount++;
        if ($callCount < 3) {
            throw new RuntimeException('Connection failed');
        }

        return Http::response('', 200);
    });

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');

    expect($callCount)->toBe(3);
    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(1)->seconds()]);
});

it('logs warning when all retries exhausted on 5xx', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('warning')->once();
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    Http::fake([
        'api.telegram.org/*' => Http::sequence()
            ->push('', 500)
            ->push('', 500)
            ->push('', 500),
    ]);

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');

    Http::assertSentCount(3);
});

it('logs warning when all retries exhausted on network exception', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('warning')->once();
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    Http::fake(fn () => throw new RuntimeException('Connection failed'));

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');
});

it('logs warning when 429 exhausts retries', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('warning')->once();
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    Http::fake([
        'api.telegram.org/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '1'])
            ->push('', 429, ['Retry-After' => '1'])
            ->push('', 429, ['Retry-After' => '1']),
    ]);

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');

    Http::assertSentCount(3);
});

it('caps retry-after at 5 seconds', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->once();
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    Http::fake([
        'api.telegram.org/*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '30'])
            ->push('', 200),
    ]);

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');

    Sleep::assertSequence([Sleep::for(5)->seconds()]);
});

it('truncates logged text to 200 characters', function (): void {
    Http::fake();
    $longText = str_repeat('A', 300);
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->once()->with('Telegram alert sent', ['text' => str_repeat('A', 200)]);
    Log::shouldReceive('channel')->with('single')->andReturn($logger);

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send($longText);
});

it('logs warning with text preview on failure', function (): void {
    Sleep::fake();
    $logger = Mockery::mock();
    $logger->shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Telegram alert delivery failed'
        && $context['text'] === 'Hello'
        && $context['status'] === 400);
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    Http::fake([
        'api.telegram.org/*' => Http::response('', 400),
    ]);

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->send('Hello');
});

it('dispatches a job via sendQueued', function (): void {
    Queue::fake();

    $client = new TelegramClient(token: 'bot-token', chatId: '12345');
    $client->sendQueued('Hello from queue');

    Queue::assertPushed(
        SendTelegramMessageJob::class,
        fn (SendTelegramMessageJob $job): bool => $job->text === 'Hello from queue',
    );
});

it('sendQueued is a no-op when not configured', function (): void {
    Queue::fake();

    $client = new TelegramClient(token: '', chatId: '');
    $client->sendQueued('Hello');

    Queue::assertNothingPushed();
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
