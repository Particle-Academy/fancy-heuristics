<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Contracts\HostResolver;
use FancyHeuristics\Contracts\PageFetcher;
use FancyHeuristics\Exceptions\UnsafeUrlException;
use FancyHeuristics\Support\CappedBuffer;
use FancyHeuristics\Support\FetchedPage;
use FancyHeuristics\Support\IpPolicy;
use Illuminate\Support\Facades\Http;

/**
 * SSRF-safe fetch of a registered site's page.
 *
 * The URL comes from whoever registered the site, and the verifier runs it on
 * a schedule from the host's own network, so every guard here is about what
 * an anonymous registrant can make the SERVER request:
 *
 *  - http and https only; no credentials in the URL.
 *  - The host is a canonical IP literal or a hostname. Decimal, octal, hex and
 *    short IPv4 spellings are refused outright rather than interpreted.
 *  - A hostname is resolved and EVERY address must be public (IpPolicy):
 *    no loopback, RFC 1918, link-local / cloud metadata, CGNAT, multicast,
 *    reserved, unspecified, IPv6 ULA / link-local, or IPv4-mapped/NAT64/6to4
 *    forms of any of those. An unresolvable host is refused.
 *  - DNS rebinding: the connection is pinned to the addresses that were just
 *    validated, via CURLOPT_RESOLVE. curl does not look the name up again, so
 *    a second, different DNS answer cannot redirect the connect. TLS SNI and
 *    certificate checks still use the hostname.
 *  - Redirects are never followed automatically. Up to `max_redirects` hops
 *    are followed by hand, and each hop goes through every check above and
 *    gets its own pin.
 *  - The body is capped at `max_bytes`: a declared Content-Length over the
 *    cap aborts at the headers, and an undeclared (chunked) body aborts once
 *    the cap is reached (CappedBuffer). The timeout is kept for both the
 *    connect and the whole request.
 *
 * Residual gaps, stated rather than claimed closed:
 *  - The pin is a cURL option. If the host app routes Laravel's HTTP client
 *    through a non-cURL handler, the pin is ignored; when the cURL extension
 *    is missing entirely, this class refuses to fetch rather than fetch
 *    unpinned.
 *  - If the process has an HTTP proxy configured (HTTP_PROXY / HTTPS_PROXY),
 *    the proxy does its own DNS resolution and the pin no longer governs the
 *    final connect. The address checks still run; the proxy's own egress
 *    policy is what then applies.
 */
