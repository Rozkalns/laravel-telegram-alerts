<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Middleware;

use Closure;
use Illuminate\Http\Request;
use Rozkalns\TelegramAlerts\TelegramClient;
use Symfony\Component\HttpFoundation\Response;

final readonly class SlowResponseMiddleware
{
    public function __construct(
        private TelegramClient $client,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('_telegram_start_us', (int) (microtime(true) * 1_000_000));

        return $next($request);
    }

    public function terminate(Request $request): void
    {
        $thresholdMs = config()->integer('telegram-alerts.slow_response_threshold');
        if ($thresholdMs <= 0) {
            return;
        }

        if (! $this->client->isConfigured()) {
            return;
        }

        $excludedPaths = config()->array('telegram-alerts.slow_response_exclude');
        foreach ($excludedPaths as $path) {
            if (is_string($path) && str_starts_with('/'.$request->path(), $path)) {
                return;
            }
        }

        $startUs = $request->attributes->getInt('_telegram_start_us');
        if ($startUs === 0) {
            return;
        }

        $nowUs = (int) (microtime(true) * 1_000_000);
        $elapsedMs = (int) round(($nowUs - $startUs) / 1000);

        if ($elapsedMs < $thresholdMs) {
            return;
        }

        $cacheKey = 'telegram_slow_'.md5($request->method().$request->getRequestUri());
        if (cache()->has($cacheKey)) {
            return;
        }

        cache()->put($cacheKey, true, 300);

        $appName = config()->string('app.name', 'Laravel');
        $appEnv = config()->string('app.env', 'production');
        $appUrl = config()->string('app.url');

        $seconds = number_format($elapsedMs / 1000, 1);
        $action = $request->route()->getActionName();

        $lines = [
            sprintf('🐌 *[%s]* Slow response (%ss)', $appName, $seconds),
            '',
            sprintf('`%s %s`', $request->method(), $request->getRequestUri()),
            sprintf('`%s`', $action),
            '',
            sprintf('⏱️ %s ms (threshold: %s ms)', number_format($elapsedMs), number_format($thresholdMs)),
            sprintf('📍 %s (%s)', $appUrl, $appEnv),
        ];

        $this->client->send(implode("\n", $lines));
    }
}
