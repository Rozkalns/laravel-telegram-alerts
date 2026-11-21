<?php

declare(strict_types=1);

namespace Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Rozkalns\TelegramAlerts\Support\Host;
use Rozkalns\TelegramAlerts\Support\Resolver;
use Rozkalns\TelegramAlerts\TelegramAlertsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, TelegramAlertsServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app?->instance(Resolver::class, new FakeResolver);
        $this->app?->instance(Host::class, new FakeHost);
    }
}
