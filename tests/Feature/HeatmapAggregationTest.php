<?php

declare(strict_types=1);

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\Models\HeuristicsEvent;

/**
 * @param  array<string, mixed>  $overrides
 */
function makePointer(array $overrides = []): HeuristicsEvent
{
    return HeuristicsEvent::create(array_merge([
        'site_key' => 'showcase',
        'actor' => 'human',
        'kind' => 'pointer',
        'path' => '/',
        'vw' => 1000,
        'vh' => 1000,
        'occurred_at' => now(),
    ], $overrides));
}

it('buckets pointer events into a normalised grid', function () {
    config()->set('heuristics.heatmap.grid_size', 10);

    // Three points clustered in the top-left cell (0,0): x/y in [0,100) of a 1000px viewport.
    makePointer(['x' => 10, 'y' => 10]);
    makePointer(['x' => 50, 'y' => 50]);
    makePointer(['x' => 90, 'y' => 90]);

    // One point dead-centre -> cell (5,5).
    makePointer(['x' => 500, 'y' => 500]);

    $map = Heuristics::heatmap('showcase', '/');

    expect($map['grid_size'])->toBe(10);
    expect($map['sample_count'])->toBe(4);
    expect($map['max'])->toBe(3);

    $cells = collect($map['cells']);

    $topLeft = $cells->firstWhere(fn ($c) => $c['x'] === 0 && $c['y'] === 0);
    expect($topLeft['count'])->toBe(3);
    expect($topLeft['weight'])->toBe(1.0);

    $centre = $cells->firstWhere(fn ($c) => $c['x'] === 5 && $c['y'] === 5);
    expect($centre['count'])->toBe(1);
});

it('ignores pointer events with no viewport and non-pointer kinds', function () {
    config()->set('heuristics.heatmap.grid_size', 8);

    makePointer(['x' => 100, 'y' => 100, 'vw' => 0, 'vh' => 0]); // no viewport -> skipped
    makePointer(['kind' => 'pageview', 'x' => 100, 'y' => 100]);  // not a pointer kind -> skipped
    makePointer(['x' => 100, 'y' => 100]);                        // valid

    $map = Heuristics::heatmap('showcase', '/');

    expect($map['sample_count'])->toBe(1);
});

it('clamps edge coordinates into the last cell', function () {
    config()->set('heuristics.heatmap.grid_size', 4);

    makePointer(['x' => 1000, 'y' => 1000]); // exactly on the far edge

    $map = Heuristics::heatmap('showcase', '/');
    $cell = collect($map['cells'])->first();

    expect($cell['x'])->toBe(3);
    expect($cell['y'])->toBe(3);
});
