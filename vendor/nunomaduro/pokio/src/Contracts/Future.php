<?php

declare(strict_types=1);

namespace Pokio\Contracts;

/**
 * @internal
 *
 * @template TResult
 */
interface Future
{
    /**
     * The result of the asynchronous operation.
     *
     * @return TResult
     */
    public function await(): mixed;

    /**
     * Whether the result has been awaited.
     */
    public function awaited(): bool;
}
