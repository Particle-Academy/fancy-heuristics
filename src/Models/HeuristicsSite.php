<?php

declare(strict_types=1);

namespace FancyHeuristics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A site registered for pixel verification.
 *
 * @property int $id
 * @property string $site_key
 * @property string $url
 * @property bool $visible
 * @property string|null $pixel_status
 * @property Carbon|null $last_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class HeuristicsSite extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'site_key',
        'url',
        'visible',
        'pixel_status',
        'last_verified_at',
    ];

    public function getTable(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'sites';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }
}
