<?php

declare(strict_types=1);

namespace Pokio\Runtime\Sync;

use Closure;
use Pokio\Contracts\Future;
use Pokio\Exceptions\FutureAlreadyAwaited;
use Pokio\Promise;

/**
 * @internal
 *
 * @template TResult
 *
 * @implements Future<TResult>
 */
final class SyncFuture implements Future
{
    /**
     * Indicates whether the result has been awaited.
     */
    private bool $awaited = false;

    /**
     * Creates a new sync result instance.
     *
     * @param  Closure(): TResult  $callback
     */
    public function __construct(private Closure $callback)
    {
        //
    }

    /**
     * Awaits the result of the future.
     *
     * @return TResult
     */
    public function await(): mixed
    {
        if ($this->awaited) {
            throw new FutureAlreadyAwaited();
        }

        $this->awaited = true;

        $result = ($this->callback)();

        if ($result instanceof Promise) {
            return await($result);
        }

        return $result;
    }

    /**
     * Whether the result has been awaited.
     */
    public function awaited(): bool
    {
        return $this->awaited;
    }
}
