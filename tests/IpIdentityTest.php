<?php

declare(strict_types=1);

use Rozkalns\TelegramAlerts\Support\IpIdentity;
use Rozkalns\TelegramAlerts\Support\IpIdentityResult;
use Tests\FakeResolver;

function identity(?FakeResolver $resolver = null, int $budgetMs = 1000): IpIdentity
{
    return new IpIdentity($resolver ?? fakeResolver(), budgetMs: $budgetMs);
}

it('identifies a verified googlebot from forward-confirmed reverse dns', function (): void {
    $result = identity(googlebotResolver())->identify('66.249.68.38');

    expect($result->bot)->toBe('Googlebot')
        ->and($result->verified)->toBeTrue()
        ->and($result->isVerifiedBot())->toBeTrue()
        ->and($result->hostname)->toBe('crawl-66-249-68-38.googlebot.com')
        ->and($result->asn)->toBe('AS15169')
        ->and($result->organisation)->toBe('GOOGLE')
        ->and($result->label())->toBe('Googlebot (AS15169 GOOGLE) · verified');
});

it('refuses to verify a crawler whose forward lookup does not come back to the same ip', function (): void {
    $resolver = googlebotResolver();
    $resolver->addresses['crawl-66-249-68-38.googlebot.com'] = ['1.2.3.4'];

    $result = identity($resolver)->identify('66.249.68.38');

    expect($result->bot)->toBe('Googlebot')
        ->and($result->verified)->toBeFalse()
        ->and($result->isVerifiedBot())->toBeFalse()
        ->and($result->label())->toContain('claims Googlebot')
        ->and($result->label())->toContain('unverified');
});

it('ignores an unresolvable address while forward-confirming', function (): void {
    $resolver = googlebotResolver();
    $resolver->addresses['crawl-66-249-68-38.googlebot.com'] = ['not-an-ip', '66.249.68.38'];

    expect(identity($resolver)->identify('66.249.68.38')->verified)->toBeTrue();
});

it('treats a spoofed googlebot user agent as a claim, never as a fact', function (): void {
    $resolver = fakeResolver();
    $resolver->ptr['1.2.0.192.in-addr.arpa'] = 'host.spoofer.example';

    $result = identity($resolver)->identify(
        '192.0.2.1',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    );

    expect($result->bot)->toBe('Googlebot')
        ->and($result->verified)->toBeFalse()
        ->and($result->isVerifiedBot())->toBeFalse()
        ->and($result->label())->toStartWith('claims Googlebot');
});

it('recognises bingbot by its ptr suffix', function (): void {
    $resolver = fakeResolver();
    $resolver->ptr['7.6.5.4.in-addr.arpa'] = 'msnbot-4-5-6-7.search.msn.com';
    $resolver->addresses['msnbot-4-5-6-7.search.msn.com'] = ['4.5.6.7'];

    $result = identity($resolver)->identify('4.5.6.7');

    expect($result->bot)->toBe('Bingbot')->and($result->verified)->toBeTrue();
});

it('recognises a bingbot user agent claim', function (): void {
    $result = identity()->identify('192.0.2.1', 'Mozilla/5.0 (compatible; bingbot/2.0)');

    expect($result->bot)->toBe('Bingbot')->and($result->verified)->toBeFalse();
});

it('does not let a lookalike hostname pass as a crawler', function (string $hostname): void {
    $resolver = fakeResolver();
    $resolver->ptr['1.2.0.192.in-addr.arpa'] = $hostname;

    expect(identity($resolver)->identify('192.0.2.1')->bot)->toBeNull();
})->with([
    'notgooglebot.com',
    'googlebot.com.evil.example',
    'fake-google.com',
]);

it('matches a bare crawler domain as well as a subdomain', function (): void {
    $resolver = fakeResolver();
    $resolver->ptr['1.2.0.192.in-addr.arpa'] = 'GoogleBot.com';
    $resolver->addresses['GoogleBot.com'] = ['192.0.2.1'];

    expect(identity($resolver)->identify('192.0.2.1')->bot)->toBe('Googlebot');
});

it('reports a cloudflare edge address as a misconfiguration, not as a caller', function (): void {
    $result = identity()->identify('172.68.1.1');

    expect($result->edge)->toBeTrue()
        ->and($result->bot)->toBeNull()
        ->and($result->isVerifiedBot())->toBeFalse()
        ->and($result->label())->toBe('Cloudflare edge IP — real-client-IP not configured')
        ->and(fakeResolver()->queries)->toBe([]);
});

it('returns an empty identity for something that is not an ip', function (): void {
    $result = identity()->identify('unknown');

    expect($result->label())->toBeNull()
        ->and($result->ip)->toBe('unknown')
        ->and(fakeResolver()->queries)->toBe([]);
});

it('reverses ipv6 by nibble against the origin6 zone', function (): void {
    $resolver = fakeResolver();
    $reversed = '0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2';

    $resolver->ptr[$reversed.'.ip6.arpa'] = 'v6.example.org';
    $resolver->txt[$reversed.'.origin6.asn.cymru.com'] = ['64496 | 2001:db8::/32 | US | arin | 2010-01-01'];
    $resolver->txt['AS64496.asn.cymru.com'] = ['64496 | US | arin | 2010-01-01 | EXAMPLE-V6, US'];

    $result = identity($resolver)->identify('2001:db8::');

    expect($result->hostname)->toBe('v6.example.org')
        ->and($result->asn)->toBe('AS64496')
        ->and($result->organisation)->toBe('EXAMPLE-V6')
        ->and($result->label())->toBe('v6.example.org (AS64496 EXAMPLE-V6)');
});

