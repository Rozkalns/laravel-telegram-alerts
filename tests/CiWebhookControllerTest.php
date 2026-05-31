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
        'sha' => 'a6aa687f1234567890abcdef',
        'commit' => 'fix: tests',
        'actor' => 'Rozkalns',
        'run_url' => 'https://github.com/org/repo/actions/runs/123',
    ])->assertOk()->assertJson(['ok' => true]);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], '✅')
        && str_contains((string) $request['text'], 'passed')
        && str_contains((string) $request['text'], '`a6aa687` fix: tests')
        && str_contains((string) $request['text'], 'Branch: `main` · Actor: `Rozkalns`')
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

it('renders the jobs line and total duration', function (): void {
    ciPost([
        'status' => 'success',
        'branch' => 'main',
        'sha' => 'a6aa687f1234567890abcdef',
        'commit' => 'fix: tests',
        'actor' => 'Rozkalns',
        'run_url' => 'https://github.com/org/repo/actions/runs/123',
        'duration' => 130,
        'jobs' => [
            ['name' => 'lint', 'conclusion' => 'success', 'duration' => 23],
            ['name' => 'tests', 'conclusion' => 'success', 'duration' => 107],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'lint ✅ 23s · tests ✅ 1m 47s')
        && str_contains((string) $request['text'], '⏱️ total 2m 10s'));
});

it('marks a failed job with a cross', function (): void {
    ciPost([
        'status' => 'failure',
        'duration' => 60,
        'jobs' => [
            ['name' => 'lint', 'conclusion' => 'success', 'duration' => 20],
            ['name' => 'tests', 'conclusion' => 'failure', 'duration' => 40],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'lint ✅ 20s · tests ❌ 40s'));
});

it('omits jobs and total lines when not provided', function (): void {
    ciPost([
        'status' => 'success',
        'branch' => 'main',
        'actor' => 'Rozkalns',
    ])->assertOk();

    Http::assertSent(fn ($request): bool => ! str_contains((string) $request['text'], '⏱️')
        && ! str_contains((string) $request['text'], ' · tests'));
});

it('ignores malformed jobs payload', function (): void {
    ciPost([
        'status' => 'success',
        'jobs' => 'not-an-array',
    ])->assertOk();

    Http::assertSent(fn ($request): bool => ! str_contains((string) $request['text'], '✅ 0s'));
});

it('skips job entries without a name', function (): void {
    ciPost([
        'status' => 'success',
        'jobs' => [
            ['conclusion' => 'success', 'duration' => 10],
            ['name' => 'build', 'conclusion' => 'success', 'duration' => 5],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'build ✅ 5s')
        && ! str_contains((string) $request['text'], ' ✅ 10s'));
});

it('skips non-array entries within jobs array', function (): void {
    ciPost([
        'status' => 'success',
        'jobs' => [
            'not-an-array-entry',
            ['name' => 'build', 'conclusion' => 'success', 'duration' => 5],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'build ✅ 5s'));
});

it('clamps negative durations to zero', function (): void {
    ciPost([
        'status' => 'success',
        'duration' => -5,
        'jobs' => [
            ['name' => 'x', 'conclusion' => 'success', 'duration' => -3],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'x ✅ 0s')
        && str_contains((string) $request['text'], '⏱️ total 0s'));
});

it('formats durations across seconds, minutes, and hours', function (): void {
    ciPost([
        'status' => 'success',
        'duration' => 45,
        'jobs' => [
            ['name' => 'a', 'conclusion' => 'success', 'duration' => 60],
            ['name' => 'b', 'conclusion' => 'success', 'duration' => 127],
            ['name' => 'c', 'conclusion' => 'success', 'duration' => 3600],
            ['name' => 'd', 'conclusion' => 'success', 'duration' => 3780],
        ],
    ])->assertOk();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'a ✅ 1m')
        && str_contains((string) $request['text'], 'b ✅ 2m 7s')
        && str_contains((string) $request['text'], 'c ✅ 1h')
        && str_contains((string) $request['text'], 'd ✅ 1h 3m')
        && str_contains((string) $request['text'], '⏱️ total 45s'));
});
