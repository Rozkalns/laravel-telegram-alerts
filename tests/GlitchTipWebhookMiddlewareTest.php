<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rozkalns\TelegramAlerts\Http\GlitchTipWebhookMiddleware;

beforeEach(function (): void {
    config()->set('telegram-alerts.glitchtip_webhook_secret', 'test-secret');
    Route::middleware(GlitchTipWebhookMiddleware::class)->post('/test-glitchtip-auth', fn (): string => 'ok');
});

it('returns 401 when no token query param is present', function (): void {
    $this->postJson('/test-glitchtip-auth')
        ->assertStatus(401)
        ->assertJson(['ok' => false, 'error' => 'Unauthorized']);
});

it('returns 401 when token does not match', function (): void {
    $this->postJson('/test-glitchtip-auth?token=wrong-token')
        ->assertStatus(401)
        ->assertJson(['ok' => false, 'error' => 'Unauthorized']);
});

it('returns 401 when secret is empty', function (): void {
    config()->set('telegram-alerts.glitchtip_webhook_secret', '');

    $this->postJson('/test-glitchtip-auth?token=anything')
        ->assertStatus(401)
        ->assertJson(['ok' => false, 'error' => 'Unauthorized']);
});

it('passes through when token matches', function (): void {
    $this->postJson('/test-glitchtip-auth?token=test-secret')
        ->assertOk()
        ->assertSee('ok');
});
