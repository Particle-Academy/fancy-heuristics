<?php

declare(strict_types=1);

namespace FancyHeuristics\Console\Commands;

use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Support\EventNames;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Give rows stored before wire v2 their `<domain>.<verb>` name, using the
 * same mapping ingest applies to v1 producers (EventNames::fromV1):
 *
 *   pageview => ui.view, click => ui.activate, dwell => engage.time,
 *   scroll => engage.scroll, pointer => pointer.sample,
 *   agent click via the relay => agent.tool, other agent click => agent.act
 *
 * Agent clicks are re-kinded "agent" so they stop counting as clicks. A v1
 * label is copied to props.legacy_label and never to target_name. Rows keep
 * schema_version 1, so reports can mark them as legacy.
 *
 * Only rows with no name are touched, so a second run changes nothing.
 * Take a read-only count of rows per kind on the real database first and size
 * --chunk from it; see the plan's section 2.5.
 */
class BackfillTaxonomyCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'heuristics:backfill-taxonomy
        {--dry-run : Count what would change, write nothing}
        {--site= : Only this site key}
        {--chunk=1000 : Rows per transaction}
        {--force : Run in production without asking}';

    protected $description = 'Map Fancy Heuristics events stored before wire v2 onto the event-name taxonomy.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));

        $query = HeuristicsEvent::query()->whereNull('name');
        if (is_string($site = $this->option('site')) && $site !== '') {
            $query->where('site_key', $site);
        }

        $changed = 0;
        $byMapping = [];
        $agentReclassified = 0;
        $unreliableLabels = 0;

        $query->chunkById($chunk, function ($rows) use ($dryRun, &$changed, &$byMapping, &$agentReclassified, &$unreliableLabels) {
            $apply = function () use ($rows, $dryRun, &$changed, &$byMapping, &$agentReclassified, &$unreliableLabels) {
                foreach ($rows as $row) {
                    /** @var HeuristicsEvent $row */
                    $meta = is_array($row->meta) ? $row->meta : null;
                    [$name, $kind, $props] = EventNames::fromV1((string) $row->kind, (string) $row->actor, $meta, $row->label);

                    $key = $row->kind.' => '.$name;
                    $byMapping[$key] = ($byMapping[$key] ?? 0) + 1;

                    if ($kind !== $row->kind) {
                        $agentReclassified++;
                    }

                    // A label that is long, or attached to a click with no
                    // target, is almost certainly a container's text.
                    if ($row->label !== null && $row->kind === 'click'
                        && (mb_strlen($row->label) > 60 || $row->target_id === null || $row->target_id === '')) {
                        $unreliableLabels++;
                    }

                    $changed++;

                    if ($dryRun) {
                        continue;
                    }

                    $existing = is_array($row->props) ? $row->props : [];
                    $row->forceFill([
                        'name' => $name,
                        'kind' => $kind,
                        'props' => $props === null && $existing === [] ? null : [...$existing, ...($props ?? [])],
                        'route_pattern' => $row->route_pattern ?? EventNames::patternFromPath((string) $row->path),
                    ])->save();
                }
            };

            $dryRun ? $apply() : DB::transaction($apply);
        });

        ksort($byMapping);
        $this->table(['mapping', 'rows'], collect($byMapping)->map(fn ($n, $k) => [$k, $n])->values()->all());

        $this->line(sprintf('%s %d row(s).', $dryRun ? 'Dry run: would update' : 'Backfill: updated', $changed));
        $this->line('agent rows no longer counted as clicks: '.$agentReclassified);
        $this->line('unreliable legacy labels: '.$unreliableLabels.' (kept as props.legacy_label, marked legacy in reports)');

        return self::SUCCESS;
    }
}
