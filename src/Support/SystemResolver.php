<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Support;

final class SystemResolver implements Resolver
{
    public function ptr(string $name): ?string
    {
        foreach ($this->query($name, DNS_PTR) as $record) {
            $target = $record['target'] ?? null;
            if (is_string($target) && $target !== '') {
                return rtrim($target, '.');
            }
        }

        return null;
    }

    /** @return list<string> */
    public function txt(string $name): array
    {
        $values = [];
        foreach ($this->query($name, DNS_TXT) as $record) {
            $text = $record['txt'] ?? null;
            if (is_string($text) && $text !== '') {
                $values[] = $text;
            }
        }

        return $values;
    }

    /** @return list<string> */
    public function addresses(string $host): array
    {
        $addresses = [];
        foreach ($this->query($host, DNS_A | DNS_AAAA) as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                $value = $record[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $addresses[] = $value;
                }
            }
        }

        return $addresses;
    }

    /** @return list<array<array-key, mixed>> */
    private function query(string $name, int $type): array
    {
        $records = @dns_get_record($name, $type);

        return $records === false ? [] : $records;
    }
}
