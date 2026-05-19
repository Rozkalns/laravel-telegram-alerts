<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use Illuminate\Support\Facades\Http;

final readonly class TelegramClient
{
    public function __construct(
        private string $token,
        private string $chatId,
    ) {}

    public function send(string $text): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        rescue(fn () => Http::timeout(5)->post(
            sprintf('https://api.telegram.org/bot%s/sendMessage', $this->token),
            [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ],
        ));
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }
}
