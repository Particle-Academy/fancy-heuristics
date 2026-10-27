<?php

declare(strict_types=1);

namespace FancyHeuristics\Contracts;

use FancyHeuristics\Support\FetchedPage;

/**
 * Fetches a registered site's page for pixel verification.
 *
 * The URL is supplied by whoever registered the site, so it is attacker
 * controlled. The default implementation, SafePageFetcher, refuses anything
 * that is not a public http(s) address and re-checks every redirect hop.
 *
 * A host that needs a different policy (an egress proxy, an allowlist, a
 * staging network) binds its own implementation — either by setting
 * `heuristics.verify.fetcher` to a class name or by rebinding this contract
 * in the container. Whatever it binds owns the SSRF guarantees from then on.
 */
interface PageFetcher
{
    /**
     * @throws \Throwable when the page cannot or must not be fetched; the
     *                    verifier records any throw as a failed verification.
     */
    public function fetch(string $url): FetchedPage;
}
