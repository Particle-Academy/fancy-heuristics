<?php

declare(strict_types=1);

use FancyHeuristics\Events\PixelVerificationFailed;
use FancyHeuristics\Events\PixelVerificationPassed;
use FancyHeuristics\Models\HeuristicsSite;
use FancyHeuristics\Services\PixelVerifier;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('marks a site visible and fires Passed when the data-fancy-badge marker is present', function () {
    Event::fake([PixelVerificationPassed::class, PixelVerificationFailed::class]);

    Http::fake([
        'https://acme.test' => Http::response('<html><body><div data-fancy-badge></div></body></html>', 200),
    ]);

    $site = HeuristicsSite::create([
        'site_key' => 'acme',
        'url' => 'https://acme.test',
        'visible' => false,
    ]);

    $result = app(PixelVerifier::class)->verify($site);

    expect($result)->toBeTrue();

    $site->refresh();
    expect($site->visible)->toBeTrue();
    expect($site->pixel_status)->toBe('passed');
    expect($site->last_verified_at)->not->toBeNull();

    Event::assertDispatched(PixelVerificationPassed::class);
    Event::assertNotDispatched(PixelVerificationFailed::class);
});

it('detects the "Powered by Fancy UI" wordmark too', function () {
    Http::fake([
        'https://wordmark.test' => Http::response('<footer>Powered by Fancy UI</footer>', 200),
    ]);

    $site = HeuristicsSite::create([
        'site_key' => 'wordmark',
        'url' => 'https://wordmark.test',
        'visible' => false,
    ]);

    expect(app(PixelVerifier::class)->verify($site))->toBeTrue();
    expect($site->refresh()->visible)->toBeTrue();
});

it('marks a site hidden and fires Failed when the marker is absent', function () {
    Event::fake([PixelVerificationPassed::class, PixelVerificationFailed::class]);

    Http::fake([
        'https://nobadge.test' => Http::response('<html><body>nothing here</body></html>', 200),
    ]);

    $site = HeuristicsSite::create([
        'site_key' => 'nobadge',
        'url' => 'https://nobadge.test',
        'visible' => true,
    ]);

    $result = app(PixelVerifier::class)->verify($site);

    expect($result)->toBeFalse();

    $site->refresh();
    expect($site->visible)->toBeFalse();
    expect($site->pixel_status)->toBe('failed');

    Event::assertDispatched(PixelVerificationFailed::class);
    Event::assertNotDispatched(PixelVerificationPassed::class);
});

it('fails verification on a non-200 response', function () {
    Http::fake([
        'https://down.test' => Http::response('not found', 404),
    ]);

    $site = HeuristicsSite::create([
        'site_key' => 'down',
        'url' => 'https://down.test',
        'visible' => true,
    ]);

    expect(app(PixelVerifier::class)->verify($site))->toBeFalse();
    expect($site->refresh()->visible)->toBeFalse();
});
