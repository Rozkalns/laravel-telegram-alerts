<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Rozkalns\TelegramAlerts\TelegramClient;

beforeEach(function (): void {
    Http::fake();
    config()->set('telegram-alerts.glitchtip_webhook', true);
    config()->set('telegram-alerts.glitchtip_webhook_secret', 'test-secret');
});

function glitchtipPost(array $payload = []): TestResponse
{
    return test()->postJson('/api/telegram-alerts/glitchtip?token=test-secret', $payload);
}

it('returns 503 when glitchtip webhook is disabled', function (): void {
    config()->set('telegram-alerts.glitchtip_webhook', false);

    glitchtipPost(['attachments' => [['title' => 'X', 'title_link' => 'https://gt/1']]])
        ->assertStatus(503)
        ->assertJson(['ok' => false, 'error' => 'GlitchTip webhook disabled']);

    Http::assertNothingSent();
});

it('returns 503 when telegram is not configured', function (): void {
    config()->set('telegram-alerts.bot_token', '');
    config()->set('telegram-alerts.chat_id', '');

    app()->forgetInstance(TelegramClient::class);
    app()->scoped(TelegramClient::class, fn (): TelegramClient => new TelegramClient(token: '', chatId: ''));

    glitchtipPost(['attachments' => [['title' => 'X', 'title_link' => 'https://gt/1']]])
        ->assertStatus(503)
        ->assertJson(['ok' => false, 'error' => 'Telegram not configured']);

    Http::assertNothingSent();
});

it('sends a full issue message with link, culprit, context, short id, and button', function (): void {
    glitchtipPost([
        'attachments' => [[
            'title' => 'ZeroDivisionError: division by zero',
            'title_link' => 'https://app.glitchtip.com/org/issues/42',
            'text' => 'trigger_error',
            'fields' => [
                ['title' => 'Project', 'value' => 'myproject', 'short' => true],
                ['title' => 'Environment', 'value' => 'production', 'short' => true],
                ['title' => 'Release', 'value' => 'abc123', 'short' => false],
            ],
        ]],
        'sections' => [
            ['activitySubtitle' => '[View Issue PROJ-1](https://app.glitchtip.com/org/issues/42)'],
        ],
    ])->assertOk()->assertJson(['ok' => true]);

    Http::assertSent(function (Request $request): bool {
        $text = (string) $request['text'];

        return str_contains($text, '🐞 <b>[TestApp]</b> GlitchTip issue')
            && str_contains($text, '<code>PROJ-1</code>')
            && str_contains($text, '<a href="https://app.glitchtip.com/org/issues/42">ZeroDivisionError: division by zero</a>')
            && str_contains($text, '📄 trigger_error')
            && str_contains($text, '📍 production · abc123')
            && $request['reply_markup'] === [
                'inline_keyboard' => [[
                    ['text' => '🔍 Open in GlitchTip', 'url' => 'https://app.glitchtip.com/org/issues/42'],
                ]],
            ];
    });
});

it('sends a minimal issue message without optional lines', function (): void {
    glitchtipPost([
        'attachments' => [['title' => 'BoomError', 'title_link' => 'https://gt/issues/7']],
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        $text = (string) $request['text'];

        return str_contains($text, '<a href="https://gt/issues/7">BoomError</a>')
            && ! str_contains($text, '📄')
            && ! str_contains($text, '📍')
            && ! str_contains($text, '<code>');
    });
});

it('sends one message per attachment', function (): void {
    glitchtipPost([
        'attachments' => [
            ['title' => 'First', 'title_link' => 'https://gt/1'],
            ['title' => 'Second', 'title_link' => 'https://gt/2'],
        ],
    ])->assertOk();

    Http::assertSentCount(2);
});

it('skips attachments without a title link', function (): void {
    glitchtipPost([
        'attachments' => [['title' => 'NoLink', 'text' => 'whatever']],
    ])->assertOk()->assertJson(['ok' => true]);

    Http::assertNothingSent();
});

it('falls back to a generic title when the title is missing', function (): void {
    glitchtipPost([
        'attachments' => [['title_link' => 'https://gt/issues/9']],
    ])->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], '<a href="https://gt/issues/9">GlitchTip issue</a>'));
});

it('sends nothing for a payload without attachments', function (): void {
    glitchtipPost([])->assertOk()->assertJson(['ok' => true]);

    Http::assertNothingSent();
});

it('ignores non-array entries within the attachments array', function (): void {
    glitchtipPost([
        'attachments' => [
            'not-an-array',
            ['title' => 'Valid', 'title_link' => 'https://gt/1'],
        ],
    ])->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Valid'));
});

it('ignores non-array entries within fields and sections', function (): void {
    glitchtipPost([
        'attachments' => [[
            'title' => 'E',
            'title_link' => 'https://gt/2',
            'fields' => [
                'not-an-array',
                ['title' => 'Environment', 'value' => 'staging'],
            ],
        ]],
        'sections' => [
            'not-an-array',
            ['activitySubtitle' => 'no issue reference here'],
        ],
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        $text = (string) $request['text'];

        return str_contains($text, '📍 staging')
            && ! str_contains($text, '<code>');
    });
});

it('escapes html-special characters in dynamic values', function (): void {
    glitchtipPost([
        'attachments' => [[
            'title' => '<script> & "evil"',
            'title_link' => 'https://gt/3?a=1&b=2',
            'text' => 'foo <bar>',
            'fields' => [['title' => 'Environment', 'value' => '<prod>']],
        ]],
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        $text = (string) $request['text'];

        return $request['parse_mode'] === 'HTML'
            && str_contains($text, '&lt;script&gt;')
            && str_contains($text, '&amp;')
            && str_contains($text, '&lt;prod&gt;')
            && ! str_contains($text, '<script>')
            && $request['reply_markup'] === [
                'inline_keyboard' => [[
                    ['text' => '🔍 Open in GlitchTip', 'url' => 'https://gt/3?a=1&b=2'],
                ]],
            ];
    });
});
