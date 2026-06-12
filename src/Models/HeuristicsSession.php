<?php

declare(strict_types=1);

namespace FancyHeuristics\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A per-session rollup derived from the collect stream.
 *
 * @property int $id
 * @property string $site_key
 * @property string $session_id
 * @property string $actor human|agent
 * @property string|null $referrer
 * @property string|null $referrer_host
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_term
 * @property string|null $utm_content
 * @property string|null $landing_path
 * @property string|null $exit_path
 * @property string|null $device mobile|tablet|desktop
 * @property string|null $os Windows|macOS|iOS|Android|Linux|Other
 * @property string|null $browser Chrome|Safari|Firefox|Edge|Other
 * @property string|null $lang
 * @property string|null $tz
 * @property int|null $screen_w
 * @property int|null $screen_h
 * @property string|null $country
 * @property Carbon|null $started_at
 * @property Carbon|null $last_event_at
 * @property int $duration_ms
 * @property int $pageviews
 * @property int $events
 * @property bool $is_bounce
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class HeuristicsSession extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'site_key',
        'session_id',
        'actor',
        'referrer',
        'referrer_host',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'landing_path',
        'exit_path',
        'device',
        'os',
        'browser',
        'lang',
        'tz',
        'screen_w',
        'screen_h',
        'country',
        'started_at',
        'last_event_at',
        'duration_ms',
        'pageviews',
        'events',
        'is_bounce',
    ];

    public function getTable(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'sessions';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'screen_w' => 'integer',
            'screen_h' => 'integer',
            'duration_ms' => 'integer',
            'pageviews' => 'integer',
            'events' => 'integer',
            'is_bounce' => 'boolean',
            'started_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /**
     * Scope to a site_key.
     *
     * @param  Builder<HeuristicsSession>  $query
     * @return Builder<HeuristicsSession>
     */
    public function scopeForSite($query, string $siteKey)
    {
        return $query->where('site_key', $siteKey);
    }
}
