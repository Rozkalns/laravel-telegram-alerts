<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Support;

use Tests\DnsShim;

/**
 * @return list<array<array-key, mixed>>|false
 */
function dns_get_record(string $hostname, int $type): array|false
{
    DnsShim::$calls[] = [$hostname, $type];

    return DnsShim::$records;
}
