<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rozkalns\TelegramAlerts\Jobs\SendTelegramMessageJob;
use Rozkalns\TelegramAlerts\TelegramClient;

beforeEach(function (): void {
    Queue::fake();
});

it('sends a heartbeat message when there are queue issues', function (): void {
    Schema::create('jobs', function ($table): void {
        $table->id();
    });
    Schema::create('failed_jobs', function ($table): void {
        $table->id();
    });

    DB::table('failed_jobs')->insert(['id' => 1]);

    $this->artisan('telegram:heartbeat')
        ->assertSuccessful();

    Queue::assertPushed(
        SendTelegramMessageJob::class,
        fn (SendTelegramMessageJob $job): bool => str_contains($job->text, 'Heartbeat')
            && str_contains($job->text, 'TestApp')
            && str_contains($job->text, 'Queue'),
    );
});

it('skips sending when both pending and failed are zero', function (): void {
    $this->artisan('telegram:heartbeat')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('is a no-op when not configured', function (): void {
    config()->set('telegram-alerts.bot_token', '');
    config()->set('telegram-alerts.chat_id', '');

    app()->forgetInstance(TelegramClient::class);
    app()->singleton(TelegramClient::class, fn (): TelegramClient => new TelegramClient(token: '', chatId: ''));

    $this->artisan('telegram:heartbeat')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('skips sending during maintenance mode', function (): void {
    $this->app->maintenanceMode()->activate([]);

    $this->artisan('telegram:heartbeat')
        ->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();

    $this->app->maintenanceMode()->deactivate();
});
