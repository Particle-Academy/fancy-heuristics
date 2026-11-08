<?php

declare(strict_types=1);

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsPixelPing;
use FancyHeuristics\Models\HeuristicsSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 0: retention is enforced by a command, pixel IP hashes are keyed,
 * the session lookup is indexed and the unbounded reports take a range.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function seedAged(string $kind, int $daysAgo): HeuristicsEvent
{
    return HeuristicsEvent::create([
        'site_key' => 's', 'session_id' => 'x', 'actor' => 'human',
        'kind' => $kind, 'path' => '/', 'x' => 1, 'y' => 1, 'vw' => 10, 'vh' => 10,
        'occurred_at' => Carbon::now()->subDays($daysAgo),
    ]);
}

it('prunes raw events, pointer samples and pings past their retention windows', function () {
    config([
        'heuristics.retention_days' => 90,
        'heuristics.retention.pointer_days' => 14,
        'heuristics.retention.pings_days' => 30,
    ]);

    seedAged('click', 100);     // past 90 days: pruned
    seedAged('click', 10);      // kept
    seedAged('pointer', 20);    // past 14 days: pruned
    seedAged('pointer', 5);     // kept

    HeuristicsPixelPing::create(['site_key' => 's', 'style' => 'badge', 'mode' => 'floating', 'visible' => true, 'path' => '/', 'pinged_at' => Carbon::now()->subDays(40)]);
    HeuristicsPixelPing::create(['site_key' => 's', 'style' => 'badge', 'mode' => 'floating', 'visible' => true, 'path' => '/', 'pinged_at' => Carbon::now()->subDays(1)]);

    $this->artisan('heuristics:prune')->assertSuccessful();

    expect(HeuristicsEvent::where('kind', 'click')->count())->toBe(1);
    expect(HeuristicsEvent::where('kind', 'pointer')->count())->toBe(1);
    expect(HeuristicsPixelPing::count())->toBe(1);
});

it('prunes sessions whose last activity is past the session window', function () {
    config(['heuristics.retention.sessions_days' => 395]);

    HeuristicsSession::create(['site_key' => 's', 'session_id' => 'old', 'last_event_at' => Carbon::now()->subDays(400), 'started_at' => Carbon::now()->subDays(400)]);
    HeuristicsSession::create(['site_key' => 's', 'session_id' => 'new', 'last_event_at' => Carbon::now()->subDays(1), 'started_at' => Carbon::now()->subDays(1)]);

    $this->artisan('heuristics:prune')->assertSuccessful();

    expect(HeuristicsSession::pluck('session_id')->all())->toBe(['new']);
});

it('reports what it would prune and deletes nothing on --dry-run', function () {
    seedAged('click', 400);

    $this->artisan('heuristics:prune', ['--dry-run' => true])
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect(HeuristicsEvent::count())->toBe(1);
});

it('hashes pixel IPs with a keyed HMAC, not a bare SHA-256', function () {
    config(['heuristics.ip_hash_key' => 'test-secret']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/heuristics/pixel', [
        'siteKey' => 's', 'style' => 'badge', 'mode' => 'floating', 'visible' => true, 'path' => '/',
    ])->assertStatus(202);

    $hash = HeuristicsPixelPing::first()->ip_hash;

    expect($hash)->not->toBe(hash('sha256', '203.0.113.9'));
    expect($hash)->toBe(hash_hmac('sha256', '203.0.113.9', 'test-secret'));
});

it('indexes the event session lookup', function () {
    $indexes = collect(Schema::getIndexes(config('heuristics.table_prefix').'events'));

    $match = $indexes->first(fn (array $i) => $i['columns'] === ['site_key', 'session_id', 'occurred_at']);

    expect($match)->not->toBeNull();
});

it('bounds sessionStats and the heatmap to a range when one is given', function () {
    seedAged('click', 2);
    seedAged('click', 60);

    $stats = Heuristics::sessionStats('s', 30);
    expect($stats['events'])->toBe(1);

    // No range keeps the old behaviour: everything.
    expect(Heuristics::sessionStats('s')['events'])->toBe(2);

    $heat = Heuristics::heatmap('s', '/', null, 30);
    expect($heat['sample_count'])->toBe(1);
    expect(Heuristics::heatmap('s', '/')['sample_count'])->toBe(2);
});
