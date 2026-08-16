<?php

declare(strict_types=1);
use Rozkalns\TelegramAlerts\Support\Resolver;
use Tests\FakeResolver;
use Tests\TestCase;

function fakeResolver(): FakeResolver
{
    /** @var FakeResolver $resolver */
    $resolver = app(Resolver::class);

    return $resolver;
}

function googlebotResolver(): FakeResolver
{
    $resolver = fakeResolver();

    $resolver->ptr['38.68.249.66.in-addr.arpa'] = 'crawl-66-249-68-38.googlebot.com';
    $resolver->addresses['crawl-66-249-68-38.googlebot.com'] = ['66.249.68.38'];
    $resolver->txt['38.68.249.66.origin.asn.cymru.com'] = ['15169 | 66.249.68.0/24 | US | arin | 2006-09-13'];
    $resolver->txt['AS15169.asn.cymru.com'] = ['15169 | US | arin | 2000-03-30 | GOOGLE, US'];

    return $resolver;
}

uses(TestCase::class)
    ->beforeEach(function (): void {
        config()->set('telegram-alerts.bot_token', 'test-token');
        config()->set('telegram-alerts.chat_id', 'test-chat-id');
        config()->set('app.name', 'TestApp');
        config()->set('app.env', 'testing');
        config()->set('app.url', 'https://test.example.com');
    })
    ->in('.');
