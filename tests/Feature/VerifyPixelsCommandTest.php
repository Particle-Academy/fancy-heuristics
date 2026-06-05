<?php

declare(strict_types=1);

use FancyHeuristics\Models\HeuristicsSite;
use Illuminate\Support\Facades\Http;

it('toggles a site visibility off when the pixel disappears', function () {
    Http::fake([
        'https://lost.test' => Http::response('<html><body>no badge anymore</body></html>', 200),
    ]);

    $site = HeuristicsSite::create([
        'site_key' => 'lost',
        'url' => 'https://lost.test',
        'visible' => true,
    ]);

    $this->artisan('heuristics:verify-pixels')->assertSuccessful();

    expect($site->refresh()->visible)->toBeFalse();
    expect($site->pixel_status)->toBe('failed');
});

it('toggles a site visibility back on when the pixel returns', function () {
    Http::fake([
        'https://back.test' => Http::response('<div data-fancy-badge></div>', 200),
    ]);

    $site = HeuristicsSite::create([
        'site_key' => 'back',
        'url' => 'https://back.test',
        'visible' => false,
    ]);

    $this->artisan('heuristics:verify-pixels')->assertSuccessful();

    expect($site->refresh()->visible)->toBeTrue();
    expect($site->pixel_status)->toBe('passed');
});

it('can target a single site by key', function () {
    Http::fake([
        'https://one.test' => Http::response('<div data-fancy-badge></div>', 200),
        'https://two.test' => Http::response('no badge', 200),
    ]);

    $one = HeuristicsSite::create(['site_key' => 'one', 'url' => 'https://one.test', 'visible' => false]);
    $two = HeuristicsSite::create(['site_key' => 'two', 'url' => 'https://two.test', 'visible' => true]);

    $this->artisan('heuristics:verify-pixels --site=one')->assertSuccessful();

    expect($one->refresh()->visible)->toBeTrue();
    // two was not touched
    expect($two->refresh()->visible)->toBeTrue();
    expect($two->pixel_status)->toBeNull();
});
