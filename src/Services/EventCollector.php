<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Support\EventNames;
use FancyHeuristics\Support\IngestResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use function mb_substr;

/**
 * Validates and persists batches of interaction events (POST {prefix}/collect):
 *
 *   { siteKey, sessionId, events: [ Event, ... ], context? }
 *
 * Each Event is normalised from the camelCase wire shape into snake_case
 * columns. The envelope is validated as a whole (422 when it is wrong); each
 * event is then validated on its own, and an invalid one is rejected with a
 * code while the rest of the batch is kept. The events and the session rollup
 * are written in one transaction, so a failed rollup never leaves events that
 * it did not count.
 */
class EventCollector
{
    /**
     * The valid v1 event kinds.
     *
     * @var list<string>
     */
    public const KINDS = ['pageview', 'click', 'scroll', 'pointer', 'dwell'];

    /**
     * Whether event timestamps must fall inside the configured skew. On for
     * the public HTTP endpoint; off for trusted server code calling the facade,
     * which may legitimately record or import past events.
     */
    protected bool $enforceSkew = false;

    /** The batch's declared wire version (1 when absent). */
    protected int $batchSchema = 1;

    public function __construct(
        protected SessionRollup $sessions = new SessionRollup,
    ) {}

    /**
     * Validate + persist a single event (already normalised or wire-shaped).
     * Returns null when the event is rejected.
     */
    public function record(array $event): ?HeuristicsEvent
    {
        $this->enforceSkew = false;
        $this->batchSchema = 1;
        [$normalised] = $this->normalise($event);

        if ($normalised === null) {
            return null;
        }

        return HeuristicsEvent::create($normalised);
    }

    /**
     * Validate + persist a full collect batch and roll it into its session.
     *
     * @param  array<string, mixed>  $payload  { siteKey, sessionId, events: [...], context?: {...} }
     * @return Collection<int, HeuristicsEvent>
     */
    public function collect(array $payload, ?string $userAgent = null): Collection
    {
        return $this->ingest($payload, $userAgent)->events;
    }

