<?php

declare(strict_types=1);

namespace FancyHeuristics\Tests\Support;

use FancyHeuristics\Contracts\HostResolver;

/**
 * Deterministic DNS for tests. Unmapped hosts resolve to a public address so
 * the existing *.test fixtures keep working without live lookups; any test
 * about a private answer maps its host explicitly.
 */
class FakeHostResolver implements HostResolver
{
    public const PUBLIC_IP = '93.184.216.34';

    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  array<string, list<string>>  $map
     */
    public function __construct(public array $map = []) {}

    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return $this->map[strtolower($host)] ?? [self::PUBLIC_IP];
    }
}
