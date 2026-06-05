<?php

declare(strict_types=1);

namespace FancyHeuristics\Facades;

use FancyHeuristics\HeuristicsManager;
use Illuminate\Support\Facades\Facade;

/**
 * Heuristics Facade
 *
 * @method static \FancyHeuristics\Models\HeuristicsEvent|null record(array $event) Persist a single interaction event
 * @method static \Illuminate\Support\Collection collect(array $payload) Persist a full collect batch { siteKey, sessionId, events }
 * @method static \FancyHeuristics\Models\HeuristicsPixelPing ping(array $ping) Persist a pixel liveness / visibility beacon
 * @method static array heatmap(string $siteKey, string $path, ?int $gridSize = null) Build a normalised heatmap grid
 * @method static \Illuminate\Support\Collection events(string $siteKey, array $filters = []) Query raw events for a site
 * @method static array sessionStats(string $siteKey) Summary stats for a site by actor + kind
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
