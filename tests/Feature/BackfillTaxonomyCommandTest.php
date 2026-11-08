<?php

declare(strict_types=1);

use FancyHeuristics\Models\HeuristicsEvent;
use Illuminate\Support\Carbon;

/*
 * Phase 1: heuristics:backfill-taxonomy applies the ingest mapping to rows
 * stored before v2. It is run here only against the in-memory test database.
 */

function legacyRow(array $attrs): HeuristicsEvent
{
    // Inserted directly so name stays null, as on a pre-0.6 database.
    $id = HeuristicsEvent::query()->insertGetId([
        'site_key' => 's', 'session_id' => 'x', 'actor' => 'human', 'path' => '/',
        'occurred_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ...$attrs,
    ]);

    return HeuristicsEvent::findOrFail($id);
}

it('reports what it would change and writes nothing on --dry-run', function () {
    legacyRow(['kind' => 'pageview']);
    legacyRow(['kind' => 'click', 'label' => str_repeat('Mashed', 20)]);
    legacyRow(['kind' => 'click', 'target_id' => 'buy', 'label' => 'Buy']);
    legacyRow(['kind' => 'click', 'actor' => 'agent', 'meta' => json_encode(['action' => 'grid_paint', 'via' => 'relay'])]);

    $this->artisan('heuristics:backfill-taxonomy', ['--dry-run' => true])
        ->expectsOutputToContain('would update 4')
        ->expectsOutputToContain('unreliable legacy labels: 1')
        ->expectsOutputToContain('agent rows no longer counted as clicks: 1')
        ->assertSuccessful();

    expect(HeuristicsEvent::whereNotNull('name')->count())->toBe(0);
});

it('applies the ingest mapping to old rows and is idempotent', function () {
    $view = legacyRow(['kind' => 'pageview', 'path' => '/orders/42']);
    $click = legacyRow(['kind' => 'click', 'label' => 'Header text']);
    $tool = legacyRow(['kind' => 'click', 'actor' => 'agent', 'meta' => json_encode(['action' => 'grid_paint', 'via' => 'relay'])]);

    $this->artisan('heuristics:backfill-taxonomy')->assertSuccessful();

    expect($view->fresh()->name)->toBe('ui.view');
    expect($view->fresh()->route_pattern)->toBe('/orders/:id');
    expect($click->fresh()->name)->toBe('ui.activate');
    expect($click->fresh()->props)->toBe(['legacy_label' => 'Header text']);
    expect($click->fresh()->target_name)->toBeNull();
    expect($tool->fresh()->name)->toBe('agent.tool');
    expect($tool->fresh()->kind)->toBe('agent');

    $this->artisan('heuristics:backfill-taxonomy')
        ->expectsOutputToContain('updated 0')
        ->assertSuccessful();
});

it('can be limited to one site', function () {
    legacyRow(['kind' => 'pageview']);
    legacyRow(['kind' => 'pageview', 'site_key' => 'other']);

    $this->artisan('heuristics:backfill-taxonomy', ['--site' => 'other'])->assertSuccessful();

    expect(HeuristicsEvent::where('site_key', 's')->first()->name)->toBeNull();
    expect(HeuristicsEvent::where('site_key', 'other')->first()->name)->toBe('ui.view');
});
