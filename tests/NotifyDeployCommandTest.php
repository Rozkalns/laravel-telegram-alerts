<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Rozkalns\TelegramAlerts\Jobs\SendTelegramMessageJob;
use Rozkalns\TelegramAlerts\TelegramClient;

beforeEach(function (): void {
    Queue::fake();
    Process::fake([
        'git log -1 --format="%h"' => Process::result('abc1234'),
        'git log -1 --format="%s"' => Process::result('Initial commit'),
    ]);
});

it('sends a deploy notification', function (): void {
    $this->artisan('telegram:notify-deploy')
        ->expectsOutputToContain('Deploy notification sent.')
        ->assertSuccessful();

    Queue::assertPushed(
        SendTelegramMessageJob::class,
        fn (SendTelegramMessageJob $job): bool => str_contains($job->text, 'deployed')
            && str_contains($job->text, 'TestApp')
            && str_contains($job->text, '`abc1234` Initial commit'),
    );
});

it('shows only sha when commit message is empty', function (): void {
    Process::fake([
        'git log -1 --format="%h"' => Process::result('abc1234'),
        'git log -1 --format="%s"' => Process::result(''),
    ]);

    $this->artisan('telegram:notify-deploy')->assertSuccessful();

    Queue::assertPushed(
        SendTelegramMessageJob::class,
        fn (SendTelegramMessageJob $job): bool => str_contains($job->text, '`abc1234`'),
    );
});

it('shows unknown when git output is empty', function (): void {
    Process::fake([
        'git log -1 --format="%h"' => Process::result(''),
        'git log -1 --format="%s"' => Process::result(''),
    ]);

    $this->artisan('telegram:notify-deploy')->assertSuccessful();

    Queue::assertPushed(
        SendTelegramMessageJob::class,
        fn (SendTelegramMessageJob $job): bool => str_contains($job->text, 'unknown'),
    );
});

it('warns when not configured and sends nothing', function (): void {
    config()->set('telegram-alerts.bot_token', '');
    config()->set('telegram-alerts.chat_id', '');

    app()->forgetInstance(TelegramClient::class);
    app()->singleton(TelegramClient::class, fn (): TelegramClient => new TelegramClient(token: '', chatId: ''));

    $this->artisan('telegram:notify-deploy')
        ->expectsOutputToContain('Telegram not configured')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
