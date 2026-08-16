<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Support;

use Illuminate\Support\Str;

final readonly class IpIdentity
{
    /**
     * @var array<string, string>
     */
    private const array BOT_HOSTS = [
        'googlebot.com' => 'Googlebot',
        'google.com' => 'Googlebot',
        'search.msn.com' => 'Bingbot',
    ];

    /** @var array<string, string> */
    private const array BOT_AGENTS = [
        'Googlebot' => 'Googlebot',
        'bingbot' => 'Bingbot',
    ];

    public function __construct(
        private Resolver $resolver,
        private int $budgetMs = 1000,
        private int $cacheTtl = 3600,
        private int $asnCacheTtl = 604800,
    ) {}

    public function identify(string $ip, ?string $userAgent = null): IpIdentityResult
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return new IpIdentityResult($ip);
        }

        if (Cloudflare::contains($ip)) {
            return new IpIdentityResult($ip, edge: true);
        }

        /** @var array<string, mixed> $facts */
        $facts = cache()->remember(
            'telegram_ip_'.md5($ip),
            $this->cacheTtl,
            fn (): array => $this->lookup($ip),
        );

        $bot = $this->stringOrNull($facts['bot'] ?? null);
        $verified = ($facts['verified'] ?? false) === true;

        if ($bot === null) {
            $bot = $this->botFromUserAgent($userAgent);
        }

        return new IpIdentityResult(
            ip: $ip,
            hostname: $this->stringOrNull($facts['hostname'] ?? null),
            asn: $this->stringOrNull($facts['asn'] ?? null),
            organisation: $this->stringOrNull($facts['organisation'] ?? null),
            bot: $bot,
            verified: $verified,
        );
    }

    /** @return array<string, mixed> */
    private function lookup(string $ip): array
    {
        $startedAt = hrtime(true);

        $hostname = $this->resolver->ptr($this->reverseName($ip));

        $bot = null;
        $verified = false;

        if ($hostname !== null) {
            $bot = $this->botFromHostname($hostname);

            if ($bot !== null && $this->withinBudget($startedAt)) {
                $verified = $this->forwardConfirms($hostname, $ip, str_contains($ip, ':'));
            }
        }

        $asn = null;
        $organisation = null;

        if ($this->withinBudget($startedAt)) {
            [$asn, $organisation] = $this->lookupNetwork($ip, $startedAt);
        }

        return [
            'hostname' => $hostname,
            'asn' => $asn,
            'organisation' => $organisation,
            'bot' => $bot,
            'verified' => $verified,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function withinBudget(int $startedAt): bool
    {
        return (hrtime(true) - $startedAt) / 1_000_000 < $this->budgetMs;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function lookupNetwork(string $ip, int $startedAt): array
    {
        $zone = str_contains($ip, ':') ? 'origin6.asn.cymru.com' : 'origin.asn.cymru.com';

        $origin = $this->resolver->txt($this->reverseLabels($ip).'.'.$zone);
        if ($origin === []) {
            return [null, null];
        }

        $number = explode(' ', trim(explode('|', $origin[0])[0]))[0];

        if (! ctype_digit($number)) {
            return [null, null];
        }

        $asn = 'AS'.$number;

        if (! $this->withinBudget($startedAt)) {
            return [$asn, null];
        }

        return [$asn, $this->organisationOf($number)];
    }

    private function organisationOf(string $number): ?string
    {
        /** @var string|null $organisation */
        $organisation = cache()->remember(
            'telegram_asn_'.$number,
            $this->asnCacheTtl,
            function () use ($number): ?string {
                $description = $this->resolver->txt('AS'.$number.'.asn.cymru.com');

                return $description === [] ? null : $this->organisationFrom($description[0]);
            },
        );

        return $organisation;
    }

    private function organisationFrom(string $record): ?string
    {
        $name = trim(Str::afterLast($record, '|'));

        return $name === '' ? null : trim(Str::beforeLast($name, ','));
    }

    private function botFromHostname(string $hostname): ?string
    {
        $hostname = mb_strtolower($hostname);

        foreach (self::BOT_HOSTS as $suffix => $name) {
            if ($hostname === $suffix || str_ends_with($hostname, '.'.$suffix)) {
                return $name;
            }
        }

        return null;
    }

    private function botFromUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        foreach (self::BOT_AGENTS as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return null;
    }

    private function forwardConfirms(string $hostname, string $ip, bool $ipv6): bool
    {
        $expected = (string) inet_pton($ip);

        foreach ($this->resolver->addresses($hostname, $ipv6) as $address) {
            $resolved = (string) @inet_pton($address);

            if ($resolved !== '' && $resolved === $expected) {
                return true;
            }
        }

        return false;
    }

    private function reverseName(string $ip): string
    {
        $suffix = str_contains($ip, ':') ? '.ip6.arpa' : '.in-addr.arpa';

        return $this->reverseLabels($ip).$suffix;
    }

    private function reverseLabels(string $ip): string
    {
        if (! str_contains($ip, ':')) {
            return implode('.', array_reverse(explode('.', $ip)));
        }

        return implode('.', array_reverse(str_split(bin2hex((string) inet_pton($ip)))));
    }
}
