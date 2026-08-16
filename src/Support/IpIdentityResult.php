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

        $name = null;
        if ($this->bot !== null) {
            $name = $this->verified ? $this->bot : 'claims '.$this->bot;
        } elseif ($this->hostname !== null) {
            $name = $this->hostname;
        }

        $network = $this->network();

        $identity = match (true) {
            $name !== null && $network !== null => $name.' ('.$network.')',
            $name !== null => $name,
            default => $network,
        };

        if ($identity === null) {
            return null;
        }

        if ($this->bot !== null) {
            $identity .= $this->verified ? ' · verified' : ' · unverified';
        }

        return $identity;
    }

    private function network(): ?string
    {
        if ($this->asn === null) {
            return $this->organisation;
        }

        return $this->organisation === null ? $this->asn : $this->asn.' '.$this->organisation;
    }
}
