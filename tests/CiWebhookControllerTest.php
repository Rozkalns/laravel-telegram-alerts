<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Rozkalns\TelegramAlerts\TelegramClient;

beforeEach(function (): void {
    Http::fake();
    config()->set('telegram-alerts.ci_webhook', true);
    config()->set('telegram-alerts.ci_webhook_secret', 'test-secret');
});

function ciPost(array $payload = []): TestResponse
{
    return test()->postJson('/api/telegram-alerts/ci', $payload, [
        'Authorization' => 'Bearer test-secret',
    ]);
}

it('returns 503 when ci webhook is disabled', function (): void {
    config()->set('telegram-alerts.ci_webhook', false);

    ciPost(['status' => 'success'])
        ->assertStatus(503)
        ->assertJson(['ok' => false, 'error' => 'CI webhook disabled']);

    Http::assertNothingSent();
});

it('returns 503 when telegram is not configured', function (): void {
    config()->set('telegram-alerts.bot_token', '');
    config()->set('telegram-alerts.chat_id', '');

    app()->forgetInstance(TelegramClient::class);
    app()->scoped(TelegramClient::class, fn (): TelegramClient => new TelegramClient(token: '', chatId: ''));

    ciPost(['status' => 'success'])
        ->assertStatus(503)
        ->assertJson(['ok' => false, 'error' => 'Telegram not configured']);

    Http::assertNothingSent();
});

it('sends success notification with correct emoji', function (): void {
    ciPost([
        'status' => 'success',
        'branch' => 'main',
        'commit' => 'fix: tests',
        'actor' => 'Rozkalns',
        'run_url' => 'https://github.com/org/repo/actions/runs/123',
    ])->assertOk()->assertJson(['ok' => true]);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '✅')
        && str_contains((string) $request['text'], 'passed')
        && str_contains((string) $request['text'], 'main')
        && str_contains((string) $request['text'], 'fix: tests')
        && str_contains((string) $request['text'], 'Rozkalns')
        && str_contains((string) $request['text'], 'https://github.com/org/repo/actions/runs/123'));
});

it('sends failure notification with correct emoji', function (): void {
    ciPost([
        'status' => 'failure',
        'branch' => 'feature/test',
        'commit' => 'wip',
        'actor' => 'dev',
    ])->assertOk()->assertJson(['ok' => true]);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '❌')
        && str_contains((string) $request['text'], 'failed'));
});

it('handles missing fields gracefully', function (): void {
    ciPost([])->assertOk()->assertJson(['ok' => true]);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'unknown'));
});

it('omits run url line when empty', function (): void {
    ciPost(['status' => 'success', 'branch' => 'main'])->assertOk();

    Http::assertSent(fn ($request): bool => ! str_contains((string) $request['text'], '🔗'));
});

it('includes app name in message', function (): void {
    ciPost(['status' => 'success'])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'TestApp'));
});
