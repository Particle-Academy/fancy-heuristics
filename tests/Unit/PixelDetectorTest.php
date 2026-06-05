<?php

declare(strict_types=1);

use FancyHeuristics\Services\HeuristicsPixelDetector;

/**
 * These cases are intentionally identical to the px-ui-sandbox
 * ScanShowcaseSubmission::detectBadge() behaviour: the SAME two signals
 * (data-fancy-badge marker OR /powered by fancy ui/i) must drive both.
 */
dataset('detection cases', [
    'marker attribute' => ['<div data-fancy-badge></div>', true],
    'wordmark text' => ['<footer>Powered by Fancy UI</footer>', true],
    'wordmark spaced/cased' => ['POWERED   by   fancy   ui', true],
    'unrelated html' => ['<div>nothing here</div>', false],
    'partial wordmark' => ['Powered by something else', false],
]);

it('detects the shared Fancy UI pixel signals', function (string $html, bool $expected) {
    expect((new HeuristicsPixelDetector)->detect($html))->toBe($expected);
})->with('detection cases');
