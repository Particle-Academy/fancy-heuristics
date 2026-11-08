<?php

declare(strict_types=1);

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsSession;
use FancyHeuristics\Services\EventCollector;
use FancyHeuristics\Services\SessionRollup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * Phase 0: the session rollup is ordered by timestamp, atomic against
 * concurrent batches, and committed together with the events it counts.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function rollupTs(int $seconds): int
{
    return (int) Carbon::parse('2026-11-08 11:00:00')->addSeconds($seconds)->getTimestampMs();
}

it('takes landing and exit paths from timestamp order, not array order', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'ord',
        'events' => [
            ['kind' => 'pageview', 'path' => '/second', 'ts' => rollupTs(20)],
            ['kind' => 'pageview', 'path' => '/first', 'ts' => rollupTs(10)],
        ],
    ]);

    $session = HeuristicsSession::where('session_id', 'ord')->first();
    expect($session->landing_path)->toBe('/first');
    expect($session->exit_path)->toBe('/second');
});

it('lets a late, older batch move the landing path back but never the exit path', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'late',
        'events' => [['kind' => 'pageview', 'path' => '/b', 'ts' => rollupTs(60)]],
    ]);

    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'late',
        'events' => [['kind' => 'pageview', 'path' => '/a', 'ts' => rollupTs(0)]],
    ]);

    $session = HeuristicsSession::where('session_id', 'late')->first();
    expect($session->landing_path)->toBe('/a');
    expect($session->exit_path)->toBe('/b');
    expect($session->pageviews)->toBe(2);
    expect($session->duration_ms)->toBe(60_000);
});

it('increments counters in SQL rather than writing back a value read earlier', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'atomic',
        'events' => [['kind' => 'pageview', 'path' => '/', 'ts' => rollupTs(0)]],
    ]);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });

    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'atomic',
        'events' => [['kind' => 'click', 'path' => '/', 'ts' => rollupTs(5)]],
    ]);

    $increment = collect($queries)->first(
        fn (string $sql) => str_contains($sql, 'update') && str_contains($sql, '"events" = "events" +')
    );

    expect($increment)->not->toBeNull();
    expect(HeuristicsSession::where('session_id', 'atomic')->first()->events)->toBe(2);
});

it('does not keep the events when the rollup fails (one transaction per batch)', function () {
    $failing = new class extends SessionRollup
    {
        public function rollUp(string $siteKey, ?string $sessionId, Collection $events, ?array $context = null, ?string $userAgent = null): ?HeuristicsSession
        {
            throw new RuntimeException('rollup failed');
        }
    };

    $collector = new EventCollector($failing);

    expect(fn () => $collector->collect([
        'siteKey' => 's',
        'sessionId' => 'tx',
        'events' => [['kind' => 'pageview', 'path' => '/', 'ts' => rollupTs(0)]],
    ]))->toThrow(RuntimeException::class);

    expect(HeuristicsEvent::count())->toBe(0);
});
