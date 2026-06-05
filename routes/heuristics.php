<?php

declare(strict_types=1);

use FancyHeuristics\Http\Controllers\CollectController;
use FancyHeuristics\Http\Controllers\PixelController;
use Illuminate\Support\Facades\Route;

/**
 * Fancy Heuristics ingestion routes.
 *
 * Loaded by HeuristicsServiceProvider when config('heuristics.routes.enabled')
 * is true. Prefix, middleware, and throttle come from config.
 *
 * IMPORTANT (cross-origin clients): the collector and pixel beacon are posted
 * by browsers on OTHER origins via navigator.sendBeacon. The host MUST either:
 *   - exempt the route prefix from CSRF (VerifyCsrfToken::$except), or
 *   - keep these on a stateless `api` middleware group (the default).
 * Otherwise every beacon 419s. CORS headers are the host's responsibility.
 */
$prefix = config('heuristics.routes.prefix', 'heuristics');
$middleware = (array) config('heuristics.routes.middleware', ['api']);
$throttle = config('heuristics.routes.throttle', '120,1');

if ($throttle !== null && $throttle !== '') {
    $middleware[] = 'throttle:'.$throttle;
}

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function () {
        Route::post('collect', CollectController::class)->name('heuristics.collect');
        Route::post('pixel', PixelController::class)->name('heuristics.pixel');
    });
