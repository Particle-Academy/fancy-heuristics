<?php

declare(strict_types=1);

use FancyHeuristics\Facades\Heuristics;
use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsSession;

const CHROME_WIN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

it('upserts a session row from a collect batch with context', function () {
    Heuristics::collect([
        'siteKey' => 'showcase',
        'sessionId' => 'sess-1',
        'context' => [
            'referrer' => 'https://www.google.com/search?q=fancy',
            'utm' => ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'launch'],
            'lang' => 'en-GB',
            'tz' => 'Europe/London',
            'screenW' => 2560,
            'screenH' => 1440,
        ],
        'events' => [
            ['kind' => 'pageview', 'actor' => 'human', 'path' => '/', 'ts' => 1_717_000_000_000],
            ['kind' => 'click', 'actor' => 'human', 'path' => '/', 'ts' => 1_717_000_005_000],
        ],
    ], CHROME_WIN_UA);

    $session = HeuristicsSession::where('session_id', 'sess-1')->first();

    expect($session)->not->toBeNull();
    expect($session->site_key)->toBe('showcase');
    expect($session->referrer_host)->toBe('google.com');     // www. stripped
    expect($session->utm_source)->toBe('newsletter');
    expect($session->utm_medium)->toBe('email');
    expect($session->utm_campaign)->toBe('launch');
    expect($session->lang)->toBe('en-GB');
    expect($session->tz)->toBe('Europe/London');
    expect($session->screen_w)->toBe(2560);
    expect($session->device)->toBe('desktop');
    expect($session->os)->toBe('Windows');
    expect($session->browser)->toBe('Chrome');
    expect($session->landing_path)->toBe('/');
    expect($session->pageviews)->toBe(1);
    expect($session->events)->toBe(2);
    expect($session->duration_ms)->toBe(5000);  // 5s between the two events
});

it('rolls subsequent batches into the same session row', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'sess-2',
        'context' => ['referrer' => ''],
        'events' => [
            ['kind' => 'pageview', 'actor' => 'human', 'path' => '/', 'ts' => 1_717_000_000_000],
        ],
    ], CHROME_WIN_UA);

    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'sess-2',
        'events' => [
            ['kind' => 'pageview', 'actor' => 'human', 'path' => '/pricing', 'ts' => 1_717_000_010_000],
            ['kind' => 'click', 'actor' => 'agent', 'path' => '/pricing', 'ts' => 1_717_000_012_000],
        ],
    ], CHROME_WIN_UA);

    expect(HeuristicsSession::where('session_id', 'sess-2')->count())->toBe(1);

    $session = HeuristicsSession::where('session_id', 'sess-2')->first();
    expect($session->events)->toBe(3);
    expect($session->pageviews)->toBe(2);
    expect($session->landing_path)->toBe('/');         // first ever path
    expect($session->exit_path)->toBe('/pricing');     // latest path
    expect($session->actor)->toBe('agent');            // latest actor wins
    expect($session->is_bounce)->toBeFalse();          // 2 pageviews
    expect($session->duration_ms)->toBe(12000);
});

it('marks a single-pageview session as a bounce', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'bouncer',
        'context' => ['referrer' => 'https://example.com/'],
        'events' => [
            ['kind' => 'pageview', 'actor' => 'human', 'path' => '/', 'ts' => 1_717_000_000_000],
        ],
    ], CHROME_WIN_UA);

    expect(HeuristicsSession::where('session_id', 'bouncer')->first()->is_bounce)->toBeTrue();
});

it('stamps the truncated UA on collected events', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'sess-ua',
        'events' => [
            ['kind' => 'pageview', 'actor' => 'human', 'path' => '/', 'ts' => 1_717_000_000_000],
        ],
    ], CHROME_WIN_UA);

    expect(HeuristicsEvent::where('session_id', 'sess-ua')->first()->ua)->toBe(CHROME_WIN_UA);
});

it('tolerates a collect batch with no context and no UA', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'sessionId' => 'sess-bare',
        'events' => [
            ['kind' => 'pageview', 'actor' => 'human', 'path' => '/', 'ts' => 1_717_000_000_000],
        ],
    ]);

    $session = HeuristicsSession::where('session_id', 'sess-bare')->first();
    expect($session)->not->toBeNull();
    expect($session->referrer_host)->toBeNull();
    expect($session->device)->toBe('desktop'); // empty UA falls back to desktop
});

it('does not create a session row for a batch without a sessionId', function () {
    Heuristics::collect([
        'siteKey' => 's',
        'events' => [
            ['kind' => 'pageview', 'actor' => 'human', 'path' => '/', 'ts' => 1_717_000_000_000],
        ],
    ], CHROME_WIN_UA);

    expect(HeuristicsSession::count())->toBe(0);
});

it('passes the request UA through the collect endpoint into the session', function () {
    $this->withHeaders(['User-Agent' => CHROME_WIN_UA])
        ->postJson('/heuristics/collect', [
            'siteKey' => 'showcase',
            'sessionId' => 'http-sess',
            'context' => ['referrer' => 'https://news.ycombinator.com/'],
            'events' => [
                ['kind' => 'pageview', 'actor' => 'human', 'path' => '/', 'ts' => 1_717_000_000_000],
            ],
        ])
        ->assertStatus(202);

    $session = HeuristicsSession::where('session_id', 'http-sess')->first();
    expect($session->browser)->toBe('Chrome');
    expect($session->referrer_host)->toBe('news.ycombinator.com');
});
