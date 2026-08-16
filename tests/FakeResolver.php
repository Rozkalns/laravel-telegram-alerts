<?php

declare(strict_types=1);

namespace Tests;

use Rozkalns\TelegramAlerts\Support\Resolver;
use RuntimeException;

final class FakeResolver implements Resolver
{
    /** @var array<string, string> */
    public array $ptr = [];

    /** @var array<string, list<string>> */
    public array $txt = [];

    /** @var array<string, list<string>> */
    public array $addresses = [];

    public bool $throws = false;

    /**
     * @var array<string, int>
     */
    public array $delays = [];

    /** @var list<string> */
    public array $queries = [];

    public function ptr(string $name): ?string
    {
        $this->record($name);

        return $this->ptr[$name] ?? null;
    }

    /** @return list<string> */
    public function txt(string $name): array
    {
        $this->record($name);

        return $this->txt[$name] ?? [];
    }

    /** @return list<string> */
    public function addresses(string $host, bool $ipv6 = false): array
    {
        $this->record($host);

        return $this->addresses[$host] ?? [];
    }

    private function record(string $name): void
    {
        if ($this->throws) {
            throw new RuntimeException('resolver exploded');
        }

        $this->queries[] = $name;

        if (isset($this->delays[$name])) {
            usleep($this->delays[$name]);
        }
    }
}
