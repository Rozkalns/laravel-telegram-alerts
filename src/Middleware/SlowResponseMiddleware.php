<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Rozkalns\TelegramAlerts\Support\IpIdentity;
use Rozkalns\TelegramAlerts\Support\IpIdentityResult;
use Rozkalns\TelegramAlerts\TelegramClient;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class SlowResponseMiddleware
{
    public function __construct(
        private TelegramClient $client,
        private IpIdentity $identity,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('_telegram_start_ms', now()->getTimestampMs());

        $queryCount = 0;
        $queryTimeMs = 0.0;
        $slowestSql = '';
        $slowestTimeMs = 0.0;

        $request->attributes->set('_telegram_listening', true);

        DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs, &$slowestSql, &$slowestTimeMs, $request): void {
            if (! $request->attributes->getBoolean('_telegram_listening')) {
                return;
            }

            $queryCount++;
            $queryTimeMs += $query->time;

            if ($query->time >= $slowestTimeMs) {
                $slowestTimeMs = $query->time;
                $slowestSql = $query->sql;
            }
        });

        $response = $next($request);

        $request->attributes->set('_telegram_listening', false);

        $request->attributes->set('_telegram_query_count', $queryCount);
        $request->attributes->set('_telegram_query_time_ms', $queryTimeMs);
        $request->attributes->set('_telegram_slowest_sql', $slowestSql);
        $request->attributes->set('_telegram_slowest_time_ms', $slowestTimeMs);

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

        $startMs = $request->attributes->getInt('_telegram_start_ms');
        if ($startMs === 0) {
            return;
        }

        $elapsedMs = now()->getTimestampMs() - $startMs;

        if ($elapsedMs < $thresholdMs) {
            return;
        }

        $livewire = $this->extractLivewireContext($request);

        $cacheKeySuffix = $livewire !== null
            ? $livewire['component'].'::'.$livewire['method']
            : $request->method().$request->getRequestUri();
        $cacheKey = 'telegram_slow_'.md5($cacheKeySuffix);
        $repeatKey = $cacheKey.'_repeats';

        $window = config()->integer('telegram-alerts.slow_response_dedup_window', 900);

        if (cache()->has($cacheKey)) {
            $this->recordRepeat($cacheKey, $repeatKey);

            return;
        }

        $identity = $this->identify($request);

        if ($identity->isVerifiedBot()) {
            $policy = config()->string('telegram-alerts.slow_response_bot_policy', 'alert');

            if ($policy === 'ignore') {
                cache()->put($cacheKey, true, $window);

                return;
            }

            if ($policy === 'digest') {
                $window = config()->integer('telegram-alerts.slow_response_bot_digest_window', 3600);
            }
        }

        $repeats = $this->pullRepeats($repeatKey);

        cache()->put($cacheKey, now()->getTimestamp(), $window);

        $appName = config()->string('app.name', 'Laravel');
        $appEnv = config()->string('app.env', 'production');
        $appUrl = config()->string('app.url');

        $seconds = number_format($elapsedMs / 1000, 1);

        $queryCount = $request->attributes->getInt('_telegram_query_count');
        $rawQueryTimeMs = $request->attributes->get('_telegram_query_time_ms', 0.0);
        $queryTimeMs = (int) round(is_numeric($rawQueryTimeMs) ? (float) $rawQueryTimeMs : 0.0);

        $slowestSql = $request->attributes->getString('_telegram_slowest_sql');
        $rawSlowestMs = $request->attributes->get('_telegram_slowest_time_ms', 0.0);
        $slowestTimeMs = (int) round(is_numeric($rawSlowestMs) ? (float) $rawSlowestMs : 0.0);

        $lines = [
            sprintf('🐌 <b>[%s]</b> Slow response (%ss)', e($appName), $seconds),
            '',
        ];

        if ($repeats !== null) {
            $lines[] = $repeats;
        }

        $user = $this->describeUser();
        if ($user !== null) {
            $lines[] = sprintf('👤 %s', $user);
        }

        $lines[] = $this->describeClient($request, $identity);

        if ($livewire !== null) {
            $signature = $this->describeSignature($livewire['method'], $livewire['params']);

            $lines[] = sprintf('Component: <code>%s::%s</code>', e($livewire['component']), e($signature));

            if ($livewire['path'] !== null) {
                $lines[] = sprintf('🌐 <code>%s %s</code>', e($livewire['httpMethod'] ?? 'GET'), e('/'.ltrim($livewire['path'], '/')));
            }

            if ($livewire['entities'] !== []) {
                $escaped = [];
                foreach ($livewire['entities'] as $entity) {
                    $escaped[] = e($entity);
                }

                $lines[] = '🔗 '.implode(' · ', $escaped);
            }
        } else {
            $action = $request->route()?->getActionName() ?? 'unknown'; // @phpstan-ignore nullsafe.neverNull, nullCoalesce.expr

            $lines[] = sprintf('<code>%s %s</code>', e($request->method()), e($request->getRequestUri()));
            $lines[] = sprintf('<code>%s</code>', e($action));
        }

        $lines[] = '';

        if ($queryCount > 0) {
            $appMs = max(0, $elapsedMs - $queryTimeMs);
            $dbLine = sprintf('🗄️ DB %sms · app %sms · %s queries', number_format($queryTimeMs), number_format($appMs), number_format($queryCount));

            if ($queryCount >= config()->integer('telegram-alerts.n_plus_one_threshold', 100)) {
                $dbLine .= ' ⚠️ N+1?';
            }

            $lines[] = $dbLine;

            if ($slowestSql !== '' && $slowestTimeMs >= config()->integer('telegram-alerts.slow_query_threshold', 100)) {
                $lines[] = sprintf('🐢 slowest: <code>%s</code> (%s ms)', e($this->truncateSql($slowestSql)), number_format($slowestTimeMs));
            }
        }

        $lines[] = sprintf('⏱️ %s ms (threshold: %s ms)', number_format($elapsedMs), number_format($thresholdMs));
        $lines[] = sprintf('📍 %s (%s)', e($appUrl), e($appEnv));

        $release = config('telegram-alerts.release');
        if (is_string($release) && $release !== '') {
            $lines[] = sprintf('🏷️ <code>%s</code>', e($release));
        }

        $this->client->send(implode("\n", $lines));
    }

    private function describeUser(): ?string
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        $parts = [];
        foreach (['name', 'email'] as $key) {
            $value = data_get($user, $key);
            if (is_string($value) && $value !== '') {
                $parts[] = e($value);
            }
        }

        $label = $parts !== [] ? implode(' · ', $parts) : 'User';

        $id = Auth::id();
        if ($id !== null) {
            $label .= sprintf(' (#%s)', e((string) $id));
        }

        return $label;
    }

    /** @return array{component: string, method: string, params: list<string>, entities: list<string>, path: string|null, httpMethod: string|null}|null */
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

        $path = $memo['path'] ?? null;
        $httpMethod = $memo['method'] ?? null;

        return [
            'component' => $component,
            'method' => is_string($method) ? $method : '__render',
            'params' => $this->extractCallParams($calls),
            'entities' => $this->extractEntities($snapshot['data'] ?? null),
            'path' => is_string($path) && $path !== '' ? $path : null,
            'httpMethod' => is_string($httpMethod) ? $httpMethod : null,
        ];
    }

    private function describeClient(Request $request, IpIdentityResult $identity): string
    {
        $ip = $request->ip();
        $line = '📡 '.e(is_string($ip) && $ip !== '' ? $ip : 'unknown');

        $label = $identity->label();
        if ($label !== null) {
            $line .= ' · '.e($label);
        }

        if ($identity->isVerifiedBot()) {
            return $line;
        }

        $agent = $request->userAgent();
        if (is_string($agent) && $agent !== '') {
            $line .= ' · '.e($this->truncateUserAgent($agent));
        }

        return $line;
    }

    private function identify(Request $request): IpIdentityResult
    {
        $ip = $request->ip();
        if (! is_string($ip) || $ip === '') {
            return new IpIdentityResult('unknown');
        }

        if (! config()->boolean('telegram-alerts.identify_caller', true)) {
            return new IpIdentityResult($ip);
        }

        try {
            return $this->identity->identify($ip, $request->userAgent());
        } catch (Throwable) {
            return new IpIdentityResult($ip);
        }
    }

    private function recordRepeat(string $cacheKey, string $repeatKey): void
    {
        $existing = cache()->get($repeatKey);

        $count = is_array($existing) && is_int($existing['count'] ?? null) ? $existing['count'] : 0;
        $since = is_array($existing) && is_int($existing['since'] ?? null)
            ? $existing['since']
            : $this->lastAlertedAt($cacheKey);

        cache()->put($repeatKey, ['count' => $count + 1, 'since' => $since], $this->repeatTtl());
    }

    private function lastAlertedAt(string $cacheKey): int
    {
        $sentAt = cache()->get($cacheKey);

        return is_int($sentAt) ? $sentAt : now()->getTimestamp();
    }

    private function repeatTtl(): int
    {
        $window = config()->integer('telegram-alerts.slow_response_dedup_window', 900);

        if (config()->string('telegram-alerts.slow_response_bot_policy', 'alert') === 'digest') {
            $window = max($window, config()->integer('telegram-alerts.slow_response_bot_digest_window', 3600));
        }

        return max($window * 2, 60);
    }

    private function pullRepeats(string $repeatKey): ?string
    {
        $existing = cache()->pull($repeatKey);
        if (! is_array($existing) || ! is_int($existing['count'] ?? null) || $existing['count'] < 1) {
            return null;
        }

        $line = sprintf('🔁 ×%s since the last alert', number_format($existing['count'] + 1));

        $since = $existing['since'] ?? null;
        if (is_int($since)) {
            $minutes = (int) round((now()->getTimestamp() - $since) / 60);

            if ($minutes > 0) {
                $line = sprintf('🔁 ×%s in %s min', number_format($existing['count'] + 1), number_format($minutes));
            }
        }

        return $line;
    }

    private function truncateUserAgent(string $agent): string
    {
        return mb_strlen($agent) > 80 ? mb_substr($agent, 0, 79).'…' : $agent;
    }

    /**
     * @param  list<string>  $params
     */
    private function describeSignature(string $method, array $params): string
    {
        if ($params === [] || $method === '__lazyLoad') {
            return $method;
        }

        $joined = implode(', ', $params);
        if (mb_strlen($joined) > 60) {
            $joined = mb_substr($joined, 0, 59).'…';
        }

        return $method.'('.$joined.')';
    }

    /** @return list<string> */
    private function extractCallParams(mixed $calls): array
    {
        $params = is_array($calls) && isset($calls[0]) && is_array($calls[0]) ? ($calls[0]['params'] ?? null) : null;
        if (! is_array($params)) {
            return [];
        }

        $result = [];
        foreach ($params as $param) {
            if (is_scalar($param) && ! is_bool($param)) {
                $result[] = (string) $param;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function extractEntities(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $entities = [];
        foreach ($data as $key => $value) {
            if (count($entities) >= 5) {
                break;
            }

            $model = $this->modelReference($value);
            if ($model !== null) {
                $entities[] = $model;

                continue;
            }

            if (is_string($key) && $this->isIdKey($key) && (is_int($value) || (is_string($value) && $value !== ''))) {
                $entities[] = $key.'='.$value;
            }
        }

        return $entities;
    }

    private function modelReference(mixed $value): ?string
    {
        if (! is_array($value) || count($value) !== 2) {
            return null;
        }

        $meta = $value[1] ?? null;
        if (! is_array($meta) || ($meta['s'] ?? null) !== 'mdl') {
            return null;
        }

        $class = $meta['class'] ?? null;
        if (! is_string($class)) {
            return null;
        }

        $name = class_basename($class);
        $key = $meta['key'] ?? null;

        return is_scalar($key) ? sprintf('%s #%s', $name, $key) : $name;
    }

    private function isIdKey(string $key): bool
    {
        return (bool) preg_match('/(^id$|Id$|_id$|[Uu]lid$)/', $key);
    }

    private function truncateSql(string $sql): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return mb_strlen($sql) > 120 ? mb_substr($sql, 0, 119).'…' : $sql;
    }
}
