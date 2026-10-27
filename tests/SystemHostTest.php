<?php

declare(strict_types=1);

use Rozkalns\TelegramAlerts\Support\SystemHost;
use Tests\HostShim;

beforeEach(fn () => HostShim::reset());

afterEach(fn () => HostShim::reset());

function procFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'proc');
    file_put_contents($path, $contents);

    return $path;
}

it('reads the load average', function (): void {
    HostShim::$load = [1.5, 0.75, 0.5];

    expect(new SystemHost()->loadAverage())->toBe([1.5, 0.75, 0.5]);
});

it('returns no load average when the system has none', function (): void {
    HostShim::$load = false;

    expect(new SystemHost()->loadAverage())->toBeNull();
});

it('returns no load average when it is incomplete', function (): void {
    HostShim::$load = [1.5];

    expect(new SystemHost()->loadAverage())->toBeNull();
});

it('counts processors in cpuinfo', function (): void {
    $path = procFile("processor\t: 0\nmodel name\t: x\n\nprocessor\t: 1\nmodel name\t: x\n");

    expect(new SystemHost(cpuInfoPath: $path)->cpuCount())->toBe(2);
});

it('returns no cpu count when cpuinfo is missing', function (): void {
    expect(new SystemHost(cpuInfoPath: '/nonexistent/cpuinfo')->cpuCount())->toBeNull();
});

it('reads total and available memory from meminfo', function (): void {
    $path = procFile("MemTotal:        8000000 kB\nMemFree:          100000 kB\nMemAvailable:    2000000 kB\n");

    expect(new SystemHost(memoryInfoPath: $path)->memory())
        ->toBe(['total' => 8_000_000 * 1024, 'available' => 2_000_000 * 1024]);
});

it('returns no memory when meminfo lacks MemAvailable', function (): void {
    $path = procFile("MemTotal:        8000000 kB\n");

    expect(new SystemHost(memoryInfoPath: $path)->memory())->toBeNull();
});

it('returns no memory when meminfo is missing', function (): void {
    expect(new SystemHost(memoryInfoPath: '/nonexistent/meminfo')->memory())->toBeNull();
});

it('adds user and system cpu time in milliseconds', function (): void {
    HostShim::$usage = [
        'ru_utime.tv_sec' => 1,
        'ru_utime.tv_usec' => 500_000,
        'ru_stime.tv_sec' => 0,
        'ru_stime.tv_usec' => 250_000,
    ];

    expect(new SystemHost()->cpuTimeMs())->toBe(1750.0);
});

it('returns no cpu time when getrusage fails', function (): void {
    HostShim::$usage = false;

    expect(new SystemHost()->cpuTimeMs())->toBeNull();
});

it('reads the real process cpu time and peak memory', function (): void {
    $host = new SystemHost;

    expect($host->cpuTimeMs())->toBeFloat()->toBeGreaterThan(0.0)
        ->and($host->peakMemory())->toBeGreaterThan(0);
});

it('reads total and stolen cpu ticks from stat', function (): void {
    $path = procFile("cpu  100 5 50 800 20 3 2 20 0 0\ncpu0 50 2 25 400 10 1 1 10 0 0\n");

    expect(new SystemHost(statPath: $path)->cpuTicks())->toBe(['total' => 1000, 'steal' => 20]);
});

it('returns no cpu ticks when stat is missing', function (): void {
    expect(new SystemHost(statPath: '/nonexistent/stat')->cpuTicks())->toBeNull();
});
