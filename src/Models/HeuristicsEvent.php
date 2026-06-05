<?php

declare(strict_types=1);

namespace FancyHeuristics\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single ingested interaction event.
 *
 * @property int $id
 * @property string $site_key
 * @property string|null $session_id
 * @property string $actor human|agent
 * @property string $kind pageview|click|scroll|pointer|dwell
 * @property string $path
 * @property int|null $x
 * @property int|null $y
 * @property int|null $vw
 * @property int|null $vh
 * @property float|null $scroll_pct
 * @property int|null $dwell_ms
 * @property string|null $target_id
 * @property string|null $label
 * @property array<string, mixed>|null $meta
 * @property Carbon $occurred_at
 */
class HeuristicsEvent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'site_key',
        'session_id',
        'actor',
        'kind',
        'path',
        'x',
        'y',
        'vw',
        'vh',
        'scroll_pct',
        'dwell_ms',
        'target_id',
        'label',
        'meta',
        'occurred_at',
    ];

    public function getTable(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'events';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'vw' => 'integer',
            'vh' => 'integer',
            'scroll_pct' => 'float',
            'dwell_ms' => 'integer',
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Scope to a site_key.
     *
     * @param  Builder<HeuristicsEvent>  $query
     * @return Builder<HeuristicsEvent>
     */
    public function scopeForSite($query, string $siteKey)
    {
        return $query->where('site_key', $siteKey);
    }
}
