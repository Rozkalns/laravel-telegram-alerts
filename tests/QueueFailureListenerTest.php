<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Rozkalns\TelegramAlerts\Listeners\QueueFailureListener;
use Rozkalns\TelegramAlerts\TelegramClient;

beforeEach(function (): void {
    Http::fake();
    Cache::flush();
});

function makeFakeJob(string $name = 'App\\Jobs\\SendEmail', string $queue = 'default', int $attempts = 1): object
{
    return new readonly class($name, $queue, $attempts)
    {
        public function __construct(
            private string $name,
            private string $queue,
            private int $attempts,
        ) {}

        public function resolveName(): string
        {
            return $this->name;
        }

        public function getQueue(): string
        {
            return $this->queue;
        }

        public function attempts(): int
        {
            return $this->attempts;
        }
    };
}

function makeJobFailedEvent(
    string $jobName = 'App\\Jobs\\SendEmail',
    string $queue = 'default',
    int $attempts = 1,
    ?Throwable $exception = null,
): JobFailed {
    $exception ??= new RuntimeException('Connection refused');
    $job = makeFakeJob($jobName, $queue, $attempts);

    return new JobFailed('redis', $job, $exception);
}

it('sends an alert on queue failure', function (): void {
    $listener = app(QueueFailureListener::class);
    $event = makeJobFailedEvent();

    $listener->handle($event);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Queue job failed')
        && str_contains((string) $request['text'], 'App\\Jobs\\SendEmail')
        && str_contains((string) $request['text'], 'Connection refused'));
});

it('includes queue name and attempt count', function (): void {
    $listener = app(QueueFailureListener::class);
    $event = makeJobFailedEvent(queue: 'emails', attempts: 3);

    $listener->handle($event);

    Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'emails')
        && str_contains((string) $request['text'], 'Attempt: 3'));
});

it('rate limits duplicate job failures', function (): void {
    $listener = app(QueueFailureListener::class);

    $listener->handle(makeJobFailedEvent());
    $listener->handle(makeJobFailedEvent());

    Http::assertSentCount(1);
});

it('allows different job failures through', function (): void {
    $listener = app(QueueFailureListener::class);

    $listener->handle(makeJobFailedEvent(jobName: 'App\\Jobs\\JobA'));
    $listener->handle(makeJobFailedEvent(jobName: 'App\\Jobs\\JobB'));

    Http::assertSentCount(2);
});

it('is a no-op when client is not configured', function (): void {
    $client = new TelegramClient(token: '', chatId: '');
    $listener = new QueueFailureListener($client);

    $listener->handle(makeJobFailedEvent());

    Http::assertNothingSent();
});
