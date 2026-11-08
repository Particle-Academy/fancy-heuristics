<?php

declare(strict_types=1);

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsSession;
use Illuminate\Support\Carbon;

/*
 * Phase 1: wire v2. Named events, route and title as separate fields, a
 * structured target, bounded props, and idempotency by batchId and eventId.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function v2Ts(int $plusSeconds = 0): int
{
    return (int) Carbon::now()->addSeconds($plusSeconds)->getTimestampMs();
}

/**
 * @param  list<array<string, mixed>>  $events
 * @return array<string, mixed>
 */
function v2Batch(array $events, string $batchId = 'batch-1', string $session = 'sess-v2'): array
{
    return [
        'schemaVersion' => 2,
        'batchId' => $batchId,
        'siteKey' => 'site',
        'sessionId' => $session,
        'sentAt' => v2Ts(),
        'events' => $events,
    ];
}

it('stores the v2 fields: name, route, title and the structured target', function () {
    $this->postJson('/heuristics/collect', v2Batch([
        [
            'eventId' => 'e1', 'name' => 'ui.view', 'kind' => 'pageview', 'actor' => 'human',
            'path' => '/packages/react-fancy', 'ts' => v2Ts(),
            'route' => ['name' => 'packages.show', 'pattern' => '/packages/{slug}'],
            'title' => 'react-fancy · Packages',
        ],
        [
            'eventId' => 'e2', 'name' => 'ui.activate', 'kind' => 'click', 'actor' => 'human',
            'path' => '/packages/react-fancy', 'ts' => v2Ts(1),
            'x' => 10, 'y' => 20, 'vw' => 1280, 'vh' => 800,
            'target' => ['component' => 'streaming-audio', 'part' => 'play', 'instance' => 'site-radio-player', 'role' => 'button', 'name' => 'Play'],
        ],
    ]))->assertStatus(202)->assertJson(['accepted' => 2, 'rejected' => 0]);

    $view = HeuristicsEvent::where('event_id', 'e1')->first();
    expect($view->schema_version)->toBe(2);
    expect($view->name)->toBe('ui.view');
    expect($view->kind)->toBe('pageview');
    expect($view->route_name)->toBe('packages.show');
    expect($view->route_pattern)->toBe('/packages/{slug}');
    expect($view->title)->toBe('react-fancy · Packages');
    expect($view->label)->toBeNull();

    $click = HeuristicsEvent::where('event_id', 'e2')->first();
    expect($click->kind)->toBe('click');
    expect($click->component)->toBe('streaming-audio');
    expect($click->part)->toBe('play');
    expect($click->instance)->toBe('site-radio-player');
    expect($click->role)->toBe('button');
    expect($click->target_name)->toBe('Play');
    expect($click->title)->toBeNull();
});

it('keeps the title only on ui.view', function () {
    $this->postJson('/heuristics/collect', v2Batch([
        ['eventId' => 'a', 'name' => 'ui.activate', 'path' => '/', 'ts' => v2Ts(), 'title' => 'Sneaky', 'target' => ['component' => 'none']],
    ]))->assertStatus(202);

    expect(HeuristicsEvent::first()->title)->toBeNull();
});

it('rejects a name that is not registered, and accepts one the host registers', function () {
    $this->postJson('/heuristics/collect', v2Batch([
        ['eventId' => 'a', 'name' => 'radio.play', 'path' => '/', 'ts' => v2Ts()],
        ['eventId' => 'b', 'name' => 'Not A Name', 'path' => '/', 'ts' => v2Ts()],
    ]))->assertStatus(202)->assertJson([
        'accepted' => 0,
        'errors' => [['index' => 0, 'code' => 'unknown_name'], ['index' => 1, 'code' => 'invalid_name']],
    ]);

    config(['heuristics.names' => ['radio.play']]);

    $this->postJson('/heuristics/collect', v2Batch([
        ['eventId' => 'c', 'name' => 'radio.play', 'path' => '/', 'ts' => v2Ts(), 'props' => ['station' => 'lofi']],
    ], 'batch-2'))->assertStatus(202)->assertJson(['accepted' => 1]);

    $e = HeuristicsEvent::first();
    expect($e->name)->toBe('radio.play');
    expect($e->kind)->toBe('radio');
    expect($e->props)->toBe(['station' => 'lofi']);
});

