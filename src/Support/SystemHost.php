<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Support;

final readonly class SystemHost implements Host
{
    public function __construct(
        private string $cpuInfoPath = '/proc/cpuinfo',
        private string $memoryInfoPath = '/proc/meminfo',
        private string $statPath = '/proc/stat',
    ) {}

    /** @return array{0: float, 1: float, 2: float}|null */
    public function loadAverage(): ?array
    {
        $load = sys_getloadavg();
        if ($load === false || count($load) < 3) {
            return null;
        }

        return [(float) $load[0], (float) $load[1], (float) $load[2]];
    }

    public function cpuCount(): ?int
    {
        $count = preg_match_all('/^processor\s*:/m', $this->read($this->cpuInfoPath));

        return $count > 0 ? $count : null;
    }

    /** @return array{total: int, available: int}|null */
    public function memory(): ?array
    {
        $contents = $this->read($this->memoryInfoPath);

        if (! preg_match('/^MemTotal:\s+(\d+) kB/m', $contents, $total) || ! preg_match('/^MemAvailable:\s+(\d+) kB/m', $contents, $available)) {
            return null;
        }

        return ['total' => (int) $total[1] * 1024, 'available' => (int) $available[1] * 1024];
    }

    public function cpuTimeMs(): ?float
    {
        $usage = getrusage();
        if ($usage === false) {
            return null;
        }

        $seconds = (int) ($usage['ru_utime.tv_sec'] ?? 0) + (int) ($usage['ru_stime.tv_sec'] ?? 0);
        $microseconds = (int) ($usage['ru_utime.tv_usec'] ?? 0) + (int) ($usage['ru_stime.tv_usec'] ?? 0);

        return $seconds * 1000 + $microseconds / 1000;
    }

    /** @return array{total: int, steal: int}|null */
    public function cpuTicks(): ?array
    {
        if (! preg_match('/^cpu((?:\s+\d+){8})/m', $this->read($this->statPath), $match)) {
            return null;
        }

        $ticks = array_map(intval(...), preg_split('/\s+/', trim($match[1])) ?: []);

        return ['total' => array_sum($ticks), 'steal' => $ticks[7]];
    }

    public function peakMemory(): int
    {
        return memory_get_peak_usage(true);
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? '' : $contents;
    }
}
