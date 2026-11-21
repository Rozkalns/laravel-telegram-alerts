<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Listeners;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Rozkalns\TelegramAlerts\Support\IpIdentity;
use Rozkalns\TelegramAlerts\TelegramClient;
use Rozkalns\TelegramAlerts\TelegramMessage;
use Throwable;

final readonly class LivewireTamperingReporter
{
    private const int MAX_COMPONENTS = 5;

    private const int MAX_LENGTH = 120;

    private const int ALERT_EVERY_SECONDS = 600;

    public function __construct(
        private TelegramClient $client,
        private IpIdentity $identity,
    ) {}

    /**
     * @return list<string>
     */
    public static function components(Request $request): array
    {
        $payload = $request->hasHeader('X-Livewire') ? $request->input('components') : null;

        if (! is_array($payload)) {
            return [];
        }

        $components = [];

        foreach (array_slice($payload, 0, self::MAX_COMPONENTS) as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null)
                ? json_decode($component['snapshot'], associative: true)
                : null;

            $memo = is_array($snapshot) && is_array($snapshot['memo'] ?? null) ? $snapshot['memo'] : [];

            $components[] = sprintf('%s @ /%s', self::short($memo['name'] ?? null), ltrim(self::short($memo['path'] ?? null), '/'));
        }

        return $components;
    }

    public static function provesLeakedKey(Throwable $e): bool
    {
        return str_ends_with(str_replace('\\', '/', $e->getFile()), 'Mechanisms/HandleComponents/SecurityPolicy.php')
            && str_contains($e->getMessage(), 'is not allowed to be instantiated');
    }

    public function report(Throwable $e, Request $request): void
    {
        if (! self::provesLeakedKey($e) || ! Cache::add('telegram_livewire_leaked_key', true, self::ALERT_EVERY_SECONDS)) {
            return;
        }

        $lines = [
            "A Livewire snapshot signed with this app's APP_KEY tried to load a blocked class.",
            'Only someone who knows APP_KEY can sign a snapshot. Rotate it now.',
            '',
            sprintf('💥 <code>%s</code>', e(self::short($e->getMessage()))),
            '📡 '.e($this->caller($request)),
        ];

        foreach (self::components($request) as $component) {
            $lines[] = sprintf('🧩 <code>%s</code>', e($component));
        }

        $this->client->send(TelegramMessage::make('🔴', 'APP_KEY is compromised: rotate now')->lines($lines));
    }

    private function caller(Request $request): string
    {
        $ip = (string) $request->ip();

        if (! config()->boolean('telegram-alerts.identify_caller', true)) {
            return $ip;
        }

        try {
            $label = $this->identity->identify($ip, $request->userAgent())->label();
        } catch (Throwable) {
            $label = null;
        }

        return $label === null ? $ip : $ip.' · '.$label;
    }

    private static function short(mixed $value): string
    {
        return is_string($value) && $value !== '' ? Str::limit($value, self::MAX_LENGTH) : '?';
    }
}
