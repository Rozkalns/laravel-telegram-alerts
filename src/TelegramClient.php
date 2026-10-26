<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use Rozkalns\TelegramAlerts\Jobs\SendTelegramMessageJob;
use Throwable;

final readonly class TelegramClient
{
    public function __construct(
        private string $token,
        private string $chatId,
        private int $maxAttempts = 3,
    ) {}

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function send(string|TelegramMessage $text, ?array $replyMarkup = null): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if ($text instanceof TelegramMessage) {
            $text = $text->render();
        }

        $payload = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $attempts = max($this->maxAttempts, 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout(5)->post(
                    sprintf('https://api.telegram.org/bot%s/sendMessage', $this->token),
                    $payload,
                );

                if ($response->successful()) {
                    $this->logInfo('Telegram alert sent', $text);

                    return;
                }

                if ($response->status() === 429) {
                    if ($attempt < $attempts) {
                        $retryAfter = (int) $response->header('Retry-After');
                        Sleep::for(min(max($retryAfter, 1), 5))->seconds();

                        continue;
                    }

                    $this->logWarning('Telegram alert delivery failed after retries', $text, $response->status());

                    return;
                }

                if ($response->serverError()) {
                    if ($attempt < $attempts) {
                        Sleep::for($attempt)->seconds();

                        continue;
                    }

                    $this->logWarning('Telegram alert delivery failed after retries', $text, $response->status());

                    return;
                }

                $this->logWarning('Telegram alert delivery failed', $text, $response->status());

                return;
            } catch (Throwable $e) {
                if ($attempt < $attempts) {
                    Sleep::for(1)->seconds();

                    continue;
                }

                $this->logWarning('Telegram alert delivery failed after retries', $text, exception: $e);

                return;
            }
        }
    }

    public function sendQueued(string|TelegramMessage $text): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        SendTelegramMessageJob::dispatch($text instanceof TelegramMessage ? $text->render() : $text);
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }

    private function logInfo(string $message, string $text): void
    {
        $this->logger()->info($message, [
            'text' => mb_substr($text, 0, 200),
        ]);
    }

    private function logWarning(string $message, string $text, ?int $status = null, ?Throwable $exception = null): void
    {
        $context = array_filter([
            'text' => mb_substr($text, 0, 200),
            'status' => $status,
            'exception' => $exception,
        ]);

        $this->logger()->warning($message, $context);
    }

    private function logger(): LoggerInterface
    {
        $configured = config('telegram-alerts.log_channel');
        $channels = is_string($configured) && $configured !== '' ? [$configured] : $this->defaultChannels();
        $channels = array_values(array_diff($channels, ['telegram']));

        return match (count($channels)) {
            0 => Log::channel('single'),
            1 => Log::channel($channels[0]),
            default => Log::stack($channels),
        };
    }

    /** @return list<string> */
    private function defaultChannels(): array
    {
        $default = config()->string('logging.default', 'stack');

        if (config("logging.channels.{$default}.driver") !== 'stack') {
            return [$default];
        }

        return array_values(array_filter((array) config("logging.channels.{$default}.channels", []), is_string(...)));
    }
}
