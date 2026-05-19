<?php

declare(strict_types=1);

namespace Pokio\Runtime\Sync;

use Closure;
use Pokio\Contracts\Future;
use Pokio\Contracts\Runtime;

/**
 * @internal
 */
final readonly class SyncRuntime implements Runtime
{
    /**
     *   Defers the given callback to be executed asynchronously.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return Future<TResult>
     */
    public function defer(Closure $callback): Future
    {
        return new SyncFuture($callback);
    }
}
