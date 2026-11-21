<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use League\Flysystem\UrlGeneration\ShardedPrefixPublicUrlGenerator;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Rozkalns\TelegramAlerts\Listeners\LivewireTamperingReporter;
use Rozkalns\TelegramAlerts\TelegramAlertsServiceProvider;
use Tests\Fixtures\ProbeComponent;

beforeEach(function (): void {
    Http::fake();
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    Livewire::component('probe', ProbeComponent::class);
});

/**
 * @return array<string, mixed>
 */
function tamperedSnapshot(bool $signWithAppKey): array
{
    preg_match('/wire:snapshot="([^"]+)"/', Livewire::mount('probe'), $match);

    /** @var array<string, mixed> $snapshot */
    $snapshot = json_decode(html_entity_decode($match[1]), associative: true);

    unset($snapshot['checksum']);
    $snapshot['data']['items'] = [[], ['s' => 'clctn', 'class' => ShardedPrefixPublicUrlGenerator::class]];
    $snapshot['checksum'] = $signWithAppKey ? Checksum::generate($snapshot) : str_repeat('0', 64);

    return $snapshot;
}

/** @param array<string, mixed> $snapshot */
function postLivewireUpdate(array $snapshot, int $status = 500): void
{
    test()->postJson(resolve('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => json_encode($snapshot), 'updates' => [], 'calls' => []]],
    ], ['X-Livewire' => 'true'])->assertStatus($status);
}

/** @return list<string> */
function compromiseAlerts(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => (string) $pair[0]['text'])
        ->filter(fn (string $text): bool => str_contains($text, 'APP_KEY is compromised'))
        ->values()
        ->all();
}

it('alerts once when a snapshot signed with APP_KEY loads a blocked class', function (): void {
    fakeResolver()->ptr['1.0.0.127.in-addr.arpa'] = 'attacker.example.net';
    fakeResolver()->addresses['attacker.example.net'] = ['127.0.0.1'];

    postLivewireUpdate(tamperedSnapshot(signWithAppKey: true));
    postLivewireUpdate(tamperedSnapshot(signWithAppKey: true));

    $alerts = compromiseAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0])->toContain('🔴 <b>[TestApp]</b> APP_KEY is compromised: rotate now')
        ->toContain('Rotate it now')
        ->toContain(e(ShardedPrefixPublicUrlGenerator::class))
        ->toContain('📡 127.0.0.1 · attacker.example.net')
        ->toContain('🧩 <code>probe @ /');
});

it('sends no compromise alert for a snapshot with a wrong checksum', function (): void {
    postLivewireUpdate(tamperedSnapshot(signWithAppKey: false), status: 419);

    expect(compromiseAlerts())->toBeEmpty();
});

it('shows only the IP when caller lookup is off or fails', function (bool $lookup): void {
    config()->set('telegram-alerts.identify_caller', $lookup);
    fakeResolver()->throws = true;

    postLivewireUpdate(tamperedSnapshot(signWithAppKey: true));

    expect(compromiseAlerts()[0])->toContain("📡 127.0.0.1\n");
})->with(['lookup off' => false, 'lookup fails' => true]);

it('ignores errors that are not a Livewire SecurityPolicy block', function (): void {
    resolve(LivewireTamperingReporter::class)->report(new Exception('Class [X] is not allowed to be instantiated.'), Request::create('/'));

    expect(Http::recorded())->toBeEmpty();
});

it('names each component and page of a Livewire update, but no property values', function (): void {
    $snapshot = json_encode(['memo' => ['name' => 'competitions', 'path' => 'lv'], 'data' => ['secret' => 'value']]);

    $request = Request::create('/livewire-x/update', 'POST', [
        'components' => [['snapshot' => $snapshot], ['snapshot' => 'not json'], 'junk'],
    ]);
    $request->headers->set('X-Livewire', 'true');

    expect(LivewireTamperingReporter::components($request))
        ->toBe(['competitions @ /lv', '? @ /?', '? @ /?']);
});

it('has no component context without the Livewire header', function (): void {
    expect(LivewireTamperingReporter::components(Request::create('/', 'POST', ['components' => []])))->toBe([]);
});

it('adds the Livewire components to the log context of every error', function (): void {
    $request = Request::create('/livewire-x/update', 'POST', [
        'components' => [['snapshot' => json_encode(['memo' => ['name' => 'probe', 'path' => '/']])]],
    ]);
    $request->headers->set('X-Livewire', 'true');

    app()->instance('request', $request);

    $handler = resolve(ExceptionHandler::class);
    $context = (fn (Throwable $e): array => $this->buildExceptionContext($e))->call($handler, new Exception('x'));

    expect($context['livewire_components'])->toBe(['probe @ /']);
});

it('stays off when disabled in config', function (): void {
    config()->set('telegram-alerts.livewire_tampering', false);
    $handler = Mockery::mock(ExceptionHandler::class);
    app()->instance(ExceptionHandler::class, $handler);

    new TelegramAlertsServiceProvider(app())->boot();

    $handler->shouldNotHaveReceived('reportable');
});

it("leaves an exception handler that is not Laravel's own alone", function (): void {
    $handler = Mockery::mock(ExceptionHandler::class);
    app()->instance(ExceptionHandler::class, $handler);

    new TelegramAlertsServiceProvider(app())->boot();

    $handler->shouldNotHaveReceived('reportable');
});
