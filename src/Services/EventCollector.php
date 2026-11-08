<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Models\HeuristicsEvent;
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

        return DB::transaction(function () use ($payload, $siteKey, $sessionId, $context, $ua) {
            $saved = new Collection;
            $errors = [];

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

                $saved->push(HeuristicsEvent::create($normalised));
            }

            $this->sessions->rollUp($siteKey, $sessionId, $saved, $context, $ua);

            return new IngestResult($saved, $errors);
        });
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
        $kind = $event['kind'] ?? null;
        $siteKey = $event['siteKey'] ?? $event['site_key'] ?? null;

        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            return [null, 'invalid_kind'];
        }

        $path = $this->normalisePath($event['path'] ?? null);
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

        $meta = $this->boundedScalarMap($event['meta'] ?? null);
        if ($meta === false) {
            return [null, 'invalid_meta'];
        }

        $actor = $event['actor'] ?? 'human';
        if (! in_array($actor, ['human', 'agent'], true)) {
            $actor = 'human';
        }

        $sessionId = $event['sessionId'] ?? $event['session_id'] ?? null;
        $targetId = $event['targetId'] ?? $event['target_id'] ?? null;

        return [[
            'site_key' => $siteKey,
            'session_id' => is_scalar($sessionId) ? mb_substr((string) $sessionId, 0, $this->limit('max_key_length', 128)) : null,
            'actor' => $actor,
            'kind' => $kind,
            'path' => $path,
            ...$numbers,
            'target_id' => is_scalar($targetId) && (string) $targetId !== ''
                ? mb_substr((string) $targetId, 0, $this->limit('max_target_id_length', 128))
                : null,
            'label' => $this->boundedText($event['label'] ?? null, $this->limit('max_label_length', 120)),
            'meta' => $meta,
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
