<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $queryCount = 0;
        $queryTimeMs = 0.0;

        $request->attributes->set('_telegram_listening', true);

        DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs, $request): void {
            if (! $request->attributes->getBoolean('_telegram_listening')) {
                return;
            }

            $queryCount++;
            $queryTimeMs += $query->time;
        });

        $response = $next($request);

        $request->attributes->set('_telegram_listening', false);

        $request->attributes->set('_telegram_query_count', $queryCount);
        $request->attributes->set('_telegram_query_time_ms', $queryTimeMs);

        return $response;
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

        $livewire = $this->extractLivewireContext($request);

        $cacheKeySuffix = $livewire !== null
            ? $livewire['component'].'::'.$livewire['method']
            : $request->method().$request->getRequestUri();
        $cacheKey = 'telegram_slow_'.md5($cacheKeySuffix);

        if (cache()->has($cacheKey)) {
            return;
        }

        cache()->put($cacheKey, true, 300);

        $appName = config()->string('app.name', 'Laravel');
        $appEnv = config()->string('app.env', 'production');
        $appUrl = config()->string('app.url');

        $seconds = number_format($elapsedMs / 1000, 1);

        $queryCount = $request->attributes->getInt('_telegram_query_count');
        $rawQueryTimeMs = $request->attributes->get('_telegram_query_time_ms', 0.0);
        $queryTimeMs = (int) round(is_numeric($rawQueryTimeMs) ? (float) $rawQueryTimeMs : 0.0);

        if ($livewire !== null) {
            $lines = [
                sprintf('🐌 *[%s]* Slow response (%ss)', $appName, $seconds),
                '',
                sprintf('Component: `%s::%s`', $livewire['component'], $livewire['method']),
                '',
            ];
        } else {
            $action = $request->route()?->getActionName() ?? 'unknown';

            $lines = [
                sprintf('🐌 *[%s]* Slow response (%ss)', $appName, $seconds),
                '',
                sprintf('`%s %s`', $request->method(), $request->getRequestUri()),
                sprintf('`%s`', $action),
                '',
            ];
        }

        if ($queryCount > 0) {
            $lines[] = sprintf('🗄️ %s queries (%s ms)', number_format($queryCount), number_format($queryTimeMs));
        }

        $lines[] = sprintf('⏱️ %s ms (threshold: %s ms)', number_format($elapsedMs), number_format($thresholdMs));
        $lines[] = sprintf('📍 %s (%s)', $appUrl, $appEnv);

        $this->client->send(implode("\n", $lines));
    }

    /** @return array{component: string, method: string}|null */
    private function extractLivewireContext(Request $request): ?array
    {
        if (! $request->isMethod('POST') || ! str_contains($request->path(), 'livewire') || ! str_ends_with($request->path(), '/update')) {
            return null;
        }

        $components = $request->input('components');
        if (! is_array($components) || $components === []) {
            return null;
        }

        $first = $components[0];
        if (! is_array($first)) {
            return null;
        }

        $rawSnapshot = $first['snapshot'] ?? '';
        if (! is_string($rawSnapshot)) {
            return null;
        }

        $snapshot = json_decode($rawSnapshot, true);
        if (! is_array($snapshot)) {
            return null;
        }

        $memo = $snapshot['memo'] ?? null;
        if (! is_array($memo)) {
            return null;
        }

        $component = $memo['name'] ?? null;
        if (! is_string($component)) {
            return null;
        }

        $calls = $first['calls'] ?? [];
        $method = is_array($calls) && $calls !== []
            ? (is_array($calls[0]) ? ($calls[0]['method'] ?? null) : null)
            : null;

        return [
            'component' => $component,
            'method' => is_string($method) ? $method : '__render',
        ];
    }
}
