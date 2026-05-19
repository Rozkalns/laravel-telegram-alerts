<?php

declare(strict_types=1);

namespace Pokio\Exceptions;

use RuntimeException;

/**
 * @internal
 */
final class FutureAlreadyAwaited extends RuntimeException
{
    /**
     * Creates a new exception instance for when a promise has already been started.
     */
    public function __construct()
    {
        parent::__construct('The promise has already been resolved.');
    }
}
