<?php

declare(strict_types=1);

namespace Tests;

/**
 * @see tests/host_shim.php
 */
final class HostShim
{
    /** @var array<int, float>|false|null */
    public static array|false|null $load = null;

    /** @var array<string, int>|false|null */
    public static array|false|null $usage = null;

    public static function reset(): void
    {
        self::$load = null;
        self::$usage = null;
    }
}
