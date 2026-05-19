<?php

declare(strict_types=1);
use Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function (): void {
        config()->set('telegram-alerts.bot_token', 'test-token');
        config()->set('telegram-alerts.chat_id', 'test-chat-id');
        config()->set('app.name', 'TestApp');
        config()->set('app.env', 'testing');
        config()->set('app.url', 'https://test.example.com');
    })
    ->in('.');
