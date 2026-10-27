<?php

declare(strict_types=1);

use FancyHeuristics\Contracts\HostResolver;
use FancyHeuristics\Contracts\PageFetcher;
use FancyHeuristics\Events\PixelVerificationFailed;
use FancyHeuristics\Models\HeuristicsSite;
use FancyHeuristics\Services\PixelVerifier;
use FancyHeuristics\Support\FetchedPage;
use FancyHeuristics\Tests\Support\FakeHostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * The site URL is supplied by whoever registers the site, so the verifier is
 * an SSRF primitive unless it refuses non-public targets. Every refusal test
 * fakes a PASSING page for every URL: if the guard is missing the verifier
 * fetches it, sees the marker and returns true, so the test fails loudly.
 */

const SSRF_MARKER = '<html><body><div data-fancy-badge></div></body></html>';

function ssrfSite(string $url): HeuristicsSite
{
    return HeuristicsSite::create([
        'site_key' => 'k'.substr(md5($url.microtime()), 0, 10),
        'url' => $url,
        'visible' => false,
    ]);
}

function mapHosts(array $map): FakeHostResolver
{
    $resolver = new FakeHostResolver($map);
    app()->instance(HostResolver::class, $resolver);

    return $resolver;
}

function expectRefused(HeuristicsSite $site): void
{
    expect(app(PixelVerifier::class)->verify($site))->toBeFalse();

    $site->refresh();
    expect($site->visible)->toBeFalse();
    expect($site->pixel_status)->toBe('failed');
}

function fakePassingEverywhere(): void
{
    Http::fake(['*' => Http::response(SSRF_MARKER, 200)]);
}

it('refuses a non-http scheme', function (string $url) {
    fakePassingEverywhere();
    expectRefused(ssrfSite($url));
    Http::assertNothingSent();
})->with([
    'ftp' => 'ftp://acme.test/',
    'gopher' => 'gopher://acme.test:70/_x',
    'file' => 'file:///etc/passwd',
    'dict' => 'dict://acme.test:11211/stat',
]);

it('refuses a literal non-public IPv4 address', function (string $url) {
    fakePassingEverywhere();
    expectRefused(ssrfSite($url));
    Http::assertNothingSent();
})->with([
    'loopback' => 'http://127.0.0.1/',
    'loopback block' => 'http://127.8.9.10/',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'link-local' => 'http://169.254.1.1/',
    'rfc1918 10/8' => 'http://10.0.0.1/',
    'rfc1918 172.16/12' => 'http://172.16.5.4/',
    'rfc1918 192.168/16' => 'http://192.168.1.1/',
    'cgnat 100.64/10' => 'http://100.64.0.1/',
    'unspecified' => 'http://0.0.0.0/',
    'this network 0/8' => 'http://0.1.2.3/',
    'multicast' => 'http://224.0.0.1/',
    'reserved 240/4' => 'http://240.0.0.1/',
    'broadcast' => 'http://255.255.255.255/',
    'benchmarking' => 'http://198.18.0.1/',
    'documentation' => 'http://192.0.2.10/',
]);

it('refuses a literal non-public IPv6 address', function (string $url) {
    fakePassingEverywhere();
    expectRefused(ssrfSite($url));
    Http::assertNothingSent();
})->with([
    'loopback' => 'http://[::1]/',
    'unspecified' => 'http://[::]/',
    'ipv4-mapped loopback' => 'http://[::ffff:127.0.0.1]/',
    'ipv4-mapped metadata' => 'http://[::ffff:169.254.169.254]/',
    'ipv4-mapped hex form' => 'http://[::ffff:7f00:1]/',
    'ipv4-compatible' => 'http://[::127.0.0.1]/',
    'nat64 of private' => 'http://[64:ff9b::a00:1]/',
    '6to4 of private' => 'http://[2002:a00:1::1]/',
    'unique local' => 'http://[fd00::1]/',
    'link-local' => 'http://[fe80::1]/',
    'site-local' => 'http://[fec0::1]/',
    'multicast' => 'http://[ff02::1]/',
    'documentation' => 'http://[2001:db8::1]/',
    'teredo' => 'http://[2001::1]/',
    'zone id' => 'http://[fe80::1%25eth0]/',
]);

it('refuses decimal, octal, hex and short IPv4 spellings', function (string $url) {
    fakePassingEverywhere();
    expectRefused(ssrfSite($url));
    Http::assertNothingSent();
})->with([
    'decimal' => 'http://2130706433/',
    'octal' => 'http://0177.0.0.1/',
    'hex' => 'http://0x7f.0.0.1/',
    'hex dword' => 'http://0x7f000001/',
    'short form' => 'http://127.1/',
    'trailing dot' => 'http://127.0.0.1./',
]);

it('refuses a hostname that resolves to a non-public address', function (array $answers) {
    fakePassingEverywhere();
    mapHosts(['internal.test' => $answers]);

    expectRefused(ssrfSite('https://internal.test/'));
    Http::assertNothingSent();
})->with([
    'rfc1918' => [['10.0.0.5']],
    'loopback' => [['127.0.0.1']],
    'metadata' => [['169.254.169.254']],
    'ipv6 loopback' => [['::1']],
    'ipv6 ula' => [['fd12:3456::1']],
    'ipv4-mapped' => [['::ffff:10.0.0.1']],
]);

it('checks EVERY resolved address, not just the first', function () {
    fakePassingEverywhere();
    mapHosts(['mixed.test' => [FakeHostResolver::PUBLIC_IP, '2606:4700::1111', '127.0.0.1']]);

    expectRefused(ssrfSite('https://mixed.test/'));
    Http::assertNothingSent();
});

