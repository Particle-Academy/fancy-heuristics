<?php

declare(strict_types=1);

namespace FancyHeuristics\Facades;

use FancyHeuristics\HeuristicsManager;
use Illuminate\Support\Facades\Facade;

/**
 * Heuristics Facade
 *
 * @method static \FancyHeuristics\Models\HeuristicsEvent|null record(array $event) Persist a single interaction event
 * @method static \Illuminate\Support\Collection collect(array $payload, ?string $userAgent = null) Persist a full collect batch { siteKey, sessionId, events, context? } + roll up the session
 * @method static \FancyHeuristics\Models\HeuristicsPixelPing ping(array $ping) Persist a pixel liveness / visibility beacon
 * @method static array heatmap(string $siteKey, string $path, ?int $gridSize = null) Build a normalised heatmap grid
 * @method static \Illuminate\Support\Collection events(string $siteKey, array $filters = []) Query raw events for a site
 * @method static array sessionStats(string $siteKey) Summary stats for a site by actor + kind
 * @method static array acquisition(string $site, int|array $range, ?string $actor = null) Top referrers, utm breakdowns, direct vs referral
 * @method static array audience(string $site, int|array $range, ?string $actor = null) Device / browser / os / language breakdowns
 * @method static array timeseries(string $site, int|array $range, string $interval = 'day', bool $splitActor = false) Sessions/pageviews per bucket
 * @method static array sessionsSummary(string $site, int|array $range, ?string $actor = null) Totals, averages, bounce rate, pages/session
 * @method static array topPages(string $site, int|array $range, ?string $actor = null, int $limit = 20) Top pages by pageviews
 * @method static array entryPages(string $site, int|array $range, ?string $actor = null, int $limit = 20) Landing pages by session count
 * @method static array exitPages(string $site, int|array $range, ?string $actor = null, int $limit = 20) Exit pages by session count
 * @method static array topElements(string $site, int|array $range, ?string $actor = null, int $limit = 20) Most-clicked target_id/label
 * @method static array realtime(string $site) Sessions active in the last 5 minutes
 *
 * @see HeuristicsManager
 */
class Heuristics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'heuristics';
    }
}
