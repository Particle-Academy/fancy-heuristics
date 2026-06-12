<?php

declare(strict_types=1);

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsSession;
use Illuminate\Support\Carbon;

/**
 * Seeds a small, deterministic set of sessions + events for 'site' so the
 * GA-parity query methods can be asserted against known shapes.
 */
function seedAnalytics(): void
{
    $now = Carbon::parse('2026-06-10 12:00:00');

    HeuristicsSession::create([
        'site_key' => 'site', 'session_id' => 'a', 'actor' => 'human',
        'referrer' => 'https://google.com/', 'referrer_host' => 'google.com',
        'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'launch',
        'landing_path' => '/', 'exit_path' => '/pricing',
        'device' => 'desktop', 'os' => 'Windows', 'browser' => 'Chrome', 'lang' => 'en-US',
        'started_at' => $now->copy()->subDays(1), 'last_event_at' => $now->copy()->subDays(1)->addSeconds(30),
        'duration_ms' => 30000, 'pageviews' => 3, 'events' => 8, 'is_bounce' => false,
    ]);

    HeuristicsSession::create([
        'site_key' => 'site', 'session_id' => 'b', 'actor' => 'human',
        'referrer' => null, 'referrer_host' => null,
        'landing_path' => '/', 'exit_path' => '/',
        'device' => 'mobile', 'os' => 'iOS', 'browser' => 'Safari', 'lang' => 'fr-FR',
        'started_at' => $now->copy()->subDays(2), 'last_event_at' => $now->copy()->subDays(2)->addSeconds(2),
        'duration_ms' => 2000, 'pageviews' => 1, 'events' => 1, 'is_bounce' => true,
    ]);

    HeuristicsSession::create([
        'site_key' => 'site', 'session_id' => 'c', 'actor' => 'agent',
        'referrer' => 'https://google.com/', 'referrer_host' => 'google.com',
        'landing_path' => '/docs', 'exit_path' => '/docs',
        'device' => 'desktop', 'os' => 'Linux', 'browser' => 'Chrome', 'lang' => 'en-US',
        'started_at' => $now->copy()->subDays(1), 'last_event_at' => $now->copy()->subDays(1)->addSeconds(10),
        'duration_ms' => 10000, 'pageviews' => 2, 'events' => 4, 'is_bounce' => false,
    ]);

    // A few click events for top elements + pageview events for top pages.
    foreach (['cta-buy' => 5, 'nav-docs' => 2] as $target => $n) {
        for ($i = 0; $i < $n; $i++) {
            HeuristicsEvent::create([
                'site_key' => 'site', 'session_id' => 'a', 'actor' => 'human',
                'kind' => 'click', 'path' => '/pricing', 'target_id' => $target,
                'label' => ucfirst($target), 'occurred_at' => $now->copy()->subDay(),
            ]);
        }
    }

    foreach (['/' => 4, '/pricing' => 2, '/docs' => 1] as $path => $n) {
        for ($i = 0; $i < $n; $i++) {
            HeuristicsEvent::create([
                'site_key' => 'site', 'session_id' => 'a', 'actor' => 'human',
                'kind' => 'pageview', 'path' => $path, 'occurred_at' => $now->copy()->subDay(),
            ]);
        }
    }
}

beforeEach(function () {
    Carbon::setTestNow('2026-06-10 12:00:00');
    seedAnalytics();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports acquisition: referrer hosts, utm, direct vs referral', function () {
    $acq = Heuristics::acquisition('site', 30);

    expect($acq['total'])->toBe(3);
    expect($acq['referral'])->toBe(2);   // a + c have a referrer_host
    expect($acq['direct'])->toBe(1);     // b is direct
    expect($acq['referrer_hosts'])->toContain(['host' => 'google.com', 'sessions' => 2]);
    expect($acq['utm_sources'])->toContain(['value' => 'newsletter', 'sessions' => 1]);
});

it('reports audience: device / browser / os / language', function () {
    $aud = Heuristics::audience('site', 30);

    expect($aud['total'])->toBe(3);
    expect($aud['devices'])->toContain(['value' => 'desktop', 'sessions' => 2]);
    expect($aud['devices'])->toContain(['value' => 'mobile', 'sessions' => 1]);
    expect($aud['browsers'])->toContain(['value' => 'Chrome', 'sessions' => 2]);
    expect($aud['os'])->toContain(['value' => 'iOS', 'sessions' => 1]);
    expect($aud['languages'])->toContain(['value' => 'en-US', 'sessions' => 2]);
});

it('filters audience by actor', function () {
    $agents = Heuristics::audience('site', 30, 'agent');

    expect($agents['total'])->toBe(1);
    expect($agents['os'])->toContain(['value' => 'Linux', 'sessions' => 1]);
});

it('reports a session summary with bounce rate + pages/session', function () {
    $summary = Heuristics::sessionsSummary('site', 30);

    expect($summary['sessions'])->toBe(3);
    expect($summary['pageviews'])->toBe(6);          // 3 + 1 + 2
    expect($summary['bounces'])->toBe(1);
    expect($summary['bounce_rate'])->toBe(round(1 / 3, 4));
    expect($summary['pages_per_session'])->toBe(2.0); // 6 / 3
});

it('reports a per-day timeseries, optionally split by actor', function () {
    $series = Heuristics::timeseries('site', 30, 'day');
    expect($series)->toBeArray();
    expect(collect($series)->sum('sessions'))->toBe(3);
    expect($series[0])->toHaveKeys(['bucket', 'sessions', 'pageviews']);

    $split = Heuristics::timeseries('site', 30, 'day', true);
    expect($split[0])->toHaveKeys(['human_sessions', 'agent_sessions']);
    expect(collect($split)->sum('agent_sessions'))->toBe(1);
    expect(collect($split)->sum('human_sessions'))->toBe(2);
});

it('reports top, entry, and exit pages', function () {
    $top = Heuristics::topPages('site', 30);
    expect($top[0])->toMatchArray(['path' => '/', 'pageviews' => 4]);

    $entry = Heuristics::entryPages('site', 30);
    expect(collect($entry)->firstWhere('path', '/')['sessions'])->toBe(2); // a + b land on /

    $exit = Heuristics::exitPages('site', 30);
    expect(collect($exit)->pluck('path'))->toContain('/pricing');
});

it('reports the most-clicked elements', function () {
    $elements = Heuristics::topElements('site', 30);

    expect($elements[0])->toMatchArray(['target_id' => 'cta-buy', 'clicks' => 5]);
    expect($elements[0]['label'])->toBe('Cta-buy');
    expect($elements[1])->toMatchArray(['target_id' => 'nav-docs', 'clicks' => 2]);
});

it('reports realtime sessions active in the last 5 minutes', function () {
    // Push session 'a' to be active 1 minute ago.
    HeuristicsSession::where('session_id', 'a')->update([
        'last_event_at' => Carbon::now()->subMinute(),
    ]);

    $rt = Heuristics::realtime('site');

    expect($rt['active'])->toBe(1);
    expect($rt['window_seconds'])->toBe(300);
    expect($rt['sessions'][0])->toMatchArray(['session_id' => 'a', 'actor' => 'human', 'path' => '/pricing']);
});

it('accepts an explicit from/to range', function () {
    $summary = Heuristics::sessionsSummary('site', [
        'from' => Carbon::parse('2026-06-09 00:00:00'),  // only day-1 sessions (a, c)
        'to' => Carbon::parse('2026-06-10 23:59:59'),
    ]);

    expect($summary['sessions'])->toBe(2);  // a + c started 1 day ago; b started 2 days ago
});
