<?php

declare(strict_types=1);

use FancyHeuristics\Http\Controllers\CollectController;
use FancyHeuristics\Http\Controllers\PixelController;
use FancyHeuristics\Http\Middleware\HandleHeuristicsCors;
use Illuminate\Support\Facades\Route;

/**
 * Fancy Heuristics ingestion routes.
 *
 * Loaded by HeuristicsServiceProvider when config('heuristics.routes.enabled')
 * is true. Prefix, middleware, and throttle come from config.
 *
 * Cross-origin: the collector + pixel beacon are posted by browsers on OTHER
 * origins (every site that embeds the Fancy Pixel), so these endpoints only ever
 * receive cross-origin traffic. Two things make that work out of the box:
 *   - the `api` middleware group is stateless (no CSRF 419), and
 *   - HandleHeuristicsCors (added below when config('heuristics.routes.cors.enabled')
 *     is true — the default) answers the OPTIONS preflight and sets the
 *     Access-Control-* headers. A host can restrict origins via
 *     `heuristics.routes.cors.allowed_origins`, or disable this and manage CORS
 *     itself (e.g. Laravel's config/cors.php) by setting the toggle false.
 */
$prefix = config('heuristics.routes.prefix', 'heuristics');
$middleware = (array) config('heuristics.routes.middleware', ['api']);
$throttle = config('heuristics.routes.throttle', '120,1');

if ($throttle !== null && $throttle !== '') {
    $middleware[] = 'throttle:'.$throttle;
}

$cors = config('heuristics.routes.cors', ['enabled' => true]);
$corsEnabled = is_array($cors) ? (bool) ($cors['enabled'] ?? true) : (bool) $cors;

if ($corsEnabled) {
    // Front of the pipeline so OPTIONS preflight is answered before the throttle
    // limiter counts it (browsers preflight often; they shouldn't burn quota).
    array_unshift($middleware, HandleHeuristicsCors::class);
}

// When CORS is on, the routes also answer OPTIONS so the preflight reaches the
// CORS middleware (which short-circuits OPTIONS before the controller). This
// uses Route::match with the controller class — NOT a separate closure route —
// so `php artisan route:cache` can serialize it. (Closure routes are not
// cacheable; route:cache silently drops them, which breaks preflight in prod.)
$methods = $corsEnabled ? ['POST', 'OPTIONS'] : ['POST'];

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function () use ($methods) {
        Route::match($methods, 'collect', CollectController::class)->name('heuristics.collect');
        Route::match($methods, 'pixel', PixelController::class)->name('heuristics.pixel');
    });
