<?php

declare(strict_types=1);

use FancyHeuristics\Services\HeuristicsPixelDetector;

/**
 * These cases are intentionally identical to the px-ui-sandbox
 * ScanShowcaseSubmission::detectBadge() / FancyPixelDetector behaviour: the
 * SAME signals must drive both. The badge marker is JS-injected at runtime by
 * the fancy-pixel loader, so the reliable STATIC signal in raw HTML is the
 * loader <script> tag (src contains `fancy-pixel`, or it carries a
 * `data-fancy-pixel` / `data-site` attribute) — alongside the runtime marker
 * and the wordmark.
 */
dataset('detection cases', [
    // 1. The loader-script signatures (the static, server-fetchable signal).
    'loader script by src (min)' => [
        '<script src="https://unpkg.com/@particle-academy/fancy-pixel/dist/fancy-pixel.global.min.js" data-site="abc"></script>',
        true,
    ],
    'loader script by src only' => [
        '<head><script src="/assets/fancy-pixel.global.js"></script></head>',
        true,
    ],
    'loader script by data-site attr' => [
        '<script src="/vendor/pixel.js" data-site="acme"></script>',
        true,
    ],
    'loader script by data-fancy-pixel attr' => [
        '<script src="/p.js" data-fancy-pixel></script>',
        true,
    ],
    // 2. The runtime marker (present once the badge renders in a browser).
    'marker attribute' => ['<div data-fancy-badge></div>', true],
    // 3. The wordmark.
    'wordmark text' => ['<footer>Powered by Fancy UI</footer>', true],
    'wordmark spaced/cased' => ['POWERED   by   fancy   ui', true],
    // Negatives.
    'unrelated html' => ['<div>nothing here</div>', false],
    'partial wordmark' => ['Powered by something else', false],
    'unrelated script tag' => ['<script src="/app.js" data-page="home"></script>', false],
]);

it('detects the shared Fancy UI pixel signals', function (string $html, bool $expected) {
    expect((new HeuristicsPixelDetector)->detect($html))->toBe($expected);
})->with('detection cases');
