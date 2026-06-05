<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Events\PixelVerificationFailed;
use FancyHeuristics\Events\PixelVerificationPassed;
use FancyHeuristics\Models\HeuristicsSite;
use Illuminate\Support\Facades\Http;

/**
 * Server-side re-poll of a registered site. Fetches the page HTML, runs the
 * shared HeuristicsPixelDetector (data-fancy-badge OR "Powered by Fancy UI"),
 * persists the result on the HeuristicsSite, and fires
 * PixelVerificationPassed / PixelVerificationFailed so hosts can toggle a
 * listing's visibility.
 */
class PixelVerifier
{
    public function __construct(
        protected HeuristicsPixelDetector $detector,
    ) {}

    /**
     * Verify a single site. Returns true when the pixel was detected.
     */
    public function verify(HeuristicsSite $site): bool
    {
        $timeout = (int) config('heuristics.verify.timeout', 15);
        $userAgent = (string) config('heuristics.verify.user_agent', 'FancyHeuristics-PixelVerifier/1.0');

        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => $userAgent])
                ->get($site->url);
        } catch (\Throwable $e) {
            return $this->fail($site, 'fetch failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return $this->fail($site, 'HTTP '.$response->status());
        }

        $detected = $this->detector->detect((string) $response->body());

        return $detected
            ? $this->pass($site)
            : $this->fail($site, 'marker missing');
    }

    protected function pass(HeuristicsSite $site): bool
    {
        $site->forceFill([
            'visible' => true,
            'pixel_status' => 'passed',
            'last_verified_at' => now(),
        ])->save();

        PixelVerificationPassed::dispatch($site);

        return true;
    }

    protected function fail(HeuristicsSite $site, string $reason): bool
    {
        $site->forceFill([
            'visible' => false,
            'pixel_status' => 'failed',
            'last_verified_at' => now(),
        ])->save();

        PixelVerificationFailed::dispatch($site, $reason);

        return false;
    }
}
