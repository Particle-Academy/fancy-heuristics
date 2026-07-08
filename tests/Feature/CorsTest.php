<?php

declare(strict_types=1);

/**
 * The ingestion endpoints are posted by browsers on OTHER origins (every site
 * embedding the Fancy Pixel), so they must answer the CORS preflight and carry
 * Access-Control-* headers by default — otherwise the beacons are blocked and
 * the collector retry-spams the console.
 */
it('answers the OPTIONS preflight for the collect endpoint with CORS headers', function () {
    $this->call('OPTIONS', '/heuristics/collect', [], [], [], [
        'HTTP_ORIGIN' => 'https://tynn.ai',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ])
        ->assertNoContent(204)
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->assertHeader('Access-Control-Allow-Headers', 'content-type');
});

it('answers the OPTIONS preflight for the pixel endpoint', function () {
    $this->call('OPTIONS', '/heuristics/pixel', [], [], [], [
        'HTTP_ORIGIN' => 'https://tynn.ai',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])
        ->assertNoContent(204)
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

it('sets Access-Control-Allow-Origin on the actual cross-origin POST', function () {
    $this->postJson('/heuristics/collect', [
        'siteKey' => 'showcase',
        'events' => [['kind' => 'pageview', 'path' => '/']],
    ], ['Origin' => 'https://tynn.ai'])
        ->assertStatus(202)
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

it('registers ingestion routes as controller actions that survive route:cache', function () {
    // Closure routes cannot be serialized by `php artisan route:cache` (run on
    // deploy) — they silently vanish, so the OPTIONS preflight falls through to
    // Laravel's default 200/Allow and CORS breaks in prod. The preflight must be
    // an OPTIONS method on the controller-backed route, never a closure route.
    $routes = app('router')->getRoutes();

    foreach (['heuristics.collect', 'heuristics.pixel'] as $name) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull();
        expect($route->getActionName())->not->toBe('Closure');
        expect($route->methods())->toContain('POST')->toContain('OPTIONS');
    }
});

it('echoes an allowed origin and sets Vary when the allow-list is concrete', function () {
    config()->set('heuristics.routes.cors.allowed_origins', ['https://tynn.ai']);

    $ok = $this->call('OPTIONS', '/heuristics/collect', [], [], [], [
        'HTTP_ORIGIN' => 'https://tynn.ai',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);
    $ok->assertNoContent(204)
        ->assertHeader('Access-Control-Allow-Origin', 'https://tynn.ai')
        ->assertHeader('Vary', 'Origin');

    // A disallowed origin gets no Allow-Origin header (browser blocks it).
    $blocked = $this->call('OPTIONS', '/heuristics/collect', [], [], [], [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);
    expect($blocked->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});