    /**
     * Like collect(), but also reports which events were rejected and why.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingest(array $payload, ?string $userAgent = null, bool $public = false): IngestResult
    {
        $this->validateEnvelope($payload);
        $this->enforceSkew = $public;

        $siteKey = (string) $payload['siteKey'];
        $sessionId = isset($payload['sessionId']) ? (string) $payload['sessionId'] : null;
        $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : null;
        $ua = $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null;
        $batchId = isset($payload['batchId']) ? (string) $payload['batchId'] : null;
        $this->batchSchema = (int) ($payload['schemaVersion'] ?? 1);

        return DB::transaction(function () use ($payload, $siteKey, $sessionId, $context, $ua, $batchId) {
            // Claim the batch first. The unique (site_key, batch_id) key makes a
            // concurrent or later replay a no-op that returns the first result.
            if ($batchId !== null) {
                $claimed = DB::table($this->receiptsTable())->insertOrIgnore([
                    'site_key' => $siteKey,
                    'batch_id' => $batchId,
                    'schema_version' => $this->batchSchema,
                    'received_at' => Carbon::now(),
                ]);

                if ($claimed === 0) {
                    return $this->replayed($siteKey, $batchId);
                }
            }

            $saved = new Collection;
            $errors = [];

            // Event ids already stored for this site: a replayed event is
            // harmless even when it arrives in a different batch.
            $seen = $this->storedEventIds($siteKey, $payload['events']);

            foreach (array_values($payload['events']) as $index => $event) {
                if (! is_array($event)) {
                    $errors[] = ['index' => $index, 'code' => 'invalid_event'];

                    continue;
                }

                // Batch-level siteKey/sessionId flow down to each event unless the
                // event carries its own. The request UA is stamped on every event.
                $event['siteKey'] = $event['siteKey'] ?? $siteKey;
                $event['sessionId'] = $event['sessionId'] ?? $sessionId;
                $event['ua'] = $event['ua'] ?? $ua;

                [$normalised, $code] = $this->normalise($event);

                if ($normalised === null) {
                    $errors[] = ['index' => $index, 'code' => $code ?? 'invalid_event'];

                    continue;
                }

                $eventId = $normalised['event_id'] ?? null;
                if ($eventId !== null) {
                    if (isset($seen[$eventId])) {
                        $errors[] = ['index' => $index, 'code' => 'duplicate'];

                        continue;
                    }
                    $seen[$eventId] = true;
                }

                $saved->push(HeuristicsEvent::create($normalised));
            }

            $this->sessions->rollUp($siteKey, $sessionId, $saved, $context, $ua);

            if ($batchId !== null) {
                DB::table($this->receiptsTable())
                    ->where('site_key', $siteKey)
                    ->where('batch_id', $batchId)
                    ->update([
                        'accepted' => $saved->count(),
                        'rejected' => count($errors),
                        'errors' => json_encode($errors),
                    ]);
            }

            return new IngestResult($saved, $errors);
        });
    }

    protected function receiptsTable(): string
    {
        return config('heuristics.table_prefix', 'heuristics_').'ingest_receipts';
    }

    /**
     * The stored result of a batch seen before.
     */
    protected function replayed(string $siteKey, string $batchId): IngestResult
    {
        $receipt = DB::table($this->receiptsTable())
            ->where('site_key', $siteKey)
            ->where('batch_id', $batchId)
            ->first();

        $errors = json_decode((string) ($receipt->errors ?? '[]'), true);

        return new IngestResult(
            new Collection,
            is_array($errors) ? array_values($errors) : [],
            replayed: true,
            acceptedCount: (int) ($receipt->accepted ?? 0),
        );
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array<string, true>
     */
    protected function storedEventIds(string $siteKey, array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            if (is_array($event) && isset($event['eventId']) && is_string($event['eventId']) && $event['eventId'] !== '') {
                $ids[] = mb_substr($event['eventId'], 0, $this->limit('max_key_length', 128));
            }
        }

        if ($ids === []) {
            return [];
        }

        return HeuristicsEvent::query()
            ->where('site_key', $siteKey)
            ->whereIn('event_id', array_values(array_unique($ids)))
            ->pluck('event_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validateEnvelope(array $payload): void
    {
        $maxKey = $this->limit('max_key_length', 128);

        Validator::make($payload, [
            'siteKey' => ['required', 'string', 'max:'.$maxKey],
            'sessionId' => ['nullable', 'string', 'max:'.$maxKey],
            'schemaVersion' => ['nullable', 'integer', 'in:1,2'],
            'batchId' => ['nullable', 'string', 'max:'.$maxKey],
            'events' => ['required', 'array', 'max:'.$this->limit('max_events_per_batch', 500)],
            'context' => ['nullable', 'array'],
        ])->validate();
    }

    /**
     * Normalise a wire-shaped Event into a snake_case attribute array.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: array<string, mixed>|null, 1: string|null} [attributes, null] or [null, rejection code]
     */
    protected function normalise(array $event): array
    {
        $isV2 = array_key_exists('name', $event) || $this->batchSchema === 2;

        return $isV2 ? $this->normaliseV2($event) : $this->normaliseV1($event);
    }

    /**
     * A v1 event: validated as before, then mapped to its v2 name. The v1
     * label is bounded and also kept as props.legacy_label; it never becomes
     * a target name.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    protected function normaliseV1(array $event): array
    {
        $kind = $event['kind'] ?? null;

        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            return [null, 'invalid_kind'];
        }

        [$common, $code] = $this->common($event, $event['path'] ?? null);
        if ($common === null) {
            return [null, $code];
        }

        $meta = $this->boundedScalarMap($event['meta'] ?? null);
        if ($meta === false) {
            return [null, 'invalid_meta'];
        }

        $targetId = $event['targetId'] ?? $event['target_id'] ?? null;
        $label = $this->boundedText($event['label'] ?? null, $this->limit('max_label_length', 120));

        [$name, $storedKind, $props] = EventNames::fromV1($kind, $common['actor'], $meta, $label);

        return [[
            ...$common,
            'schema_version' => 1,
            'name' => $name,
            'kind' => $storedKind,
            'route_pattern' => EventNames::patternFromPath($common['path']),
            'target_id' => is_scalar($targetId) && (string) $targetId !== ''
                ? mb_substr((string) $targetId, 0, $this->limit('max_target_id_length', 128))
                : null,
            'label' => $label,
            'meta' => $meta,
            'props' => $props,
        ], null];
    }

    /**
     * A wire-v2 event: a registered `<domain>.<verb>` name, the route and
     * title as separate fields, a structured target and bounded props.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    protected function normaliseV2(array $event): array
    {
        $name = $event['name'] ?? null;
        if (! EventNames::isWellFormed($name)) {
            return [null, 'invalid_name'];
        }
        if (! EventNames::isRegistered($name)) {
            return [null, 'unknown_name'];
        }

        $route = is_array($event['route'] ?? null) ? $event['route'] : [];

        [$common, $code] = $this->common($event, $event['path'] ?? $route['path'] ?? null);
        if ($common === null) {
            return [null, $code];
        }

        $props = $this->boundedScalarMap($event['props'] ?? null);
        if ($props === false) {
            return [null, 'invalid_props'];
        }

        $target = $this->target($event['target'] ?? null);
        if ($target === false) {
            return [null, 'invalid_target'];
        }

        $routeName = $this->boundedHandle($route['name'] ?? null, 128);
        $routePattern = $this->boundedHandle($route['pattern'] ?? null, 255);

        $eventId = $event['eventId'] ?? null;

        return [[
            ...$common,
            ...$target,
            'schema_version' => 2,
            'event_id' => is_string($eventId) && $eventId !== '' ? mb_substr($eventId, 0, $this->limit('max_key_length', 128)) : null,
            'name' => $name,
            'kind' => EventNames::kindFor($name),
            'route_name' => $routeName,
            'route_pattern' => $routePattern ?? EventNames::patternFromPath($common['path']),
            // The title is page-level: kept on views only, never on a click.
            'title' => $name === 'ui.view' ? $this->boundedText($event['title'] ?? null, 120) : null,
            'props' => $props,
        ], null];
    }

    /**
     * The structured target. False when present but malformed.
     *
     * @return array{component: ?string, part: ?string, instance: ?string, role: ?string, target_name: ?string}|false
     */
    protected function target(mixed $target): array|false
    {
        $empty = ['component' => null, 'part' => null, 'instance' => null, 'role' => null, 'target_name' => null];

        if ($target === null) {
            return $empty;
        }

        if (! is_array($target)) {
            return false;
        }

        $component = $target['component'] ?? 'none';
        $part = $target['part'] ?? null;
        $role = $target['role'] ?? null;

        foreach ([$component, $part, $role] as $handle) {
            if ($handle !== null && ! $this->isSlug($handle)) {
                return false;
            }
        }

        $instance = $target['instance'] ?? null;
        if ($instance !== null && ! is_scalar($instance)) {
            return false;
        }

        return [
            'component' => $component,
            'part' => $part,
            'instance' => $instance !== null && (string) $instance !== '' ? mb_substr((string) $instance, 0, 128) : null,
            'role' => $role !== null ? mb_substr($role, 0, 32) : null,
            'target_name' => $this->boundedText($target['name'] ?? null, 60),
        ];
    }

    protected function isSlug(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 64
            && preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) === 1;
    }

    protected function boundedHandle(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    /**
     * Fields every event shares, v1 or v2: site, session, actor, path,
     * timestamp, numbers, UA.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    protected function common(array $event, mixed $rawPath): array
    {
        $siteKey = $event['siteKey'] ?? $event['site_key'] ?? null;

        $path = $this->normalisePath($rawPath);
        if ($path === null) {
            return [null, 'invalid_path'];
        }

        if (! is_string($siteKey) || $siteKey === '' || mb_strlen($siteKey) > $this->limit('max_key_length', 128)) {
            return [null, 'invalid_site'];
        }

        $occurredAt = $this->resolveTimestamp($event['ts'] ?? $event['occurred_at'] ?? null);
        if ($occurredAt === null) {
            return [null, 'invalid_ts'];
        }

        $numbers = $this->numbers($event);
        if ($numbers === null) {
            return [null, 'invalid_number'];
        }

        // Public ingestion accepts human and agent; anything else is human.
        $actor = $event['actor'] ?? 'human';
        if (! in_array($actor, ['human', 'agent'], true)) {
            $actor = 'human';
        }

        $sessionId = $event['sessionId'] ?? $event['session_id'] ?? null;

        return [[
            'site_key' => $siteKey,
            'session_id' => is_scalar($sessionId) ? mb_substr((string) $sessionId, 0, $this->limit('max_key_length', 128)) : null,
            'actor' => $actor,
            'path' => $path,
            ...$numbers,
            'ua' => isset($event['ua']) && is_string($event['ua']) && $event['ua'] !== '' ? mb_substr($event['ua'], 0, 255) : null,
            'occurred_at' => $occurredAt,
        ], null];
    }

    /**
     * Pathname only: the query string and fragment are removed (they carry
     * tokens and per-visit state). Null when empty or over the bound.
     */
    protected function normalisePath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $cut = strcspn($path, '?#');
        $path = substr($path, 0, $cut);

        if ($path === '' || mb_strlen($path) > $this->limit('max_path_length', 255)) {
            return null;
        }

        return $path;
    }

    /**
     * Coordinates, scroll percentage and dwell, range-checked. Null when any
     * present value is impossible.
     *
     * @param  array<string, mixed>  $event
     * @return array{x: ?int, y: ?int, vw: ?int, vh: ?int, scroll_pct: ?float, dwell_ms: ?int}|null
     */
    protected function numbers(array $event): ?array
    {
        $maxCoord = $this->limit('max_coordinate', 100_000);

        $out = [
            'x' => $this->intOrNull($event['x'] ?? null),
            'y' => $this->intOrNull($event['y'] ?? null),
            'vw' => $this->intOrNull($event['vw'] ?? null),
            'vh' => $this->intOrNull($event['vh'] ?? null),
            'scroll_pct' => $this->floatOrNull($event['scrollPct'] ?? $event['scroll_pct'] ?? null),
            'dwell_ms' => $this->intOrNull($event['dwellMs'] ?? $event['dwell_ms'] ?? null),
        ];

        foreach (['x', 'y'] as $axis) {
            if ($out[$axis] !== null && abs($out[$axis]) > $maxCoord) {
                return null;
            }
        }

        foreach (['vw', 'vh'] as $dim) {
            if ($out[$dim] !== null && ($out[$dim] < 0 || $out[$dim] > $maxCoord)) {
                return null;
            }
        }

        if ($out['scroll_pct'] !== null && ($out['scroll_pct'] < 0 || $out['scroll_pct'] > 100)) {
            return null;
        }

        if ($out['dwell_ms'] !== null && ($out['dwell_ms'] < 0 || $out['dwell_ms'] > $this->limit('max_dwell_ms', 3_600_000))) {
            return null;
        }

        return $out;
    }

    /**
     * A flat map of scalars under the key and byte caps. Null when absent,
     * false when present but not acceptable.
     *
     * @return array<string, scalar|null>|null|false
     */
    protected function boundedScalarMap(mixed $map): array|null|false
    {
        if ($map === null) {
            return null;
        }

        if (! is_array($map) || array_is_list($map) && $map !== []) {
            return false;
        }

        if (count($map) > $this->limit('max_meta_keys', 16)) {
            return false;
        }

        foreach ($map as $key => $value) {
            if (! is_string($key) || mb_strlen($key) > 40) {
                return false;
            }

            if ($value !== null && ! is_scalar($value)) {
                return false;
            }
        }

        $encoded = json_encode($map);
        if ($encoded === false || strlen($encoded) > $this->limit('max_meta_bytes', 2048)) {
            return false;
        }

        return $map;
    }

    /**
     * Collapse runs of whitespace to one space, trim, then cut to $max
     * characters. Null when nothing is left.
     */
    protected function boundedText(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected function limit(string $key, int $default): int
    {
        return (int) config('heuristics.limits.'.$key, $default);
    }

    /**
     * Resolve a wire `ts` (ms epoch, or a date string) into a Carbon instance.
     * A missing ts means now. Null when the time is outside the allowed skew.
     */
    protected function resolveTimestamp(mixed $ts): ?Carbon
    {
        $now = Carbon::now();
        $at = null;

        if (is_numeric($ts)) {
            $at = Carbon::createFromTimestampMs((int) $ts);
        } elseif (is_string($ts) && $ts !== '') {
            try {
                $at = Carbon::parse($ts);
            } catch (\Throwable) {
                return null;
            }
        }

        if ($at === null || ! $this->enforceSkew) {
            return $at ?? $now;
        }

        if ($at->greaterThan($now->copy()->addSeconds($this->limit('max_future_skew_seconds', 86_400)))) {
            return null;
        }

        if ($at->lessThan($now->copy()->subSeconds($this->limit('max_past_skew_seconds', 30 * 86_400)))) {
            return null;
        }

        return $at;
    }
}
