<?php

declare(strict_types=1);

use FancyHeuristics\Models\HeuristicsEvent;
use Illuminate\Support\Carbon;

/*
 * Phase 0: ingest bounds. Every string the wire carries is bounded, oversize
 * batches are refused as a whole, and an invalid event is reported back by
 * index and code rather than silently dropped.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function nowMs(): int
{
    return (int) Carbon::now()->getTimestampMs();
}

it('refuses a batch with more events than the configured cap', function () {
    config(['heuristics.limits.max_events_per_batch' => 3]);

    $events = array_fill(0, 4, ['kind' => 'click', 'path' => '/', 'ts' => nowMs()]);

    $this->postJson('/heuristics/collect', ['siteKey' => 's', 'events' => $events])
        ->assertStatus(422);

    expect(HeuristicsEvent::count())->toBe(0);
});

it('reports rejected events by index and code, without echoing values', function () {
    $response = $this->postJson('/heuristics/collect', [
        'siteKey' => 's',
        'events' => [
            ['kind' => 'bogus', 'path' => '/', 'ts' => nowMs()],
            ['kind' => 'click', 'ts' => nowMs()],
            ['kind' => 'click', 'path' => '/ok', 'ts' => nowMs()],
        ],
    ]);

    $response->assertStatus(202)->assertJson([
        'ok' => true,
        'accepted' => 1,
        'rejected' => 2,
        'errors' => [
            ['index' => 0, 'code' => 'invalid_kind'],
            ['index' => 1, 'code' => 'invalid_path'],
        ],
    ]);

    expect($response->getContent())->not->toContain('bogus');
});

it('strips the query string and fragment from a path', function () {
    $this->postJson('/heuristics/collect', [
        'siteKey' => 's',
        'events' => [['kind' => 'pageview', 'path' => '/reset?token=secret#frag', 'ts' => nowMs()]],
    ])->assertStatus(202);

    expect(HeuristicsEvent::first()->path)->toBe('/reset');
});

it('rejects a path longer than the configured bound', function () {
    $this->postJson('/heuristics/collect', [
        'siteKey' => 's',
        'events' => [['kind' => 'pageview', 'path' => '/'.str_repeat('a', 300), 'ts' => nowMs()]],
    ])->assertStatus(202)->assertJson(['accepted' => 0, 'errors' => [['index' => 0, 'code' => 'invalid_path']]]);

    expect(HeuristicsEvent::count())->toBe(0);
});

it('bounds a legacy label and normalises its whitespace', function () {
    $this->postJson('/heuristics/collect', [
        'siteKey' => 's',
        'events' => [[
            'kind' => 'click',
            'path' => '/',
            'label' => "  Sign \n\t in  ".str_repeat('x', 400),
            'ts' => nowMs(),
        ]],
    ])->assertStatus(202);

    $label = HeuristicsEvent::first()->label;
    expect(mb_strlen($label))->toBeLessThanOrEqual(120);
    expect($label)->toStartWith('Sign in x');
});

it('rejects meta over the byte cap and meta that is not flat scalars', function () {
    config(['heuristics.limits.max_meta_bytes' => 64]);

    $this->postJson('/heuristics/collect', [
        'siteKey' => 's',
        'events' => [
            ['kind' => 'click', 'path' => '/', 'ts' => nowMs(), 'meta' => ['blob' => str_repeat('x', 200)]],
            ['kind' => 'click', 'path' => '/', 'ts' => nowMs(), 'meta' => ['ok' => 'yes']],
        ],
    ])->assertStatus(202)->assertJson([
        'accepted' => 1,
        'errors' => [['index' => 0, 'code' => 'invalid_meta']],
    ]);
});

it('rejects timestamps beyond the configured skew', function () {
    $this->postJson('/heuristics/collect', [
        'siteKey' => 's',
        'events' => [
            ['kind' => 'click', 'path' => '/', 'ts' => nowMs() + 3 * 86_400_000],
            ['kind' => 'click', 'path' => '/', 'ts' => nowMs() - 60 * 86_400_000],
            ['kind' => 'click', 'path' => '/', 'ts' => nowMs()],
        ],
    ])->assertStatus(202)->assertJson([
        'accepted' => 1,
        'errors' => [
            ['index' => 0, 'code' => 'invalid_ts'],
            ['index' => 1, 'code' => 'invalid_ts'],
        ],
    ]);
});

it('rejects impossible numbers', function () {
    $this->postJson('/heuristics/collect', [
        'siteKey' => 's',
        'events' => [
            ['kind' => 'scroll', 'path' => '/', 'ts' => nowMs(), 'scrollPct' => 140],
            ['kind' => 'dwell', 'path' => '/', 'ts' => nowMs(), 'dwellMs' => -5],
            ['kind' => 'pointer', 'path' => '/', 'ts' => nowMs(), 'x' => 10, 'y' => 10, 'vw' => -1, 'vh' => 800],
            ['kind' => 'scroll', 'path' => '/', 'ts' => nowMs(), 'scrollPct' => 50],
        ],
    ])->assertStatus(202)->assertJson([
        'accepted' => 1,
        'errors' => [
            ['index' => 0, 'code' => 'invalid_number'],
            ['index' => 1, 'code' => 'invalid_number'],
            ['index' => 2, 'code' => 'invalid_number'],
        ],
    ]);
});

it('refuses an over-long site key or session id', function () {
    $this->postJson('/heuristics/collect', [
        'siteKey' => str_repeat('k', 300),
        'events' => [['kind' => 'click', 'path' => '/', 'ts' => nowMs()]],
    ])->assertStatus(422);

    $this->postJson('/heuristics/collect', [
        'siteKey' => 's',
        'sessionId' => str_repeat('s', 300),
        'events' => [['kind' => 'click', 'path' => '/', 'ts' => nowMs()]],
    ])->assertStatus(422);
});
