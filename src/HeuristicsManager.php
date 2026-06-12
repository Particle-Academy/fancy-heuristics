<?php

declare(strict_types=1);

namespace FancyHeuristics;

use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsPixelPing;
use FancyHeuristics\Models\HeuristicsSession;
use FancyHeuristics\Services\EventCollector;
use FancyHeuristics\Services\HeatmapAggregator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Central API for Fancy Heuristics, exposed via the `Heuristics` facade.
 *
 * Delegates persistence to EventCollector and aggregation to
 * HeatmapAggregator while offering a few terse query helpers.
 */
class HeuristicsManager
{
    public function __construct(
        protected EventCollector $collector,
        protected HeatmapAggregator $heatmaps,
    ) {}

    /**
     * Persist a single interaction event (wire-shaped or snake_case).
     *
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): ?HeuristicsEvent
    {
        return $this->collector->record($event);
    }

    /**
     * Persist a full collect batch: { siteKey, sessionId, events: [...], context?: {...} }.
     *
     * @param  array<string, mixed>  $payload
     * @param  string|null  $userAgent  Request User-Agent — stamped on events + used to classify the session.
     * @return Collection<int, HeuristicsEvent>
     */
    public function collect(array $payload, ?string $userAgent = null): Collection
    {
        return $this->collector->collect($payload, $userAgent);
    }

    /**
     * Persist a pixel liveness / visibility beacon.
     *
     * @param  array<string, mixed>  $ping  { siteKey, style, mode, visible, path, ts?, ua?, ipHash? }
     */
    public function ping(array $ping): HeuristicsPixelPing
    {
        return HeuristicsPixelPing::create([
            'site_key' => (string) ($ping['siteKey'] ?? $ping['site_key'] ?? ''),
            'style' => (string) ($ping['style'] ?? 'badge'),
            'mode' => (string) ($ping['mode'] ?? 'floating'),
            'visible' => (bool) ($ping['visible'] ?? false),
            'path' => (string) ($ping['path'] ?? '/'),
            'ua' => $ping['ua'] ?? null,
            'ip_hash' => $ping['ipHash'] ?? $ping['ip_hash'] ?? null,
            'pinged_at' => $this->resolveTimestamp($ping['ts'] ?? $ping['pinged_at'] ?? null),
        ]);
    }

    /**
     * Build a normalised heatmap grid for a site/path.
     *
     * @return array<string, mixed>
     */
    public function heatmap(string $siteKey, string $path, ?int $gridSize = null): array
    {
        return $this->heatmaps->aggregate($siteKey, $path, $gridSize);
    }

    /**
     * Query raw events for a site, optionally filtered by path/kind/actor.
     *
     * @param  array{path?: string, kind?: string, actor?: string, limit?: int}  $filters
     * @return Collection<int, HeuristicsEvent>
     */
    public function events(string $siteKey, array $filters = []): Collection
    {
        $query = HeuristicsEvent::query()->where('site_key', $siteKey);

        if (! empty($filters['path'])) {
            $query->where('path', $filters['path']);
        }

        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        if (! empty($filters['actor'])) {
            $query->where('actor', $filters['actor']);
        }

        $query->orderByDesc('occurred_at');

        if (! empty($filters['limit'])) {
            $query->limit((int) $filters['limit']);
        }

        return $query->get();
    }

    /**
     * Summary stats for a site, broken down by actor (human vs agent).
     *
     * @return array{
     *     site_key: string,
     *     sessions: int,
     *     events: int,
     *     by_actor: array<string, int>,
     *     by_kind: array<string, int>
     * }
     */
    public function sessionStats(string $siteKey): array
    {
        $base = HeuristicsEvent::query()->where('site_key', $siteKey);

        $byActor = (clone $base)
            ->selectRaw('actor, COUNT(*) as aggregate')
            ->groupBy('actor')
            ->pluck('aggregate', 'actor')
            ->map(fn ($v) => (int) $v)
            ->all();

        $byKind = (clone $base)
            ->selectRaw('kind, COUNT(*) as aggregate')
            ->groupBy('kind')
            ->pluck('aggregate', 'kind')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [
            'site_key' => $siteKey,
            'sessions' => (int) (clone $base)->distinct()->count('session_id'),
            'events' => (int) (clone $base)->count(),
            'by_actor' => $byActor,
            'by_kind' => $byKind,
        ];
    }

