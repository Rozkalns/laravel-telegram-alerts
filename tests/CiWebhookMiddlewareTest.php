<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rozkalns\TelegramAlerts\Http\CiWebhookMiddleware;

beforeEach(function (): void {
    config()->set('telegram-alerts.ci_webhook_secret', 'test-secret');
    Route::middleware(CiWebhookMiddleware::class)->get('/test-ci-auth', fn (): string => 'ok');
});

it('returns 401 when no authorization header is present', function (): void {
    $this->getJson('/test-ci-auth')
        ->assertStatus(401)
        ->assertJson(['ok' => false, 'error' => 'Unauthorized']);
});

it('returns 401 when token does not match', function (): void {
    $this->getJson('/test-ci-auth', ['Authorization' => 'Bearer wrong-token'])
        ->assertStatus(401)
        ->assertJson(['ok' => false, 'error' => 'Unauthorized']);
});

it('returns 401 when secret is empty', function (): void {
    config()->set('telegram-alerts.ci_webhook_secret', '');

    $this->getJson('/test-ci-auth', ['Authorization' => 'Bearer anything'])
        ->assertStatus(401)
        ->assertJson(['ok' => false, 'error' => 'Unauthorized']);
});

it('passes through when token matches', function (): void {
    $this->getJson('/test-ci-auth', ['Authorization' => 'Bearer test-secret'])
        ->assertOk()
        ->assertSee('ok');
});
