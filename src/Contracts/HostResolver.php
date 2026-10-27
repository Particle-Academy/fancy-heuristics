<?php

declare(strict_types=1);

namespace FancyHeuristics\Contracts;

/**
 * Resolves a hostname to EVERY address it answers with. The safe fetcher
 * refuses a host if any one of them is non-public, so an implementation must
 * not stop at the first record.
 *
 * Returning an empty list means "could not resolve"; the fetcher treats that
 * as a refusal, never as permission.
 */
interface HostResolver
{
    /**
     * @return list<string> IPv4 / IPv6 address literals
     */
    public function resolve(string $host): array;
}
