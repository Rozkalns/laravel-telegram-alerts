<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rozkalns\TelegramAlerts\TelegramClient;

final readonly class GlitchTipWebhookController
{
    public function __construct(
        private TelegramClient $client,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! config()->boolean('telegram-alerts.glitchtip_webhook', false)) {
            return response()->json(['ok' => false, 'error' => 'GlitchTip webhook disabled'], 503);
        }

        if (! $this->client->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Telegram not configured'], 503);
        }

        $shortId = $this->shortId($request->input('sections'));

        foreach ($this->attachments($request->input('attachments')) as $attachment) {
            $this->sendAttachment($attachment, $shortId);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function attachments(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $result = [];

        foreach ($attachments as $attachment) {
            if (is_array($attachment)) {
                $result[] = $attachment;
            }
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $attachment
     */
    private function sendAttachment(array $attachment, string $shortId): void
    {
        $titleLink = $this->stringValue($attachment, 'title_link');
        if ($titleLink === '') {
            return;
        }

        $title = $this->stringValue($attachment, 'title');
        if ($title === '') {
            $title = 'GlitchTip issue';
        }

        $appName = config()->string('app.name', 'Laravel');

        $headline = $shortId !== ''
            ? sprintf('<code>%s</code> <a href="%s">%s</a>', e($shortId), e($titleLink), e($title))
            : sprintf('<a href="%s">%s</a>', e($titleLink), e($title));

        $lines = [
            sprintf('🐞 <b>[%s]</b> GlitchTip issue', e($appName)),
            '',
            $headline,
        ];

        $culprit = $this->stringValue($attachment, 'text');
        if ($culprit !== '') {
            $lines[] = sprintf('📄 %s', e($culprit));
        }

        $context = $this->contextLine($attachment['fields'] ?? null);
        if ($context !== '') {
            $lines[] = $context;
        }

        $this->client->send(implode("\n", $lines), [
            'inline_keyboard' => [[
                ['text' => '🔍 Open in GlitchTip', 'url' => $titleLink],
            ]],
        ]);
    }

    private function contextLine(mixed $fields): string
    {
        $parts = [];

        $environment = $this->fieldValue($fields, 'Environment');
        if ($environment !== '') {
            $parts[] = e($environment);
        }

        $release = $this->fieldValue($fields, 'Release');
        if ($release !== '') {
            $parts[] = e($release);
        }

        return $parts === [] ? '' : '📍 '.implode(' · ', $parts);
    }

    private function fieldValue(mixed $fields, string $name): string
    {
        if (! is_array($fields)) {
            return '';
        }

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            if (($field['title'] ?? null) === $name) {
                return $this->stringValue($field, 'value');
            }
        }

        return '';
    }

    private function shortId(mixed $sections): string
    {
        if (! is_array($sections)) {
            return '';
        }

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $subtitle = $this->stringValue($section, 'activitySubtitle');
            if (preg_match('/\[View Issue ([^\]]+)\]/', $subtitle, $matches) === 1) {
                return $matches[1];
            }
        }

        return '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
