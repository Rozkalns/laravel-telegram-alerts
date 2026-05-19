<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Rozkalns\TelegramAlerts\Jobs\SendTelegramMessageJob;
use Throwable;

final readonly class TelegramClient
{
    public function __construct(
        private string $token,
        private string $chatId,
        private int $maxAttempts = 3,
    ) {}

    public function send(string $text): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $attempts = max($this->maxAttempts, 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout(5)->post(
                    sprintf('https://api.telegram.org/bot%s/sendMessage', $this->token),
                    [
                        'chat_id' => $this->chatId,
                        'text' => $text,
                        'parse_mode' => 'Markdown',
                        'disable_web_page_preview' => true,
                    ],
                );

                if ($response->successful()) {
                    return;
                }

                if ($response->status() === 429) {
                    if ($attempt < $attempts) {
                        $retryAfter = (int) $response->header('Retry-After');
                        Sleep::for(min(max($retryAfter, 1), 5))->seconds();

                        continue;
                    }

                    $this->logWarning('Telegram alert delivery failed after retries', $response->status());

                    return;
                }

                if ($response->serverError()) {
                    if ($attempt < $attempts) {
                        Sleep::for($attempt)->seconds();

                        continue;
                    }

                    $this->logWarning('Telegram alert delivery failed after retries', $response->status());

                    return;
                }

                $this->logWarning('Telegram alert delivery failed', $response->status());

                return;
            } catch (Throwable $e) {
                if ($attempt < $attempts) {
                    Sleep::for(1)->seconds();

                    continue;
                }

                $this->logWarning('Telegram alert delivery failed after retries', exception: $e);

                return;
            }
        }
    }

    public function sendQueued(string $text): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        SendTelegramMessageJob::dispatch($text);
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }

    private function logWarning(string $message, ?int $status = null, ?Throwable $exception = null): void
    {
        $context = array_filter([
            'status' => $status,
            'exception' => $exception,
        ]);

        Log::channel('single')->warning($message, $context);
    }
}
