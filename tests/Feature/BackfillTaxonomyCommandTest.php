<?php

declare(strict_types=1);

use FancyHeuristics\Models\HeuristicsEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

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

    $code = $this->artisan('heuristics:backfill-taxonomy', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->toContain('would update 4')
        ->and($output)->toContain('unreliable legacy labels: 1')
        ->and($output)->toContain('agent rows no longer counted as clicks: 1')
        ->and($code)->toBe(0);

    expect(HeuristicsEvent::whereNotNull('name')->count())->toBe(0);
});

it('applies the ingest mapping to old rows and is idempotent', function () {
    $view = legacyRow(['kind' => 'pageview', 'path' => '/orders/42']);
    $click = legacyRow(['kind' => 'click', 'label' => 'Header text']);
    $tool = legacyRow(['kind' => 'click', 'actor' => 'agent', 'meta' => json_encode(['action' => 'grid_paint', 'via' => 'relay'])]);

    expect($this->artisan('heuristics:backfill-taxonomy'))->toBe(0);

    expect($view->fresh()->name)->toBe('ui.view');
    expect($view->fresh()->route_pattern)->toBe('/orders/:id');
    expect($click->fresh()->name)->toBe('ui.activate');
    expect($click->fresh()->props)->toBe(['legacy_label' => 'Header text']);
    expect($click->fresh()->target_name)->toBeNull();
    expect($tool->fresh()->name)->toBe('agent.tool');
    expect($tool->fresh()->kind)->toBe('agent');

    $code = $this->artisan('heuristics:backfill-taxonomy');
    $output = Artisan::output();

    expect($output)->toContain('updated 0')
        ->and($code)->toBe(0);
});

it('can be limited to one site', function () {
    legacyRow(['kind' => 'pageview']);
    legacyRow(['kind' => 'pageview', 'site_key' => 'other']);

    expect($this->artisan('heuristics:backfill-taxonomy', ['--site' => 'other']))->toBe(0);

    expect(HeuristicsEvent::where('site_key', 's')->first()->name)->toBeNull();
    expect(HeuristicsEvent::where('site_key', 'other')->first()->name)->toBe('ui.view');
});
