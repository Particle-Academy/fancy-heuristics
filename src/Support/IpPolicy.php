<?php

declare(strict_types=1);

namespace FancyHeuristics\Support;

/**
 * Decides whether an IP address is a public, globally routable unicast
 * address that an SSRF-exposed fetch may connect to.
 *
 * Deliberately explicit rather than leaning on PHP's FILTER_FLAG_NO_PRIV_RANGE
 * / FILTER_FLAG_NO_RES_RANGE alone: those flags do not cover CGNAT
 * (100.64.0.0/10), and their IPv6 coverage of embedded-IPv4 forms has varied
 * across PHP releases. The filter flags are still applied as an extra AND, so
 * this can only ever be stricter than PHP's own view.
 */
final class IpPolicy
{
    /** @var list<array{string, int}> IPv4 blocks that are never public */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],         // "this network", incl. 0.0.0.0 unspecified
        ['10.0.0.0', 8],        // RFC 1918
        ['100.64.0.0', 10],     // CGNAT shared address space (RFC 6598)
        ['127.0.0.0', 8],       // loopback
        ['169.254.0.0', 16],    // link-local, incl. 169.254.169.254 cloud metadata
        ['172.16.0.0', 12],     // RFC 1918
        ['192.0.0.0', 24],      // IETF protocol assignments
        ['192.0.2.0', 24],      // TEST-NET-1
        ['192.31.196.0', 24],   // AS112-v4
        ['192.52.193.0', 24],   // AMT
        ['192.88.99.0', 24],    // 6to4 relay anycast (deprecated)
        ['192.168.0.0', 16],    // RFC 1918
        ['192.175.48.0', 24],   // direct delegation AS112
        ['198.18.0.0', 15],     // benchmarking
        ['198.51.100.0', 24],   // TEST-NET-2
        ['203.0.113.0', 24],    // TEST-NET-3
        ['224.0.0.0', 4],       // multicast
        ['240.0.0.0', 4],       // reserved, incl. 255.255.255.255 broadcast
    ];

    /** @var list<array{string, int}> IPv6 blocks inside 2000::/3 that are never public */
    private const BLOCKED_V6 = [
        ['2001::', 23],         // IETF protocol assignments, incl. Teredo 2001::/32
        ['2001:db8::', 32],     // documentation
        ['3fff::', 20],         // documentation (RFC 9637)
    ];

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }

        $explicit = strlen($bin) === 4 ? self::isPublicV4($bin) : self::isPublicV6($bin);

        return $explicit
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function isPublicV4(string $bin): bool
    {
        foreach (self::BLOCKED_V4 as [$net, $bits]) {
            if (self::inCidr($bin, (string) inet_pton($net), $bits)) {
                return false;
            }
        }

        return true;
    }

    private static function isPublicV6(string $bin): bool
    {
        // ::ffff:a.b.c.d (IPv4-mapped) — judge the IPv4 address it carries.
        if (self::inCidr($bin, (string) inet_pton('::ffff:0:0'), 96)) {
            return self::isPublicV4(substr($bin, 12, 4));
        }

        // 64:ff9b::/96 (NAT64 well-known prefix) — reaches the embedded IPv4.
        if (self::inCidr($bin, (string) inet_pton('64:ff9b::'), 96)) {
            return self::isPublicV4(substr($bin, 12, 4));
        }

        // 2002::/16 (6to4) — reaches the IPv4 in bits 16..47.
        if (self::inCidr($bin, (string) inet_pton('2002::'), 16)) {
            return self::isPublicV4(substr($bin, 2, 4));
        }

        // Only global unicast (2000::/3) can be public. This alone refuses
        // ::, ::1, IPv4-compatible ::/96, 64:ff9b:1::/48, 100::/64,
        // fc00::/7 ULA, fe80::/10 link-local, fec0::/10 site-local and
        // ff00::/8 multicast.
        if (! self::inCidr($bin, (string) inet_pton('2000::'), 3)) {
            return false;
        }

        foreach (self::BLOCKED_V6 as [$net, $bits]) {
            if (self::inCidr($bin, (string) inet_pton($net), $bits)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $bin, string $net, int $bits): bool
    {
        if (strlen($bin) !== strlen($net)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (strncmp($bin, $net, $bytes) !== 0) {
            return false;
        }

        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
