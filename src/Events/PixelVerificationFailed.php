<?php

declare(strict_types=1);

namespace FancyHeuristics\Events;

use FancyHeuristics\Models\HeuristicsSite;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a site's Fancy UI pixel is missing during verification (or the
 * fetch failed). Hosts listen to hide the listing.
 */
class PixelVerificationFailed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $reason  Why verification failed (e.g. "marker missing", "HTTP 404").
     */
    public function __construct(
        public HeuristicsSite $site,
        public string $reason = 'marker missing',
    ) {}
}
