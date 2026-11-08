<?php

declare(strict_types=1);

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\Models\HeuristicsEvent;
use Illuminate\Support\Carbon;

/*
 * Phase 1: the reports read the v2 fields. Elements keeps clicks that have no
 * target, marks v1 rows as legacy, and leaves agent activity out; Pages
 * carries the title and route pattern.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function taxonomyEvent(array $attrs): HeuristicsEvent
{
    return HeuristicsEvent::create([
        'site_key' => 'site', 'session_id' => 's1', 'actor' => 'human', 'path' => '/',
        'occurred_at' => Carbon::now()->subHour(), ...$attrs,
    ]);
}

it('keeps clicks that have no target in the Elements report, marked as legacy', function () {
    foreach (range(1, 3) as $_) {
        taxonomyEvent(['kind' => 'click', 'name' => 'ui.activate', 'schema_version' => 1, 'target_id' => null, 'label' => 'Fancy UI Kitv0.5Docs']);
    }

    $rows = Heuristics::topElements('site', 30);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['clicks'])->toBe(3);
    expect($rows[0]['legacy'])->toBeTrue();
    expect($rows[0]['label'])->toBe('Fancy UI Kitv0.5Docs');
    expect($rows[0]['target_id'])->toBe('');
});

it('groups v2 clicks by component, part, instance and name', function () {
    foreach (range(1, 4) as $_) {
        taxonomyEvent(['kind' => 'click', 'name' => 'ui.activate', 'schema_version' => 2, 'component' => 'streaming-audio', 'part' => 'play', 'instance' => 'site-radio-player', 'role' => 'button', 'target_name' => 'Play']);
    }
    taxonomyEvent(['kind' => 'click', 'name' => 'ui.activate', 'schema_version' => 2, 'component' => 'button', 'role' => 'button', 'target_name' => 'Sign in']);
    taxonomyEvent(['kind' => 'click', 'name' => 'ui.activate', 'schema_version' => 2, 'component' => 'none']);
    taxonomyEvent(['kind' => 'agent', 'name' => 'agent.act', 'actor' => 'agent', 'schema_version' => 2, 'component' => 'button']);

    $rows = Heuristics::topElements('site', 30);

    expect($rows)->toHaveCount(3);
    expect($rows[0])->toMatchArray([
        'component' => 'streaming-audio', 'part' => 'play', 'instance' => 'site-radio-player',
        'role' => 'button', 'name' => 'Play', 'clicks' => 4, 'legacy' => false,
        'target_id' => 'site-radio-player', 'label' => 'Play',
    ]);
    expect(collect($rows)->pluck('name')->all())->toContain('Sign in');
    expect(collect($rows)->sum('clicks'))->toBe(6);
});

it('carries the latest title and the route pattern on top pages', function () {
    taxonomyEvent(['kind' => 'pageview', 'name' => 'ui.view', 'schema_version' => 2, 'path' => '/packages/x', 'route_pattern' => '/packages/{slug}', 'title' => 'Old title', 'occurred_at' => Carbon::now()->subHours(3)]);
    taxonomyEvent(['kind' => 'pageview', 'name' => 'ui.view', 'schema_version' => 2, 'path' => '/packages/x', 'route_pattern' => '/packages/{slug}', 'title' => 'X · Packages']);
    taxonomyEvent(['kind' => 'pageview', 'name' => 'ui.view', 'schema_version' => 1, 'path' => '/old']);

    $pages = collect(Heuristics::topPages('site', 30))->keyBy('path');

    expect($pages['/packages/x'])->toMatchArray(['pageviews' => 2, 'title' => 'X · Packages', 'route_pattern' => '/packages/{slug}']);
    expect($pages['/old'])->toMatchArray(['pageviews' => 1, 'title' => null]);
});

it('reports event counts by name', function () {
    taxonomyEvent(['kind' => 'pageview', 'name' => 'ui.view']);
    taxonomyEvent(['kind' => 'click', 'name' => 'ui.activate']);
    taxonomyEvent(['kind' => 'click', 'name' => 'ui.activate', 'session_id' => 's2']);

    expect(Heuristics::eventNames('site', 30))->toBe([
        ['name' => 'ui.activate', 'events' => 2, 'sessions' => 2],
        ['name' => 'ui.view', 'events' => 1, 'sessions' => 1],
    ]);
});
