<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Support;

interface Host
{
    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    public function loadAverage(): ?array;

    public function cpuCount(): ?int;

    /**
     * @return array{total: int, available: int}|null bytes
     */
    public function memory(): ?array;

    public function cpuTimeMs(): ?float;

    /**
     * @return array{total: int, steal: int}|null
     */
    public function cpuTicks(): ?array;

    public function peakMemory(): int;
}
