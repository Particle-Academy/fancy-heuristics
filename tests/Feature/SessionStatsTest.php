<?php

declare(strict_types=1);

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\Models\HeuristicsEvent;

it('summarises session stats by actor and kind', function () {
    HeuristicsEvent::create(['site_key' => 's', 'session_id' => 'a', 'actor' => 'human', 'kind' => 'pageview', 'path' => '/', 'occurred_at' => now()]);
    HeuristicsEvent::create(['site_key' => 's', 'session_id' => 'a', 'actor' => 'human', 'kind' => 'click', 'path' => '/', 'occurred_at' => now()]);
    HeuristicsEvent::create(['site_key' => 's', 'session_id' => 'b', 'actor' => 'agent', 'kind' => 'click', 'path' => '/', 'occurred_at' => now()]);

    $stats = Heuristics::sessionStats('s');

    expect($stats['events'])->toBe(3);
    expect($stats['sessions'])->toBe(2);
    expect($stats['by_actor'])->toMatchArray(['human' => 2, 'agent' => 1]);
    expect($stats['by_kind'])->toMatchArray(['pageview' => 1, 'click' => 2]);
});

it('records a single event through the facade', function () {
    $event = Heuristics::record([
        'siteKey' => 's',
        'kind' => 'dwell',
        'path' => '/x',
        'dwellMs' => 5000,
    ]);

    expect($event)->not->toBeNull();
    expect($event->dwell_ms)->toBe(5000);
    expect(Heuristics::record(['kind' => 'bogus']))->toBeNull();
});
