<?php

declare(strict_types=1);

use FancyHeuristics\Models\HeuristicsEvent;

it('persists a batch of events posted to the collect endpoint', function () {
    $response = $this->postJson('/heuristics/collect', [
        'siteKey' => 'showcase',
        'sessionId' => 'sess-abc',
        'events' => [
            [
                'kind' => 'pageview',
                'actor' => 'human',
                'path' => '/',
                'ts' => 1_717_000_000_000,
            ],
            [
                'kind' => 'pointer',
                'actor' => 'agent',
                'path' => '/',
                'x' => 100,
                'y' => 200,
                'vw' => 1000,
                'vh' => 800,
                'ts' => 1_717_000_001_000,
            ],
        ],
    ]);

    $response->assertStatus(202)->assertJson(['ok' => true, 'accepted' => 2]);

    expect(HeuristicsEvent::count())->toBe(2);

    $pointer = HeuristicsEvent::where('kind', 'pointer')->first();
    expect($pointer->site_key)->toBe('showcase');
    expect($pointer->session_id)->toBe('sess-abc');
    expect($pointer->actor)->toBe('agent');
    expect($pointer->x)->toBe(100);
    expect($pointer->vw)->toBe(1000);
});

it('skips malformed events but keeps the valid ones in a batch', function () {
    $response = $this->postJson('/heuristics/collect', [
        'siteKey' => 'showcase',
        'events' => [
            ['kind' => 'bogus', 'path' => '/'],   // invalid kind
            ['kind' => 'click'],                   // missing path
            ['kind' => 'click', 'path' => '/ok'],  // valid
        ],
    ]);

    $response->assertStatus(202)->assertJson(['accepted' => 1]);
    expect(HeuristicsEvent::count())->toBe(1);
});

it('normalises camelCase wire fields into snake_case columns', function () {
    $this->postJson('/heuristics/collect', [
        'siteKey' => 'showcase',
        'events' => [[
            'kind' => 'scroll',
            'path' => '/about',
            'scrollPct' => 42.5,
            'dwellMs' => 1234,
            'targetId' => 'hero',
        ]],
    ])->assertStatus(202);

    $event = HeuristicsEvent::first();
    expect($event->scroll_pct)->toBe(42.5);
    expect($event->dwell_ms)->toBe(1234);
    expect($event->target_id)->toBe('hero');
});
