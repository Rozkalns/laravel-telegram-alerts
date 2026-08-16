<?php

declare(strict_types=1);

namespace Tests;

/**
 * @see tests/dns_shim.php
 */
final class DnsShim
{
    /** @var list<array<array-key, mixed>>|false */
    public static array|false $records = [];

    /** @var list<array{0: string, 1: int}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$records = [];
        self::$calls = [];
    }
}