    // ---------------------------------------------------------------------
    // GA-parity reports (Phase B). Each takes a $site, a date $range, and an
    // optional $actor filter ('human' | 'agent' | null = all). Built against
    // the heuristics_sessions rollup (+ heuristics_events for elements). All
    // return primitive, JSON-friendly arrays.
    //
    // $range accepts either:
    //   - an int N  => the last N days (started_at >= now()->subDays(N)), or
    //   - ['from' => Carbon|string, 'to' => Carbon|string] (inclusive).
    // Both compare against the session's started_at.
    // ---------------------------------------------------------------------

    /**
     * Acquisition: how sessions arrived — top referrer hosts, utm breakdowns,
     * and the direct vs referral split.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return array{
     *     referrer_hosts: list<array{host: string, sessions: int}>,
     *     utm_sources: list<array{value: string, sessions: int}>,
     *     utm_mediums: list<array{value: string, sessions: int}>,
     *     utm_campaigns: list<array{value: string, sessions: int}>,
     *     direct: int,
     *     referral: int,
     *     total: int
     * }
     */
    public function acquisition(string $site, int|array $range, ?string $actor = null): array
    {
        $base = fn () => $this->sessionsInRange($site, $range, $actor);

        $referral = (int) $base()->whereNotNull('referrer_host')->count();
        $total = (int) $base()->count();

        return [
            'referrer_hosts' => $this->breakdown($base(), 'referrer_host'),
            'utm_sources' => $this->breakdown($base(), 'utm_source'),
            'utm_mediums' => $this->breakdown($base(), 'utm_medium'),
            'utm_campaigns' => $this->breakdown($base(), 'utm_campaign'),
            'direct' => $total - $referral,
            'referral' => $referral,
            'total' => $total,
        ];
    }

    /**
     * Audience: device category, browser, os, and language breakdowns.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return array{
     *     devices: list<array{value: string, sessions: int}>,
     *     browsers: list<array{value: string, sessions: int}>,
     *     os: list<array{value: string, sessions: int}>,
     *     languages: list<array{value: string, sessions: int}>,
     *     total: int
     * }
     */
    public function audience(string $site, int|array $range, ?string $actor = null): array
    {
        $base = fn () => $this->sessionsInRange($site, $range, $actor);

        return [
            'devices' => $this->breakdown($base(), 'device'),
            'browsers' => $this->breakdown($base(), 'browser'),
            'os' => $this->breakdown($base(), 'os'),
            'languages' => $this->breakdown($base(), 'lang'),
            'total' => (int) $base()->count(),
        ];
    }

    /**
     * Time series of sessions + pageviews per bucket. When $splitActor is true,
     * each bucket also carries separate human/agent counts.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @param  'day'|'week'|'month'  $interval
     * @return list<array{
     *     bucket: string,
     *     sessions: int,
     *     pageviews: int,
     *     human_sessions?: int,
     *     agent_sessions?: int
     * }>
     */
    public function timeseries(string $site, int|array $range, string $interval = 'day', bool $splitActor = false): array
    {
        $expr = $this->bucketExpression('started_at', $interval);

        $rows = $this->sessionsInRange($site, $range, null)
            ->selectRaw("$expr as bucket")
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('SUM(pageviews) as pageviews')
            ->selectRaw("SUM(CASE WHEN actor = 'human' THEN 1 ELSE 0 END) as human_sessions")
            ->selectRaw("SUM(CASE WHEN actor = 'agent' THEN 1 ELSE 0 END) as agent_sessions")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(function ($row) use ($splitActor) {
            $bucket = [
                'bucket' => (string) $row->bucket,
                'sessions' => (int) $row->sessions,
                'pageviews' => (int) $row->pageviews,
            ];

            if ($splitActor) {
                $bucket['human_sessions'] = (int) $row->human_sessions;
                $bucket['agent_sessions'] = (int) $row->agent_sessions;
            }

            return $bucket;
        })->all();
    }

