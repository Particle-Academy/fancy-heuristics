<?php

declare(strict_types=1);

namespace FancyHeuristics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pixel liveness / visibility beacon.
 *
 * @property int $id
 * @property string $site_key
 * @property string $style badge|mark|beacon
 * @property string $mode placed|floating
 * @property bool $visible
 * @property string $path
 * @property string|null $ua
 * @property string|null $ip_hash
 * @property Carbon $pinged_at
 */
class HeuristicsPixelPing extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'site_key',
        'style',
        'mode',
        'visible',
        'path',
        'ua',
        'ip_hash',
        'pinged_at',
    ];

    public function getTable(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'pixel_pings';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'pinged_at' => 'datetime',
        ];
    }
}
