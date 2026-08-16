<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Support;

/**
 * @see https://www.cloudflare.com/ips/
 */
final class Cloudflare
{
    /** @var list<string> */
    private const array RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function contains(string $ip): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }

        return array_any(self::RANGES, fn (string $range): bool => self::matches($address, $range));
    }

    private static function matches(string $address, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);

        $network = @inet_pton($subnet);
        if ($network === false || strlen($network) !== strlen($address)) {
            return false;
        }

        $prefix = (int) $bits;
        $wholeBytes = intdiv($prefix, 8);

        if ($wholeBytes > 0 && strncmp($address, $network, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
