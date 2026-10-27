<?php

declare(strict_types=1);

namespace Tests;

use Rozkalns\TelegramAlerts\Support\Host;

final class FakeHost implements Host
{
    /** @var array{0: float, 1: float, 2: float}|null */
    public ?array $load = [1.5, 0.75, 0.5];

    public ?int $cores = 4;

    /** @var array{total: int, available: int}|null */
    public ?array $memory = ['total' => 8 * 1024 ** 3, 'available' => 2 * 1024 ** 3];

    /** @var list<float|null> */
    public array $cpuTimes = [100.0, 1_950.0];

    /** @var list<array{total: int, steal: int}|null> */
    public array $ticks = [['total' => 1_000, 'steal' => 10], ['total' => 1_400, 'steal' => 58]];

    public int $peak = 34 * 1024 ** 2;

    /** @return array{0: float, 1: float, 2: float}|null */
    public function loadAverage(): ?array
    {
        return $this->load;
    }

    public function cpuCount(): ?int
    {
        return $this->cores;
    }

    /** @return array{total: int, available: int}|null */
    public function memory(): ?array
    {
        return $this->memory;
    }

    public function cpuTimeMs(): ?float
    {
        return $this->cpuTimes === [] ? null : array_shift($this->cpuTimes);
    }

    /** @return array{total: int, steal: int}|null */
    public function cpuTicks(): ?array
    {
        return $this->ticks === [] ? null : array_shift($this->ticks);
    }

    public function peakMemory(): int
    {
        return $this->peak;
    }
}
