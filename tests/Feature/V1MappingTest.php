<?php

declare(strict_types=1);

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\Models\HeuristicsEvent;

/*
 * Phase 1: v1 producers (deployed pixels, the agent sink, the sandbox relay)
 * keep working, and their events are mapped to v2 names at ingest. An agent
 * tool call is no longer stored as a click.
 */

it('maps v1 kinds to v2 names and keeps schema_version 1', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'v1',
        'events' => [
            ['kind' => 'pageview', 'path' => '/'],
            ['kind' => 'click', 'path' => '/'],
            ['kind' => 'dwell', 'path' => '/', 'dwellMs' => 100],
            ['kind' => 'scroll', 'path' => '/', 'scrollPct' => 40],
            ['kind' => 'pointer', 'path' => '/', 'x' => 1, 'y' => 1, 'vw' => 10, 'vh' => 10],
        ],
    ]);

    expect(HeuristicsEvent::orderBy('id')->pluck('name')->all())
        ->toBe(['ui.view', 'ui.activate', 'engage.time', 'engage.scroll', 'pointer.sample']);
    expect(HeuristicsEvent::pluck('schema_version')->unique()->all())->toBe([1]);
});

it('keeps a v1 label as a legacy label and never as the target name', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'events' => [['kind' => 'click', 'path' => '/', 'label' => 'Fancy UI Kitv0.5DocsLearn']],
    ]);

    $e = HeuristicsEvent::first();
    expect($e->target_name)->toBeNull();
    expect($e->props)->toBe(['legacy_label' => 'Fancy UI Kitv0.5DocsLearn']);
    expect($e->label)->toBe('Fancy UI Kitv0.5DocsLearn');
});

it('stores an agent tool call from the relay as agent.tool, not as a click', function () {
    // Exactly what px-ui-sandbox's AgentRelayController sends today.
    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'relay-abc',
        'events' => [[
            'kind' => 'click', 'actor' => 'agent', 'path' => '/',
            'targetId' => 'grid_paint', 'label' => 'grid paint',
            'meta' => ['action' => 'grid_paint', 'source' => 'agent', 'via' => 'relay'],
        ]],
    ]);

    $e = HeuristicsEvent::first();
    expect($e->name)->toBe('agent.tool');
    expect($e->kind)->not->toBe('click');
    expect($e->kind)->toBe('agent');
    expect($e->props)->toMatchArray(['tool' => 'grid_paint']);

    expect(Heuristics::sessionStats('s')['by_kind'])->not->toHaveKey('click');
    expect(Heuristics::topElements('s', 30))->toBe([]);
    expect(Heuristics::heatmap('s', '/')['sample_count'])->toBe(0);
});

it('stores an in-page agent activity as agent.act with its verb', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'events' => [[
            'kind' => 'click', 'actor' => 'agent', 'path' => '/', 'x' => 0, 'y' => 0, 'vw' => 0, 'vh' => 0,
            'meta' => ['action' => 'form_fill', 'agentId' => 'a1', 'source' => 'agent'],
        ]],
    ]);

    $e = HeuristicsEvent::first();
    expect($e->name)->toBe('agent.act');
    expect($e->kind)->toBe('agent');
    expect($e->props)->toMatchArray(['action' => 'form_fill']);
});

it('maps agent dwell to engage.time with the agent actor', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'events' => [['kind' => 'dwell', 'actor' => 'agent', 'path' => '/', 'dwellMs' => 500, 'meta' => ['action' => 'ux_focus']]],
    ]);

    $e = HeuristicsEvent::first();
    expect($e->name)->toBe('engage.time');
    expect($e->kind)->toBe('dwell');
    expect($e->actor)->toBe('agent');
});
