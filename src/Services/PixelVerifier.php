<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Contracts\PageFetcher;
use FancyHeuristics\Events\PixelVerificationFailed;
use FancyHeuristics\Events\PixelVerificationPassed;
use FancyHeuristics\Exceptions\UnsafeUrlException;
use FancyHeuristics\Models\HeuristicsSite;

/**
 * Server-side re-poll of a registered site. Fetches the page HTML, runs the
 * shared HeuristicsPixelDetector (data-fancy-badge OR "Powered by Fancy UI"),
 * persists the result on the HeuristicsSite, and fires
 * PixelVerificationPassed / PixelVerificationFailed so hosts can toggle a
 * listing's visibility.
 *
 * The page is fetched through the PageFetcher contract. The default,
 * SafePageFetcher, refuses non-public targets: the site URL is supplied by
 * whoever registered the site, so an unguarded fetch is an SSRF primitive.
 */
class PixelVerifier
{
    public function __construct(
        protected HeuristicsPixelDetector $detector,
        protected ?PageFetcher $fetcher = null,
    ) {}

    /**
     * Verify a single site. Returns true when the pixel was detected.
     */
    public function verify(HeuristicsSite $site): bool
    {
        $fetcher = $this->fetcher ?? app(PageFetcher::class);

        try {
            $page = $fetcher->fetch((string) $site->url);
        } catch (UnsafeUrlException $e) {
            return $this->fail($site, 'fetch refused: '.$e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail($site, 'fetch failed: '.$e->getMessage());
        }

        if (! $page->successful()) {
            return $this->fail($site, 'HTTP '.$page->status);
        }

        $detected = $this->detector->detect($page->body);

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
