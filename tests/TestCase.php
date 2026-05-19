<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Rozkalns\TelegramAlerts\TelegramAlertsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [TelegramAlertsServiceProvider::class];
    }
}
