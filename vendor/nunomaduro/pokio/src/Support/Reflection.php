<?php

declare(strict_types=1);

namespace Pokio\Support;

use Closure;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

/**
 * @internal
 */
final readonly class Reflection
{
    /**
     * Checks if the given callback is catchable.
     *
     * @param  Closure(Throwable): mixed  $callback
     */
    public static function isCatchable(Closure $callback, Throwable $throwable): bool
    {
        $reflection = new ReflectionFunction($callback);
        $parameters = $reflection->getParameters();
        $types = [];

        if (count($parameters) > 0) {
            $type = current($parameters)->getType();

            /** @var array<int, ReflectionNamedType> $types */
            $types = match (true) {
                $type instanceof ReflectionUnionType => $type->getTypes(),
                $type instanceof ReflectionNamedType => [$type],
                default => [],
            };
        }

        $matchesType = false;
        foreach ($types as $type) {
            $matchesType = $type->getName() === get_debug_type($throwable)
                || is_a($throwable, $type->getName());

            if ($matchesType) {
                break;
            }
        }

        return count($types) === 0 || $matchesType;
    }
}
