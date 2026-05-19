<?php

declare(strict_types=1);

namespace Pokio;

/**
 * @internal
 */
final readonly class Pokio
{
    /**
     * Specifies that Pokio should use the fork runtime for asynchronous operations.
     */
    public function useFork(): void
    {
        Kernel::instance()->useFork();
    }

    /**
     * Specifies that Pokio should use the sync runtime for asynchronous operations.
     */
    public function useSync(): void
    {
        Kernel::instance()->useSync();
    }
}