class SafePageFetcher implements PageFetcher
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        protected HostResolver $resolver,
        protected ?int $timeout = null,
        protected ?int $maxBytes = null,
        protected ?int $maxRedirects = null,
        protected ?string $userAgent = null,
    ) {}

    public function fetch(string $url): FetchedPage
    {
        if (! function_exists('curl_init')) {
            throw new UnsafeUrlException('the cURL extension is required to pin the connection; refusing to fetch unpinned');
        }

        $maxRedirects = $this->maxRedirects ?? (int) config('heuristics.verify.max_redirects', 3);
        $current = $url;

        for ($hop = 0; ; $hop++) {
            [$target, $curl] = $this->prepare($current);

            [$status, $location, $body] = $this->request($target, $curl);

            if (in_array($status, self::REDIRECT_STATUSES, true) && $location !== '') {
                if ($hop >= $maxRedirects) {
                    throw new UnsafeUrlException("more than {$maxRedirects} redirects");
                }

                $current = $this->resolveLocation($target, $location);

                continue;
            }

            return new FetchedPage($status, $body, $target);
        }
    }

    /**
     * Validate a URL and return the exact URL to request plus the cURL options
     * that pin it. The URL is rebuilt from its validated parts so that what was
     * checked is byte-for-byte what is sent.
     *
     * @return array{0: string, 1: array<int, mixed>}
     *
     * @throws UnsafeUrlException
     */
    public function prepare(string $url): array
    {
        // Whitespace, control bytes and backslashes are where URL parsers
        // disagree with each other; none belongs in a site URL.
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new UnsafeUrlException('the URL contains whitespace, control characters or a backslash');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeUrlException('the URL is malformed');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException("the {$scheme} scheme is not allowed; only http and https");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('credentials in the URL are not allowed');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $host = strtolower($parts['host']);
        $curl = [];

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ip = substr($host, 1, -1);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new UnsafeUrlException('the URL host is not a valid IPv6 address');
            }
            $this->assertPublic([$ip]);
            $urlHost = '['.$ip.']';
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $this->assertPublic([$host]);
            $urlHost = $host;
        } else {
            $host = rtrim($host, '.');

            if (preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/', $host) !== 1) {
                throw new UnsafeUrlException('the URL host is not a valid hostname (use the punycode form for IDNs)');
            }

            // A host whose last label is a number is an IPv4 address in a
            // non-canonical spelling (2130706433, 0177.0.0.1, 0x7f.1, 127.1).
            // Resolvers disagree on how to read these, so none is accepted.
            $labels = explode('.', $host);
            if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) end($labels)) === 1) {
                throw new UnsafeUrlException('the URL host is an IP address in a non-canonical form');
            }

            $ips = $this->resolver->resolve($host);
            if ($ips === []) {
                throw new UnsafeUrlException('the URL host does not resolve');
            }

            $this->assertPublic($ips);

            $pinned = array_map(
                fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip,
                array_values(array_unique($ips)),
            );
            $curl[CURLOPT_RESOLVE] = ["{$host}:{$port}:".implode(',', $pinned)];
            $urlHost = $host;
        }

        $target = $scheme.'://'.$urlHost
            .(isset($parts['port']) ? ':'.$port : '')
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');

        return [$target, $curl];
    }

    /**
     * @param  list<string>  $ips
     *
     * @throws UnsafeUrlException
     */
    protected function assertPublic(array $ips): void
    {
        foreach ($ips as $ip) {
            if (! IpPolicy::isPublic($ip)) {
                throw new UnsafeUrlException('the URL resolves to a private, loopback, link-local or reserved address');
            }
        }
    }

    /**
     * @param  array<int, mixed>  $curl
     * @return array{0: int, 1: string, 2: string}
     */
    protected function request(string $target, array $curl): array
    {
        $timeout = $this->timeout ?? (int) config('heuristics.verify.timeout', 15);
        $maxBytes = $this->maxBytes ?? (int) config('heuristics.verify.max_bytes', 2_000_000);
        $userAgent = $this->userAgent ?? (string) config('heuristics.verify.user_agent', 'FancyHeuristics-PixelVerifier/1.0');

        [$sink, $state] = CappedBuffer::open($maxBytes);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => $userAgent])
                ->withOptions([
                    'curl' => $curl,
                    'sink' => $sink,
                    'on_headers' => function ($response) use ($maxBytes): void {
                        $declared = $response->getHeaderLine('Content-Length');
                        if ($declared !== '' && (int) $declared > $maxBytes) {
                            throw new UnsafeUrlException("the response declares more than {$maxBytes} bytes");
                        }
                    },
                ])
                ->get($target);

            $body = (string) $response->body();
        } catch (\Throwable $e) {
            if ($state->exceeded) {
                throw new UnsafeUrlException("the response exceeded {$maxBytes} bytes", previous: $e);
            }

            throw $e;
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        // The cap is enforced on the wire above; this also covers a host
        // whose HTTP stack does not write through the sink.
        if ($state->exceeded || strlen($body) > $maxBytes) {
            throw new UnsafeUrlException("the response exceeded {$maxBytes} bytes");
        }

        return [$response->status(), trim((string) $response->header('Location')), $body];
    }

    /**
     * Resolve a Location header against the URL that returned it. The result
     * goes back through prepare(), so this only has to be correct, not safe.
     */
    protected function resolveLocation(string $base, string $location): string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$authority.$location;
        }

        $path = $parts['path'] ?? '/';

        if (str_starts_with($location, '?')) {
            return $scheme.'://'.$authority.$path.$location;
        }

        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $scheme.'://'.$authority.$dir.$location;
    }
}
