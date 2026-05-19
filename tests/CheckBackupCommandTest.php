<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rozkalns\TelegramAlerts\TelegramClient;

beforeEach(function (): void {
    Http::fake();
});

it('passes when no backup path is configured', function (): void {
    config()->set('telegram-alerts.backup_path', '');

    $this->artisan('telegram:check-backup')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('passes when backup file exists and is recent', function (): void {
    $dir = sys_get_temp_dir().'/telegram-backup-test-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/database.sqlite', str_repeat('x', 2048));
    touch($dir.'/database.sqlite', time() - 3600);

    config()->set('telegram-alerts.backup_path', $dir.'/database.sqlite');

    $this->artisan('telegram:check-backup')
        ->expectsOutputToContain('Backup check passed')
        ->assertSuccessful();

    Http::assertNothingSent();

    unlink($dir.'/database.sqlite');
    rmdir($dir);
});

it('alerts when no backup files found', function (): void {
    config()->set('telegram-alerts.backup_path', '/tmp/nonexistent-backup-*.sqlite');

    $this->artisan('telegram:check-backup')
        ->assertFailed();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Backup check failed')
        && str_contains((string) $request['text'], 'No backup files found'));
});

it('alerts when backup is too old', function (): void {
    $dir = sys_get_temp_dir().'/telegram-backup-test-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/database.sqlite', str_repeat('x', 2048));
    touch($dir.'/database.sqlite', time() - (30 * 3600));

    config()->set('telegram-alerts.backup_path', $dir.'/database.sqlite');
    config()->set('telegram-alerts.backup_max_age_hours', 25);

    $this->artisan('telegram:check-backup')
        ->assertFailed();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Backup check failed')
        && str_contains((string) $request['text'], 'No backup file modified'));

    unlink($dir.'/database.sqlite');
    rmdir($dir);
});

it('alerts when backup is suspiciously small', function (): void {
    $dir = sys_get_temp_dir().'/telegram-backup-test-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/database.sqlite', 'tiny');
    touch($dir.'/database.sqlite', time() - 60);

    config()->set('telegram-alerts.backup_path', $dir.'/database.sqlite');
    config()->set('telegram-alerts.backup_min_size_bytes', 1024);

    $this->artisan('telegram:check-backup')
        ->assertFailed();

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Backup check failed')
        && str_contains((string) $request['text'], 'suspiciously small'));

    unlink($dir.'/database.sqlite');
    rmdir($dir);
});

it('supports glob patterns', function (): void {
    $dir = sys_get_temp_dir().'/telegram-backup-test-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/database.backup-20260519.sqlite', str_repeat('x', 2048));
    touch($dir.'/database.backup-20260519.sqlite', time() - 3600);

    config()->set('telegram-alerts.backup_path', $dir.'/database.backup-*.sqlite');

    $this->artisan('telegram:check-backup')
        ->expectsOutputToContain('Backup check passed')
        ->assertSuccessful();

    Http::assertNothingSent();

    unlink($dir.'/database.backup-20260519.sqlite');
    rmdir($dir);
});

it('rejects paths with directory traversal', function (): void {
    config()->set('telegram-alerts.backup_path', '/tmp/../etc/passwd');

    $this->artisan('telegram:check-backup')
        ->expectsOutputToContain('must not contain')
        ->assertFailed();

    Http::assertNothingSent();
});

it('is a no-op when client is not configured', function (): void {
    config()->set('telegram-alerts.backup_path', '/tmp/nonexistent-backup-*.sqlite');
    config()->set('telegram-alerts.bot_token', '');
    config()->set('telegram-alerts.chat_id', '');

    app()->forgetInstance(TelegramClient::class);
    app()->singleton(TelegramClient::class, fn (): TelegramClient => new TelegramClient(token: '', chatId: ''));

    $this->artisan('telegram:check-backup')
        ->assertSuccessful();

    Http::assertNothingSent();
});
