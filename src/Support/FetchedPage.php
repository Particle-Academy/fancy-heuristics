<?php

declare(strict_types=1);

namespace FancyHeuristics\Support;

/**
 * The final response of a page fetch, after any redirects were followed.
 */
final readonly class FetchedPage
{
    public function __construct(
        public int $status,
        public string $body,
        public string $url,
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
