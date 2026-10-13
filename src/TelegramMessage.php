<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use DateTimeInterface;

final class TelegramMessage
{
    /** @var list<string> */
    private array $lines = [];

    private bool $withContext = true;

    private ?DateTimeInterface $at = null;

    private function __construct(
        private readonly string $emoji,
        private readonly string $title,
    ) {}

    public static function make(string $emoji, string $title): self
    {
        return new self($emoji, $title);
    }

    public function line(string $html = ''): self
    {
        $this->lines[] = $html;

        return $this;
    }

    /**
     * @param  list<string>  $lines
     */
    public function lines(array $lines): self
    {
        $this->lines = [...$this->lines, ...$lines];

        return $this;
    }

    public function at(DateTimeInterface $at): self
    {
        $this->at = $at;

        return $this;
    }

    public function withoutContext(): self
    {
        $this->withContext = false;

        return $this;
    }

    public function render(): string
    {
        $out = [sprintf('%s <b>[%s]</b> %s', $this->emoji, e(config()->string('app.name', 'Laravel')), $this->title)];

        $body = $this->trimBlankEdges($this->lines);

        if ($body !== []) {
            $out = [...$out, '', ...$body];
        }

        if ($this->withContext) {
            $out = [...$out, '', ...self::context($this->at)];
        }

        return implode("\n", $out);
    }

    /**
     * @return list<string>
     */
    public static function context(?DateTimeInterface $at = null): array
    {
        $lines = [sprintf('📍 %s (%s)', e(config()->string('app.url')), e(config()->string('app.env', 'production')))];

        $release = self::release();

        if ($release !== null) {
            $lines[] = sprintf('🏷️ <code>%s</code>', e($release));
        }

        $lines[] = sprintf('🕐 %s', ($at ?? now())->format('Y-m-d H:i:s T'));

        return $lines;
    }

    public static function release(): ?string
    {
        foreach (['telegram-alerts.release', 'sentry.release'] as $key) {
            $release = config($key);

            if (is_string($release) && $release !== '') {
                return $release;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function trimBlankEdges(array $lines): array
    {
        while ($lines !== [] && $lines[0] === '') {
            array_shift($lines);
        }

        while ($lines !== [] && array_last($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}
