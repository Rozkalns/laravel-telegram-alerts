<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Support;

use Tests\HostShim;

/**
 * @return array<int, float>|false
 */
function sys_getloadavg(): array|false
{
    return HostShim::$load ?? \sys_getloadavg();
}

/**
 * @return array<string, int>|false
 */
function getrusage(int $mode = 0): array|false
{
    return HostShim::$usage ?? \getrusage($mode);
}
