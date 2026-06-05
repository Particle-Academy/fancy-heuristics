<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

/**
 * SINGLE shared "Fancy Pixel" detection.
 *
 * This is the one place the detection is defined for the whole suite. The
 * px-ui-sandbox ScanShowcaseSubmission job and this package's PixelVerifier
 * both detect a pixel the SAME way. Keep this byte-identical to
 * ScanShowcaseSubmission::detectBadge() in the showcase app.
 *
 * Why three signals? The "Powered by Fancy" badge is JS-injected at runtime
 * by the `fancy-pixel` loader script, so a server-side HTML fetch will NOT
 * contain the runtime `data-fancy-badge` marker (it only appears after the
 * loader executes in a browser). The reliable static signal in raw HTML is
 * therefore the loader `<script>` tag itself — its `src` references
 * `fancy-pixel` and/or it carries `data-site` / `data-fancy-pixel`
 * attributes. We match ANY of:
 *   1. the `fancy-pixel` loader script (src contains `fancy-pixel`, or a
 *      script tag carries a `data-fancy-pixel` / `data-site` attribute),
 *   2. the runtime `data-fancy-badge` marker (present once the badge renders),
 *   3. the literal "Powered by Fancy UI" wordmark text.
 */
class HeuristicsPixelDetector
{
    /**
     * Detect a Fancy UI pixel in the given HTML.
     */
    public function detect(string $html): bool
    {
        // 1. The fancy-pixel loader <script> tag — the reliable STATIC signal,
        //    since the badge marker is only injected at runtime in a browser.
        if (preg_match('/<script\b[^>]*\bsrc\s*=\s*["\'][^"\']*fancy-pixel[^"\']*["\'][^>]*>/i', $html) === 1) {
            return true;
        }
        if (preg_match('/<script\b[^>]*\bdata-(?:fancy-pixel|site)\b[^>]*>/i', $html) === 1) {
            return true;
        }

        // 2. The runtime marker the rendered badge carries.
        if (str_contains($html, 'data-fancy-badge')) {
            return true;
        }

        // 3. The literal wordmark.
        return preg_match('/powered\s+by\s+fancy\s+ui/i', $html) === 1;
    }
}
