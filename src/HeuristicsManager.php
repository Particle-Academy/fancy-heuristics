<?php

declare(strict_types=1);

namespace FancyHeuristics;

use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsPixelPing;
use FancyHeuristics\Services\EventCollector;
use FancyHeuristics\Services\HeatmapAggregator;
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
     * Persist a full collect batch: { siteKey, sessionId, events: [...] }.
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<int, HeuristicsEvent>
     */
    public function collect(array $payload): Collection
    {
        return $this->collector->collect($payload);
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
