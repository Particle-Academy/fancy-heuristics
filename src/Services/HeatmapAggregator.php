<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Models\HeuristicsEvent;

/**
 * Aggregates pointer/click events into a normalised grid per (site_key, path).
 *
 * Each event's x/y is normalised against its own viewport (vw/vh) so samples
 * from different screen sizes land in the same 0..1 space, then bucketed into
 * a `grid_size` x `grid_size` grid. The result is a dense list of cells with
 * hit counts plus the grid metadata, ready to render as a heatmap.
 */
class HeatmapAggregator
{
    /**
     * @var list<string> Event kinds that carry pointer coordinates.
     */
    public const POINTER_KINDS = ['pointer', 'click'];

    /**
     * Build a heatmap for a site/path.
     *
     * @return array{
     *     site_key: string,
     *     path: string,
     *     grid_size: int,
     *     sample_count: int,
     *     max: int,
     *     cells: list<array{x:int, y:int, count:int, weight:float}>
     * }
     */
    public function aggregate(string $siteKey, string $path, ?int $gridSize = null): array
    {
        $gridSize = $gridSize ?? (int) config('heuristics.heatmap.grid_size', 24);
        $gridSize = max(1, $gridSize);

        /** @var array<int, int> $buckets keyed by (row * gridSize + col) */
        $buckets = [];
        $sampleCount = 0;

        $events = HeuristicsEvent::query()
            ->where('site_key', $siteKey)
            ->where('path', $path)
            ->whereIn('kind', self::POINTER_KINDS)
            ->whereNotNull('x')
            ->whereNotNull('y')
            ->get(['x', 'y', 'vw', 'vh']);

        foreach ($events as $event) {
            $cell = $this->bucketFor($event, $gridSize);

            if ($cell === null) {
                continue;
            }

            [$col, $row] = $cell;
            $key = $row * $gridSize + $col;
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            $sampleCount++;
        }

        $max = $buckets === [] ? 0 : max($buckets);

        $cells = [];
        foreach ($buckets as $key => $count) {
            $cells[] = [
                'x' => $key % $gridSize,
                'y' => intdiv($key, $gridSize),
                'count' => $count,
                'weight' => $max > 0 ? round($count / $max, 4) : 0.0,
            ];
        }

        return [
            'site_key' => $siteKey,
            'path' => $path,
            'grid_size' => $gridSize,
            'sample_count' => $sampleCount,
            'max' => $max,
            'cells' => $cells,
        ];
    }

    /**
     * Compute the [col, row] grid cell for an event, or null if it can't be
     * normalised (missing/zero viewport, out-of-bounds).
     *
     * @return array{0:int, 1:int}|null
     */
    protected function bucketFor(HeuristicsEvent $event, int $gridSize): ?array
    {
        $vw = (int) ($event->vw ?? 0);
        $vh = (int) ($event->vh ?? 0);

        if ($vw <= 0 || $vh <= 0) {
            return null;
        }

        $nx = (int) $event->x / $vw;
        $ny = (int) $event->y / $vh;

        // Clamp into [0, 1) so points exactly on the far edge map to the last cell.
        $nx = min(max($nx, 0.0), 0.999999);
        $ny = min(max($ny, 0.0), 0.999999);

        $col = (int) floor($nx * $gridSize);
        $row = (int) floor($ny * $gridSize);

        return [$col, $row];
    }
}
