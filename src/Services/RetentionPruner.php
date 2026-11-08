<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsPixelPing;
use FancyHeuristics\Models\HeuristicsSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes data past its retention window, per data class:
 *
 *   events   — every raw event older than `retention_days`
 *   pointer  — pointer samples older than `retention.pointer_days` (shorter:
 *              they are the bulk of the rows and the least useful once old)
 *   pings    — pixel pings older than `retention.pings_days`
 *   sessions — session rollups whose last activity is older than
 *              `retention.sessions_days`
 *   receipts — ingest receipts older than `retention.receipts_days` (they only
 *              need to outlive a client's retries)
 *
 * Deletes in id-ordered pages so a large prune never holds one long lock.
 */
class RetentionPruner
{
    public const PAGE = 1000;

    /**
     * @return array<string, int> rows removed (or that would be removed) per class
     */
    public function prune(bool $dryRun = false): array
    {
        $now = Carbon::now();
        $days = fn (string $key, int $default) => max(1, (int) config($key, $default));

        $counts = [
            'pointer' => $this->run(
                HeuristicsEvent::query()
                    ->where('kind', 'pointer')
                    ->where('occurred_at', '<', $now->copy()->subDays($days('heuristics.retention.pointer_days', 14))),
                $dryRun,
            ),
            'events' => $this->run(
                HeuristicsEvent::query()
                    ->where('occurred_at', '<', $now->copy()->subDays($days('heuristics.retention_days', 90))),
                $dryRun,
            ),
            'pings' => $this->run(
                HeuristicsPixelPing::query()
                    ->where('pinged_at', '<', $now->copy()->subDays($days('heuristics.retention.pings_days', 90))),
                $dryRun,
            ),
            'sessions' => $this->run(
                HeuristicsSession::query()->where(function (Builder $q) use ($now, $days) {
                    $cutoff = $now->copy()->subDays($days('heuristics.retention.sessions_days', 395));
                    $q->where('last_event_at', '<', $cutoff)
                        ->orWhere(fn (Builder $q) => $q->whereNull('last_event_at')->where('created_at', '<', $cutoff));
                }),
                $dryRun,
            ),
        ];

        $receipts = config('heuristics.table_prefix', 'heuristics_').'ingest_receipts';
        if (Schema::hasTable($receipts)) {
            $counts['receipts'] = $this->runTable(
                $receipts,
                'received_at',
                $now->copy()->subDays($days('heuristics.retention.receipts_days', 7)),
                $dryRun,
            );
        }

        return $counts;
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function run(Builder $query, bool $dryRun): int
    {
        if ($dryRun) {
            return (int) (clone $query)->count();
        }

        $total = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(self::PAGE)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += $query->getModel()->newQuery()->whereKey($ids->all())->delete();
        } while ($ids->count() === self::PAGE);

        return $total;
    }

    protected function runTable(string $table, string $column, Carbon $cutoff, bool $dryRun): int
    {
        $query = fn () => DB::table($table)->where($column, '<', $cutoff);

        if ($dryRun) {
            return (int) $query()->count();
        }

        $total = 0;

        do {
            $ids = $query()->orderBy('id')->limit(self::PAGE)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += DB::table($table)->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === self::PAGE);

        return $total;
    }
}
