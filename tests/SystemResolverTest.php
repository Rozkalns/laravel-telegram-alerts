<?php

declare(strict_types=1);

use Rozkalns\TelegramAlerts\Support\SystemResolver;
use Tests\DnsShim;

beforeEach(fn () => DnsShim::reset());

afterEach(fn () => DnsShim::reset());

it('reads a ptr target and strips the trailing dot', function (): void {
    DnsShim::$records = [
        ['host' => '38.68.249.66.in-addr.arpa', 'type' => 'PTR', 'target' => 'crawl-66-249-68-38.googlebot.com.'],
    ];

    expect(new SystemResolver()->ptr('38.68.249.66.in-addr.arpa'))
        ->toBe('crawl-66-249-68-38.googlebot.com')
        ->and(DnsShim::$calls)->toBe([['38.68.249.66.in-addr.arpa', DNS_PTR]]);
});

it('skips ptr records without a usable target', function (): void {
    DnsShim::$records = [
        ['type' => 'PTR'],
        ['type' => 'PTR', 'target' => ''],
        ['type' => 'PTR', 'target' => 123],
        ['type' => 'PTR', 'target' => 'real.example.com'],
    ];

    expect(new SystemResolver()->ptr('whatever'))->toBe('real.example.com');
});

it('returns null when there is no ptr record', function (): void {
    expect(new SystemResolver()->ptr('nothing.example'))->toBeNull();
});

it('returns null when the ptr query fails outright', function (): void {
    DnsShim::$records = false;

    expect(new SystemResolver()->ptr('nothing.example'))->toBeNull();
});

it('collects txt values', function (): void {
    DnsShim::$records = [
        ['type' => 'TXT', 'txt' => '15169 | 66.249.68.0/24 | US | arin | 2006-09-13'],
        ['type' => 'TXT', 'txt' => ''],
        ['type' => 'TXT'],
        ['type' => 'TXT', 'txt' => ['not', 'a', 'string']],
    ];

    expect(new SystemResolver()->txt('origin.asn.cymru.com'))
        ->toBe(['15169 | 66.249.68.0/24 | US | arin | 2006-09-13'])
        ->and(DnsShim::$calls)->toBe([['origin.asn.cymru.com', DNS_TXT]]);
});

it('returns no txt values when the query fails', function (): void {
    DnsShim::$records = false;

    expect(new SystemResolver()->txt('origin.asn.cymru.com'))->toBe([]);
});

it('asks only for the address family it was given', function (): void {
    DnsShim::$records = [
        ['type' => 'A', 'ip' => '66.249.68.38'],
        ['type' => 'A', 'ip' => ''],
        ['type' => 'A'],
    ];

    expect(new SystemResolver()->addresses('crawl.googlebot.com'))
        ->toBe(['66.249.68.38'])
        ->and(DnsShim::$calls)->toBe([['crawl.googlebot.com', DNS_A]]);
});

it('asks for aaaa records when confirming an ipv6 caller', function (): void {
    DnsShim::$records = [
        ['type' => 'AAAA', 'ipv6' => '2001:db8::1'],
    ];

    expect(new SystemResolver()->addresses('v6.example.org', true))
        ->toBe(['2001:db8::1'])
        ->and(DnsShim::$calls)->toBe([['v6.example.org', DNS_AAAA]]);
});

it('returns no addresses when the forward query fails', function (): void {
    DnsShim::$records = false;

    expect(new SystemResolver()->addresses('crawl.googlebot.com'))->toBe([]);
});