    /**
     * Headline session totals: counts, averages, bounce rate, pages/session.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return array{
     *     sessions: int,
     *     pageviews: int,
     *     events: int,
     *     avg_duration_ms: float,
     *     avg_events: float,
     *     pages_per_session: float,
     *     bounce_rate: float,
     *     bounces: int
     * }
     */
    public function sessionsSummary(string $site, int|array $range, ?string $actor = null): array
    {
        $row = $this->sessionsInRange($site, $range, $actor)
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COALESCE(SUM(pageviews), 0) as pageviews')
            ->selectRaw('COALESCE(SUM(events), 0) as events')
            ->selectRaw('COALESCE(AVG(duration_ms), 0) as avg_duration_ms')
            ->selectRaw('COALESCE(AVG(events), 0) as avg_events')
            ->selectRaw('SUM(CASE WHEN is_bounce THEN 1 ELSE 0 END) as bounces')
            ->first();

        $sessions = (int) ($row->sessions ?? 0);
        $pageviews = (int) ($row->pageviews ?? 0);
        $bounces = (int) ($row->bounces ?? 0);

        return [
            'sessions' => $sessions,
            'pageviews' => $pageviews,
            'events' => (int) ($row->events ?? 0),
            'avg_duration_ms' => round((float) ($row->avg_duration_ms ?? 0), 2),
            'avg_events' => round((float) ($row->avg_events ?? 0), 2),
            'pages_per_session' => $sessions > 0 ? round($pageviews / $sessions, 2) : 0.0,
            'bounce_rate' => $sessions > 0 ? round($bounces / $sessions, 4) : 0.0,
            'bounces' => $bounces,
        ];
    }