it('refuses a hostname that does not resolve at all', function () {
    fakePassingEverywhere();
    mapHosts(['nowhere.test' => []]);

    expectRefused(ssrfSite('https://nowhere.test/'));
    Http::assertNothingSent();
});

it('refuses credentials embedded in the URL', function () {
    fakePassingEverywhere();
    expectRefused(ssrfSite('http://acme.test@127.0.0.1/'));
    Http::assertNothingSent();
});

it('refuses a redirect to 127.0.0.1 and never requests it', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'hop.test')) {
            return Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']);
        }

        return Http::response(SSRF_MARKER, 200);
    });

    expectRefused(ssrfSite('https://hop.test/'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '127.0.0.1'));
});

it('refuses a redirect to a hostname that resolves privately', function () {
    mapHosts(['metadata.test' => ['169.254.169.254']]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'hop.test')) {
            return Http::response('', 301, ['Location' => 'http://metadata.test/latest/meta-data/']);
        }

        return Http::response(SSRF_MARKER, 200);
    });

    expectRefused(ssrfSite('https://hop.test/'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'metadata.test'));
});

it('refuses a redirect to an IPv6 loopback', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'hop.test')) {
            return Http::response('', 307, ['Location' => 'http://[::1]:8080/internal']);
        }

        return Http::response(SSRF_MARKER, 200);
    });

    expectRefused(ssrfSite('https://hop.test/'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '[::1]'));
});

// Guzzle's automatic redirects already refused non-http(s) targets, so this
// one passed before the fix too. It stays because redirects are now followed
// by hand, which makes the scheme check on each hop ours to keep.
it('refuses a redirect to a non-http scheme', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'hop.test')) {
            return Http::response('', 302, ['Location' => 'gopher://acme.test:70/_x']);
        }

        return Http::response(SSRF_MARKER, 200);
    });

    expectRefused(ssrfSite('https://hop.test/'));
    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'gopher:'));
});

it('stops after the configured number of redirects', function () {
    config()->set('heuristics.verify.max_redirects', 2);

    Http::fake(function (Request $request) {
        $n = (int) (parse_url($request->url(), PHP_URL_QUERY) ? substr(parse_url($request->url(), PHP_URL_QUERY), 2) : 0);

        if ($n < 3) {
            return Http::response('', 302, ['Location' => 'https://loop.test/?n='.($n + 1)]);
        }

        return Http::response(SSRF_MARKER, 200);
    });

    expectRefused(ssrfSite('https://loop.test/'));
    Http::assertSentCount(3);
});

it('refuses a response larger than the size cap', function () {
    config()->set('heuristics.verify.max_bytes', 1024);

    Http::fake(['*' => Http::response(SSRF_MARKER.str_repeat('x', 4096), 200)]);

    Event::fake([PixelVerificationFailed::class]);

    expectRefused(ssrfSite('https://huge.test/'));

    Event::assertDispatched(PixelVerificationFailed::class, fn ($e) => str_contains($e->reason, 'bytes'));
});

it('pins the connection to the validated address and disables automatic redirects', function () {
    $seen = null;

    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response(SSRF_MARKER, 200);
    });

    mapHosts(['pinned.test' => ['93.184.216.34', '2606:2800:220:1::1']]);

    expect(app(PixelVerifier::class)->verify(ssrfSite('https://pinned.test/page')))->toBeTrue();

    expect($seen['allow_redirects'])->toBeFalse();
    expect($seen['curl'][CURLOPT_RESOLVE])->toBe(['pinned.test:443:93.184.216.34,[2606:2800:220:1::1]']);
    expect($seen['timeout'])->toBe(15);
});

it('fetches an allowed public URL and follows a public redirect manually', function () {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://public.test/') {
            return Http::response('', 302, ['Location' => '/home']);
        }
        if ($request->url() === 'https://public.test/home') {
            return Http::response(SSRF_MARKER, 200);
        }

        return Http::response('wrong url', 404);
    });

    $resolver = mapHosts([]);

    $site = ssrfSite('https://public.test/');
    expect(app(PixelVerifier::class)->verify($site))->toBeTrue();
    expect($site->refresh()->pixel_status)->toBe('passed');

    Http::assertSentCount(2);
    // Each hop was resolved and checked, not just the first.
    expect($resolver->asked)->toContain('public.test');
    expect(count($resolver->asked))->toBeGreaterThanOrEqual(2);
});

it('uses a host-supplied fetcher class from config', function () {
    Http::fake(['*' => Http::response('no badge', 200)]);

    config()->set('heuristics.verify.fetcher', HostPolicyFetcher::class);

    expect(app(PixelVerifier::class)->verify(ssrfSite('http://10.0.0.7/')))->toBeTrue();
    expect(HostPolicyFetcher::$fetched)->toBe(['http://10.0.0.7/']);
    Http::assertNothingSent();
});

it('uses a fetcher rebound on the PageFetcher contract', function () {
    Http::fake(['*' => Http::response('no badge', 200)]);
    HostPolicyFetcher::$fetched = [];

    app()->instance(PageFetcher::class, new HostPolicyFetcher);

    expect(app(PixelVerifier::class)->verify(ssrfSite('http://intranet.local/')))->toBeTrue();
    expect(HostPolicyFetcher::$fetched)->toBe(['http://intranet.local/']);
});

class HostPolicyFetcher implements PageFetcher
{
    /** @var list<string> */
    public static array $fetched = [];

    public function fetch(string $url): FetchedPage
    {
        self::$fetched[] = $url;

        return new FetchedPage(200, SSRF_MARKER, $url);
    }
}
