<?php

declare(strict_types=1);

namespace FancyHeuristics\Support;

use FancyHeuristics\Contracts\HostResolver;

/**
 * Default resolver: the union of A + AAAA records from DNS and the system
 * resolver's IPv4 answer (which also honours /etc/hosts). Taking the union,
 * not the first source that answers, is what lets the fetcher refuse a host
 * if ANY of its addresses is non-public.
 */
class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (! empty($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
                if (! empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        $system = @gethostbynamel($host);
        if (is_array($system)) {
            array_push($ips, ...$system);
        }

        return array_values(array_unique($ips));
    }
}