    /**
     * Top pages by pageview count across all events in range (every path seen,
     * not just landing/exit). Ranked by pageviews then unique sessions.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return list<array{path: string, pageviews: int, sessions: int}>
     */
    public function topPages(string $site, int|array $range, ?string $actor = null, int $limit = 20): array
    {
        [$from, $to] = $this->resolveRange($range);

        $query = HeuristicsEvent::query()
            ->where('site_key', $site)
            ->where('kind', 'pageview')
            ->whereBetween('occurred_at', [$from, $to]);

        if ($actor !== null) {
            $query->where('actor', $actor);
        }

        $rows = $query
            ->selectRaw('path')
            ->selectRaw('COUNT(*) as pageviews')
            ->selectRaw('COUNT(DISTINCT session_id) as sessions')
            ->groupBy('path')
            ->orderByDesc('pageviews')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'path' => (string) $row->path,
            'pageviews' => (int) $row->pageviews,
            'sessions' => (int) $row->sessions,
        ])->all();
    }

    /**
     * Entry (landing) pages by session count.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return list<array{path: string, sessions: int}>
     */
    public function entryPages(string $site, int|array $range, ?string $actor = null, int $limit = 20): array
    {
        return $this->pageBreakdown($site, $range, $actor, 'landing_path', $limit);
    }

    /**
     * Exit pages by session count.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return list<array{path: string, sessions: int}>
     */
    public function exitPages(string $site, int|array $range, ?string $actor = null, int $limit = 20): array
    {
        return $this->pageBreakdown($site, $range, $actor, 'exit_path', $limit);
    }

    /**
     * Most-clicked elements by target_id / label, from click events in range.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return list<array{target_id: string, label: string|null, clicks: int}>
     */
    public function topElements(string $site, int|array $range, ?string $actor = null, int $limit = 20): array
    {
        [$from, $to] = $this->resolveRange($range);

        $query = HeuristicsEvent::query()
            ->where('site_key', $site)
            ->where('kind', 'click')
            ->whereNotNull('target_id')
            ->where('target_id', '!=', '')
            ->whereBetween('occurred_at', [$from, $to]);

        if ($actor !== null) {
            $query->where('actor', $actor);
        }

        $rows = $query
            ->selectRaw('target_id')
            ->selectRaw('MAX(label) as label')
            ->selectRaw('COUNT(*) as clicks')
            ->groupBy('target_id')
            ->orderByDesc('clicks')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'target_id' => (string) $row->target_id,
            'label' => $row->label !== null ? (string) $row->label : null,
            'clicks' => (int) $row->clicks,
        ])->all();
    }

    /**
     * Sessions active in the last 5 minutes (by last_event_at), with their
     * current path + actor. The realtime pulse.
     *
     * @return array{
     *     active: int,
     *     window_seconds: int,
     *     sessions: list<array{session_id: string, actor: string, path: string|null, last_event_at: string|null}>
     * }
     */
    public function realtime(string $site): array
    {
        $since = Carbon::now()->subMinutes(5);

        $rows = HeuristicsSession::query()
            ->where('site_key', $site)
            ->where('last_event_at', '>=', $since)
            ->orderByDesc('last_event_at')
            ->get(['session_id', 'actor', 'exit_path', 'last_event_at']);

        return [
            'active' => $rows->count(),
            'window_seconds' => 300,
            'sessions' => $rows->map(fn ($row) => [
                'session_id' => (string) $row->session_id,
                'actor' => (string) $row->actor,
                'path' => $row->exit_path !== null ? (string) $row->exit_path : null,
                'last_event_at' => $row->last_event_at?->toIso8601String(),
            ])->all(),
        ];
    }

    // ---------------------------------------------------------------------
    // Query helpers for the GA-parity reports.
    // ---------------------------------------------------------------------

    /**
     * A fresh sessions query scoped to (site, range, actor?).
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return Builder<HeuristicsSession>
     */
    protected function sessionsInRange(string $site, int|array $range, ?string $actor): Builder
    {
        [$from, $to] = $this->resolveRange($range);

        $query = HeuristicsSession::query()
            ->where('site_key', $site)
            ->whereBetween('started_at', [$from, $to]);

        if ($actor !== null) {
            $query->where('actor', $actor);
        }

        return $query;
    }

    /**
     * Count sessions grouped by a single column, descending, ignoring nulls.
     *
     * @param  Builder<HeuristicsSession>  $query
     * @return list<array{value: string, sessions: int}>|list<array{host: string, sessions: int}>
     */
    protected function breakdown(Builder $query, string $column, int $limit = 20): array
    {
        $rows = $query
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->selectRaw("$column as value")
            ->selectRaw('COUNT(*) as sessions')
            ->groupBy($column)
            ->orderByDesc('sessions')
            ->limit($limit)
            ->get();

        $key = $column === 'referrer_host' ? 'host' : 'value';

        return $rows->map(fn ($row) => [
            $key => (string) $row->value,
            'sessions' => (int) $row->sessions,
        ])->all();
    }

    /**
     * Sessions grouped by a path column (landing/exit), descending.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return list<array{path: string, sessions: int}>
     */
    protected function pageBreakdown(string $site, int|array $range, ?string $actor, string $column, int $limit): array
    {
        $rows = $this->sessionsInRange($site, $range, $actor)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->selectRaw("$column as path")
            ->selectRaw('COUNT(*) as sessions')
            ->groupBy($column)
            ->orderByDesc('sessions')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'path' => (string) $row->path,
            'sessions' => (int) $row->sessions,
        ])->all();
    }

    /**
     * A portable per-bucket date expression for the active DB driver.
     * SQLite uses strftime; MySQL/Postgres use date_trunc-style formatting.
     */
    protected function bucketExpression(string $column, string $interval): string
    {
        $driver = HeuristicsSession::query()->getConnection()->getDriverName();

        return match ($driver) {
            'sqlite' => match ($interval) {
                'week' => "strftime('%Y-%W', $column)",
                'month' => "strftime('%Y-%m', $column)",
                default => "strftime('%Y-%m-%d', $column)",
            },
            'pgsql' => match ($interval) {
                'week' => "to_char(date_trunc('week', $column), 'IYYY-IW')",
                'month' => "to_char($column, 'YYYY-MM')",
                default => "to_char($column, 'YYYY-MM-DD')",
            },
            default => match ($interval) { // mysql / mariadb
                'week' => "DATE_FORMAT($column, '%x-%v')",
                'month' => "DATE_FORMAT($column, '%Y-%m')",
                default => "DATE_FORMAT($column, '%Y-%m-%d')",
            },
        };
    }

    /**
     * Normalise the $range argument into a [from, to] Carbon pair.
     *
     * @param  int|array{from?: Carbon|string, to?: Carbon|string}  $range
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveRange(int|array $range): array
    {
        if (is_int($range)) {
            return [Carbon::now()->subDays(max(0, $range))->startOfDay(), Carbon::now()];
        }

        $from = isset($range['from']) ? Carbon::parse($range['from']) : Carbon::now()->subDays(30)->startOfDay();
        $to = isset($range['to']) ? Carbon::parse($range['to']) : Carbon::now();

        return [$from, $to];
    }

    protected function resolveTimestamp(mixed $ts): Carbon
    {
        if (is_numeric($ts)) {
            return Carbon::createFromTimestampMs((int) $ts);
        }

        if (is_string($ts) && $ts !== '') {
            try {
                return Carbon::parse($ts);
            } catch (\Throwable) {
                // fall through
            }
        }

        return Carbon::now();
    }
}
