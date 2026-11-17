<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Rozkalns\TelegramAlerts\Jobs\SendTelegramMessageJob;
use Rozkalns\TelegramAlerts\TelegramClient;
use Rozkalns\TelegramAlerts\TelegramMessage;

beforeEach(function (): void {
    $this->travelTo('2026-10-13 06:00:00');
});

it('renders the header, body and context footer', function (): void {
    $text = TelegramMessage::make('⚠️', 'Something happened')
        ->line('first')
        ->lines(['second', 'third'])
        ->render();

    expect($text)->toBe(implode("\n", [
        '⚠️ <b>[TestApp]</b> Something happened',
        '',
        'first',
        'second',
        'third',
        '',
        '📍 https://test.example.com (testing)',
        '🕐 2026-10-13 06:00:00 UTC',
    ]));
});

it('escapes the app name, which is not HTML', function (): void {
    config()->set('app.name', 'A <b>&</b> B');

    expect(TelegramMessage::make('✅', 'done')->withoutContext()->render())
        ->toBe('✅ <b>[A &lt;b&gt;&amp;&lt;/b&gt; B]</b> done');
});

it('drops blank lines at the edges of the body so spacing stays uniform', function (): void {
    $text = TelegramMessage::make('✅', 'done')->line()->line('only')->line()->withoutContext()->render();

    expect($text)->toBe("✅ <b>[TestApp]</b> done\n\nonly");
});

it('renders just the header when there is no body and no footer', function (): void {
    expect(TelegramMessage::make('✅', 'done')->withoutContext()->render())->toBe('✅ <b>[TestApp]</b> done');
});

it('reports the given moment instead of now', function (): void {
    $text = TelegramMessage::make('🚨', 'ERROR')->at(Carbon::parse('2026-01-02 03:04:05'))->render();

    expect($text)->toEndWith('🕐 2026-01-02 03:04:05 UTC');
});

it('shows the package release', function (): void {
    config()->set('telegram-alerts.release', 'abc1234');
    config()->set('sentry.release', 'ignored');

    expect(TelegramMessage::context())->toContain('🏷️ <code>abc1234</code>');
});

it('falls back to the release Sentry derives from git', function (): void {
    config()->set('telegram-alerts.release');
    config()->set('sentry.release', 'def5678');

    expect(TelegramMessage::release())->toBe('def5678');
});

it('leaves the release out when none is known', function (): void {
    config()->set('telegram-alerts.release', '');
    config()->set('sentry.release');

    expect(TelegramMessage::release())->toBeNull()
        ->and(TelegramMessage::context())->toHaveCount(2);
});

it('shows the request ID from the context, between the release and the time', function (): void {
    config()->set('telegram-alerts.release', 'abc1234');
    Context::add('request_id', 'SRVAPP-K7Q2M9X4TB');

    expect(TelegramMessage::context())->toBe([
        '📍 https://test.example.com (testing)',
        '🏷️ <code>abc1234</code>',
        '🔎 <code>SRVAPP-K7Q2M9X4TB</code>',
        '🕐 2026-10-13 06:00:00 UTC',
    ]);
});

it('leaves the request ID out when the app sets none', function (mixed $value): void {
    config()->set('telegram-alerts.release', '');
    config()->set('sentry.release');
    Context::add('request_id', $value);

    expect(TelegramMessage::requestId())->toBeNull()
        ->and(TelegramMessage::context())->toHaveCount(2);
})->with([
    'empty' => '',
    'not a string' => 42,
    'null' => null,
]);

it('escapes the request ID, which comes from app code', function (): void {
    Context::add('request_id', '<b>x</b>');

    expect(TelegramMessage::context())->toContain('🔎 <code>&lt;b&gt;x&lt;/b&gt;</code>');
});

it('puts the request ID on an error alert, so the log line and the alert match', function (): void {
    Http::fake();
    Context::add('request_id', 'SRVAPP-K7Q2M9X4TB');

    resolve(TelegramClient::class)->send(TelegramMessage::make('🚨', 'ERROR')->line('boom'));

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '🔎 <code>SRVAPP-K7Q2M9X4TB</code>'));
});

it('is accepted by the client directly', function (): void {
    Http::fake();

    app(TelegramClient::class)->send(TelegramMessage::make('✅', 'sent'));

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request['text'], '✅ <b>[TestApp]</b> sent'));
});

it('is rendered before it is queued, so the footer reports when it happened', function (): void {
    Queue::fake();

    app(TelegramClient::class)->sendQueued(TelegramMessage::make('✅', 'queued'));

    Queue::assertPushed(
        SendTelegramMessageJob::class,
        fn (SendTelegramMessageJob $job): bool => str_starts_with($job->text, '✅ <b>[TestApp]</b> queued')
            && str_ends_with($job->text, '🕐 2026-10-13 06:00:00 UTC'),
    );
});
