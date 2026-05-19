<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use Monolog\Logger;
use Psr\Log\LoggerInterface;

final readonly class TelegramLogChannel
{
    public function __construct(
        private TelegramHandler $handler,
    ) {}

    public function __invoke(): LoggerInterface
    {
        return new Logger('telegram', [$this->handler]);
    }
}
