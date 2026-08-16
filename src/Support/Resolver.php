<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Support;

interface Resolver
{
    public function ptr(string $name): ?string;

    /**
     * @return list<string>
     */
    public function txt(string $name): array;

    /**
     * @return list<string>
     */
    public function addresses(string $host): array;
}
