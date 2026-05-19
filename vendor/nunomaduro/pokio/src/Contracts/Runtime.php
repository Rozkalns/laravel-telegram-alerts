<?php

declare(strict_types=1);

namespace Pokio\Contracts;

use Closure;

interface Runtime
{
    /**
     * Defers the given callback to be executed asynchronously.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return Future<TResult>
     */
    public function defer(Closure $callback): Future;
}
