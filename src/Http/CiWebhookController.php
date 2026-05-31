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
            $shortSha !== '' && $commit !== '' => sprintf('<code>%s</code> %s', e($shortSha), e($commit)),
            $shortSha !== '' => sprintf('<code>%s</code>', e($shortSha)),
            $commit !== '' => sprintf('<code>%s</code>', e($commit)),
            default => '<code>unknown</code>',
        };

        $lines = [
            sprintf('%s <b>[%s]</b> CI build %s', $emoji, e($appName), $label),
            '',
            $commitLine,
            sprintf('Branch: <code>%s</code> · Actor: <code>%s</code>', $branch !== '' ? e($branch) : 'unknown', $actor !== '' ? e($actor) : 'unknown'),
        ];

        $jobsLine = $this->buildJobsLine($request->input('jobs'));
        $hasDuration = $request->has('duration');

        if ($jobsLine !== '' || $hasDuration) {
            $lines[] = '';

            if ($jobsLine !== '') {
                $lines[] = $jobsLine;
            }

            if ($hasDuration) {
                $lines[] = sprintf('⏱️ total %s', $this->formatDuration($request->integer('duration')));
            }
        }

        if ($runUrl !== '') {
            $lines[] = '';
            $lines[] = sprintf('🔗 %s', e($runUrl));
        }

        $this->client->send(implode("\n", $lines));

        return response()->json(['ok' => true]);
    }

    private function buildJobsLine(mixed $jobs): string
    {
        if (! is_array($jobs)) {
            return '';
        }

        $parts = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            $rawName = $job['name'] ?? '';
            $name = is_string($rawName) ? $rawName : '';
            if ($name === '') {
                continue;
            }

            $rawConclusion = $job['conclusion'] ?? '';
            $conclusion = is_string($rawConclusion) ? $rawConclusion : '';
            $jobEmoji = $conclusion === 'success' ? '✅' : '❌';
            $rawDuration = $job['duration'] ?? 0;
            $parts[] = sprintf('%s %s %s', e($name), $jobEmoji, $this->formatDuration(is_int($rawDuration) ? $rawDuration : 0));
        }

        return implode(' · ', $parts);
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remSeconds > 0 ? sprintf('%dm %ds', $minutes, $remSeconds) : sprintf('%dm', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $remMinutes = $minutes % 60;

        return $remMinutes > 0 ? sprintf('%dh %dm', $hours, $remMinutes) : sprintf('%dh', $hours);
    }
}