it('takes the first asn when a prefix has several origins', function (): void {
    $resolver = fakeResolver();
    $resolver->txt['1.2.0.192.origin.asn.cymru.com'] = ['23028 393950 | 192.0.2.0/24 | US | arin | 2006-09-13'];
    $resolver->txt['AS23028.asn.cymru.com'] = ['23028 | US | arin | 1998-01-01 | TEAM-CYMRU, US'];

    expect(identity($resolver)->identify('192.0.2.1')->asn)->toBe('AS23028');
});

it('keeps an organisation name that carries no country suffix', function (): void {
    $resolver = fakeResolver();
    $resolver->txt['1.2.0.192.origin.asn.cymru.com'] = ['64496 | 192.0.2.0/24 | US | arin | 2006-09-13'];
    $resolver->txt['AS64496.asn.cymru.com'] = ['64496 | US | arin | 2010-01-01 | SOLONAME'];

    expect(identity($resolver)->identify('192.0.2.1')->organisation)->toBe('SOLONAME');
});

it('reports the asn alone when the organisation record is empty', function (): void {
    $resolver = fakeResolver();
    $resolver->txt['1.2.0.192.origin.asn.cymru.com'] = ['64496 | 192.0.2.0/24 | US | arin | 2006-09-13'];
    $resolver->txt['AS64496.asn.cymru.com'] = ['64496 | US | arin | 2010-01-01 | '];

    $result = identity($resolver)->identify('192.0.2.1');

    expect($result->organisation)->toBeNull()->and($result->label())->toBe('AS64496');
});

it('reports the asn alone when no organisation record exists', function (): void {
    $resolver = fakeResolver();
    $resolver->txt['1.2.0.192.origin.asn.cymru.com'] = ['64496 | 192.0.2.0/24 | US | arin | 2006-09-13'];

    expect(identity($resolver)->identify('192.0.2.1')->label())->toBe('AS64496');
});

it('ignores an origin record whose asn field is not a number', function (): void {
    $resolver = fakeResolver();
    $resolver->txt['1.2.0.192.origin.asn.cymru.com'] = ['no-asn-here | 192.0.2.0/24'];

    $result = identity($resolver)->identify('192.0.2.1');

    expect($result->asn)->toBeNull()->and($result->label())->toBeNull();
});

it('yields nothing at all when every lookup comes back empty', function (): void {
    expect(identity()->identify('192.0.2.1')->label())->toBeNull();
});

it('abandons the remaining lookups once the budget is spent', function (): void {
    $resolver = googlebotResolver();

    $result = identity($resolver, budgetMs: 0)->identify('66.249.68.38');

    expect($result->hostname)->toBe('crawl-66-249-68-38.googlebot.com')
        ->and($result->verified)->toBeFalse()
        ->and($result->asn)->toBeNull()
        ->and($resolver->queries)->toBe(['38.68.249.66.in-addr.arpa']);
});

it('keeps the asn but skips the organisation when the budget runs out mid-lookup', function (): void {
    $resolver = fakeResolver();
    $resolver->txt['1.2.0.192.origin.asn.cymru.com'] = ['64496 | 192.0.2.0/24 | US | arin | 2006-09-13'];
    $resolver->txt['AS64496.asn.cymru.com'] = ['64496 | US | arin | 2010-01-01 | EXAMPLE, US'];

    $resolver->delays['1.2.0.192.origin.asn.cymru.com'] = 30_000;

    $result = identity($resolver, budgetMs: 10)->identify('192.0.2.1');

    expect($result->asn)->toBe('AS64496')
        ->and($result->organisation)->toBeNull()
        ->and($resolver->queries)->not->toContain('AS64496.asn.cymru.com');
});

it('caches the dns facts per ip', function (): void {
    $resolver = googlebotResolver();
    $identity = identity($resolver);

    $identity->identify('66.249.68.38');

    $first = count($resolver->queries);

    $identity->identify('66.249.68.38');

    expect($resolver->queries)->toHaveCount($first)
        ->and($first)->toBeGreaterThan(0);
});

it('does not fragment the cache by user agent', function (): void {
    $resolver = googlebotResolver();
    $identity = identity($resolver);

    $identity->identify('66.249.68.38', 'agent-one');
    $identity->identify('66.249.68.38', 'agent-two');

    expect($resolver->queries)->toHaveCount(4);
});

it('builds a result with no identity at all', function (): void {
    $result = new IpIdentityResult('192.0.2.1');

    expect($result->label())->toBeNull()
        ->and($result->isVerifiedBot())->toBeFalse()
        ->and($result->verified)->toBeFalse()
        ->and($result->edge)->toBeFalse();
});

it('labels an organisation known without an asn', function (): void {
    $result = new IpIdentityResult('192.0.2.1', organisation: 'EXAMPLE');

    expect($result->label())->toBe('EXAMPLE');
});
