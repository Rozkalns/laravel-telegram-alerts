<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('sends log messages through the telegram channel', function (): void {
    Http::fake();

    Log::channel('telegram')->error('Test error via channel');

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Test error via channel'));
});
