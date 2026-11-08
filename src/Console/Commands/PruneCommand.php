<?php

declare(strict_types=1);

namespace FancyHeuristics\Console\Commands;

use FancyHeuristics\HeuristicsManager;
use Illuminate\Console\Command;

/**
 * Enforce the retention windows in config('heuristics.retention*').
 *
 *   php artisan heuristics:prune            # delete
 *   php artisan heuristics:prune --dry-run  # count only
 */
class PruneCommand extends Command
{
    protected $signature = 'heuristics:prune {--dry-run : Count what would be deleted, delete nothing}';

    protected $description = 'Delete Fancy Heuristics data past its retention window.';

    public function handle(HeuristicsManager $heuristics): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = $heuristics->prune($dryRun);

        $this->table(
            ['class', $dryRun ? 'would delete' : 'deleted'],
            collect($counts)->map(fn (int $n, string $class) => [$class, (string) $n])->values()->all(),
        );

        $this->info(sprintf(
            '%s %d row(s).',
            $dryRun ? 'Dry run: would delete' : 'Deleted',
            array_sum($counts),
        ));

        return self::SUCCESS;
    }
}
