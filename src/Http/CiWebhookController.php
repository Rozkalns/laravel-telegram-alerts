<?php

declare(strict_types=1);

namespace Rozkalns\TelegramAlerts\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rozkalns\TelegramAlerts\TelegramClient;

final readonly class CiWebhookController
{
    public function __construct(
        private TelegramClient $client,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! config()->boolean('telegram-alerts.ci_webhook', false)) {
            return response()->json(['ok' => false, 'error' => 'CI webhook disabled'], 503);
        }

        if (! $this->client->isConfigured()) {
            return response()->json(['ok' => false, 'error' => 'Telegram not configured'], 503);
        }

        $status = $request->string('status')->toString();
        $branch = $request->string('branch')->toString();
        $sha = $request->string('sha')->toString();
        $commit = $request->string('commit')->toString();
        $actor = $request->string('actor')->toString();
        $runUrl = $request->string('run_url')->toString();

        $emoji = $status === 'success' ? '✅' : '❌';
        $label = $status === 'success' ? 'passed' : 'failed';
        $appName = config()->string('app.name', 'Laravel');
        $shortSha = $sha !== '' ? substr($sha, 0, 7) : '';

        $commitLine = match (true) {
            $shortSha !== '' && $commit !== '' => sprintf('`%s` %s', $shortSha, $commit),
            $shortSha !== '' => sprintf('`%s`', $shortSha),
            $commit !== '' => sprintf('`%s`', $commit),
            default => '`unknown`',
        };

        $lines = [
            sprintf('%s *[%s]* CI build %s', $emoji, $appName, $label),
            '',
            $commitLine,
            sprintf('Branch: `%s` · Actor: `%s`', $branch !== '' ? $branch : 'unknown', $actor !== '' ? $actor : 'unknown'),
        ];

        if ($runUrl !== '') {
            $lines[] = '';
            $lines[] = sprintf('🔗 %s', $runUrl);
        }

        $this->client->send(implode("\n", $lines));

        return response()->json(['ok' => true]);
    }
}