it('derives a route pattern from the path when the host sends none', function () {
    $this->postJson('/heuristics/collect', v2Batch([
        ['eventId' => 'a', 'name' => 'ui.view', 'path' => '/orders/12345/items/3f2b8c1e-9d4a-4b7e-8f00-1a2b3c4d5e6f', 'ts' => v2Ts()],
        ['eventId' => 'b', 'name' => 'ui.view', 'route' => ['path' => '/plain'], 'ts' => v2Ts(1)],
    ]))->assertStatus(202)->assertJson(['accepted' => 2]);

    expect(HeuristicsEvent::where('event_id', 'a')->first()->route_pattern)->toBe('/orders/:id/items/:id');
    expect(HeuristicsEvent::where('event_id', 'b')->first()->path)->toBe('/plain');
});

it('bounds the target name and props', function () {
    $this->postJson('/heuristics/collect', v2Batch([
        ['eventId' => 'a', 'name' => 'ui.activate', 'path' => '/', 'ts' => v2Ts(), 'target' => ['component' => 'button', 'name' => "  Add \n to   cart ".str_repeat('x', 100)]],
        ['eventId' => 'b', 'name' => 'ui.activate', 'path' => '/', 'ts' => v2Ts(), 'target' => ['component' => 'button'], 'props' => ['nested' => ['no' => 1]]],
        ['eventId' => 'c', 'name' => 'ui.activate', 'path' => '/', 'ts' => v2Ts(), 'target' => ['component' => 'bad component!']],
    ]))->assertStatus(202)->assertJson([
        'accepted' => 1,
        'errors' => [['index' => 1, 'code' => 'invalid_props'], ['index' => 2, 'code' => 'invalid_target']],
    ]);

    $name = HeuristicsEvent::first()->target_name;
    expect(mb_strlen($name))->toBe(60);
    expect($name)->toStartWith('Add to cart x');
});

it('projects agent names to kind agent, never click', function () {
    $this->postJson('/heuristics/collect', v2Batch([
        ['eventId' => 'a', 'name' => 'agent.act', 'actor' => 'agent', 'path' => '/', 'ts' => v2Ts(), 'kind' => 'click', 'props' => ['action' => 'form_fill']],
    ]))->assertStatus(202);

    expect(HeuristicsEvent::first()->kind)->toBe('agent');
});

it('replays a batch with the same batchId without storing or counting it twice', function () {
    $batch = v2Batch([
        ['eventId' => 'a', 'name' => 'ui.view', 'path' => '/', 'ts' => v2Ts()],
        ['eventId' => 'b', 'name' => 'ui.activate', 'path' => '/', 'ts' => v2Ts(1), 'target' => ['component' => 'none']],
    ]);

    $this->postJson('/heuristics/collect', $batch)->assertJson(['replayed' => false, 'accepted' => 2]);
    $this->postJson('/heuristics/collect', $batch)->assertJson(['replayed' => true, 'accepted' => 2]);

    expect(HeuristicsEvent::count())->toBe(2);
    $session = HeuristicsSession::where('session_id', 'sess-v2')->first();
    expect($session->events)->toBe(2);
    expect($session->pageviews)->toBe(1);
});

it('stores an event id once even when it arrives in a different batch', function () {
    $event = ['eventId' => 'same', 'name' => 'ui.view', 'path' => '/', 'ts' => v2Ts()];

    $this->postJson('/heuristics/collect', v2Batch([$event], 'b1'))->assertJson(['accepted' => 1]);
    $this->postJson('/heuristics/collect', v2Batch([$event], 'b2'))
        ->assertJson(['accepted' => 0, 'errors' => [['index' => 0, 'code' => 'duplicate']]]);

    expect(HeuristicsEvent::count())->toBe(1);
});

it('aggregates the new fields in sessionStats by name', function () {
    Heuristics::collect(v2Batch([
        ['eventId' => 'a', 'name' => 'ui.view', 'path' => '/', 'ts' => v2Ts()],
        ['eventId' => 'b', 'name' => 'ui.activate', 'path' => '/', 'ts' => v2Ts(1), 'target' => ['component' => 'none']],
        ['eventId' => 'c', 'name' => 'ui.activate', 'path' => '/', 'ts' => v2Ts(2), 'target' => ['component' => 'none']],
    ]));

    expect(Heuristics::sessionStats('site')['by_name'])->toBe(['ui.activate' => 2, 'ui.view' => 1]);
});
