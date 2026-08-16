<?php

declare(strict_types=1);

use Rozkalns\TelegramAlerts\Support\Cloudflare;

it('recognises ipv4 addresses inside published ranges', function (string $ip): void {
    expect(Cloudflare::contains($ip))->toBeTrue();
})->with([
    '173.245.48.1',
    '173.245.63.255',
    '104.16.0.0',
    '162.158.255.255',
    '131.0.75.7',
]);

it('rejects ipv4 addresses just outside a range boundary', function (string $ip): void {
    expect(Cloudflare::contains($ip))->toBeFalse();
})->with([
    '173.245.47.255',
    '173.245.64.0',
    '131.0.76.0',
    '66.249.68.38',
    '8.8.8.8',
    '127.0.0.1',
]);

it('recognises ipv6 addresses inside published ranges', function (string $ip): void {
    expect(Cloudflare::contains($ip))->toBeTrue();
})->with([
    '2400:cb00::1',
    '2606:4700:4700::1111',
    '2a06:98c0:0000::1',
]);

it('rejects ipv6 addresses outside published ranges', function (string $ip): void {
    expect(Cloudflare::contains($ip))->toBeFalse();
})->with([
    '2001:4860:4860::8888',
    '2a07:98c0::1',
    '::1',
]);

it('never matches a v4 address against a v6 range or vice versa', function (): void {
    expect(Cloudflare::contains('36.0.203.0'))->toBeFalse();
});

it('returns false for anything that is not an ip address', function (string $value): void {
    expect(Cloudflare::contains($value))->toBeFalse();
})->with([
    'unknown',
    '',
    'not-an-ip',
    '999.999.999.999',
]);
