<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Support;

final readonly class IpIdentityResult
{
    public function __construct(
        public string $ip,
        public ?string $hostname = null,
        public ?string $asn = null,
        public ?string $organisation = null,
        public ?string $bot = null,
        public bool $verified = false,
        public bool $edge = false,
    ) {}

    public function isVerifiedBot(): bool
    {
        return $this->bot !== null && $this->verified;
    }

    public function label(): ?string
    {
        if ($this->edge) {
            return 'Cloudflare edge IP — real-client-IP not configured';
        }

        $name = match (true) {
            $this->bot === null => $this->hostname,
            $this->verified => $this->bot,
            default => 'claims '.$this->bot,
        };

        $network = $this->network();

        if ($name === null) {
            return $network;
        }

        $identity = $network === null ? $name : $name.' ('.$network.')';

        return $this->bot === null ? $identity : $identity.($this->verified ? ' · verified' : ' · unverified');
    }

    private function network(): ?string
    {
        $parts = array_filter([$this->asn, $this->organisation]);

        return $parts === [] ? null : implode(' ', $parts);
    }
}
