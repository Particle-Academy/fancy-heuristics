<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

/**
 * SINGLE shared "Powered by Fancy" pixel detection.
 *
 * This is the one place the marker is defined for the whole suite. The
 * px-ui-sandbox ScanShowcaseSubmission job and this package's PixelVerifier
 * both detect a pixel the SAME way: the stable `data-fancy-badge` attribute
 * the official badge embed carries, OR the literal "Powered by Fancy UI"
 * wordmark text. Keep these two signals identical to the showcase scanner.
 */
class HeuristicsPixelDetector
{
    /**
     * Detect a Fancy UI pixel in the given HTML.
     */
    public function detect(string $html): bool
    {
        return str_contains($html, 'data-fancy-badge')
            || preg_match('/powered\s+by\s+fancy\s+ui/i', $html) === 1;
    }
}
