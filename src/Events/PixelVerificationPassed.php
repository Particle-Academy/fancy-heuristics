<?php

declare(strict_types=1);

namespace FancyHeuristics\Events;

use FancyHeuristics\Models\HeuristicsSite;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a site's Fancy UI pixel is detected during verification.
 * Hosts listen to (re)show the listing.
 */
class PixelVerificationPassed
{
    use Dispatchable, SerializesModels;

    public function __construct(public HeuristicsSite $site) {}
}
