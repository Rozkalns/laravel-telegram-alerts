<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Rozkalns\TelegramAlerts\Support\Resolver;
use Rozkalns\TelegramAlerts\TelegramAlertsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [TelegramAlertsServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app?->instance(Resolver::class, new FakeResolver);
    }
}
