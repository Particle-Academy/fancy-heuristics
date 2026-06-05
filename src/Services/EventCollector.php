<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Models\HeuristicsEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Validates and persists batches of interaction events per the frozen wire
 * contract (POST {prefix}/collect):
 *
 *   { siteKey, sessionId, events: [ Event, ... ] }
 *
 * Each Event is normalised from the camelCase wire shape into snake_case
 * columns. Invalid events are skipped rather than failing the whole batch
 * so one malformed sample never drops a good beacon.
 */
class EventCollector
{
    /**
     * The valid event kinds per the contract.
     *
     * @var list<string>
     */
    public const KINDS = ['pageview', 'click', 'scroll', 'pointer', 'dwell'];

    /**
     * Validate + persist a single event (already normalised or wire-shaped).
     */
    public function record(array $event): ?HeuristicsEvent
    {
        $normalised = $this->normalise($event);

        if ($normalised === null) {
            return null;
        }

        return HeuristicsEvent::create($normalised);
    }

    /**
     * Validate + persist a full collect batch.
     *
     * @param  array<string, mixed>  $payload  { siteKey, sessionId, events: [...] }
     * @return Collection<int, HeuristicsEvent>
     */
    public function collect(array $payload): Collection
    {
        $validator = Validator::make($payload, [
            'siteKey' => ['required', 'string'],
            'sessionId' => ['nullable', 'string'],
            'events' => ['required', 'array'],
        ]);

        $validator->validate();

        $siteKey = (string) $payload['siteKey'];
        $sessionId = isset($payload['sessionId']) ? (string) $payload['sessionId'] : null;

        $saved = new Collection;

        foreach ($payload['events'] as $event) {
            if (! is_array($event)) {
                continue;
            }

            // Batch-level siteKey/sessionId flow down to each event unless the
            // event carries its own.
            $event['siteKey'] = $event['siteKey'] ?? $siteKey;
            $event['sessionId'] = $event['sessionId'] ?? $sessionId;

            $model = $this->record($event);

            if ($model !== null) {
                $saved->push($model);
            }
        }

        return $saved;
    }

    /**
     * Normalise a wire-shaped Event into a snake_case attribute array.
     * Returns null when the event is structurally invalid.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    protected function normalise(array $event): ?array
    {
        $kind = $event['kind'] ?? null;
        $path = $event['path'] ?? null;
        $siteKey = $event['siteKey'] ?? $event['site_key'] ?? null;

        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            return null;
        }

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (! is_string($siteKey) || $siteKey === '') {
            return null;
        }

        $actor = $event['actor'] ?? 'human';
        if (! in_array($actor, ['human', 'agent'], true)) {
            $actor = 'human';
        }

        return [
            'site_key' => $siteKey,
            'session_id' => isset($event['sessionId']) ? (string) $event['sessionId'] : ($event['session_id'] ?? null),
            'actor' => $actor,
            'kind' => $kind,
            'path' => $path,
            'x' => $this->intOrNull($event['x'] ?? null),
            'y' => $this->intOrNull($event['y'] ?? null),
            'vw' => $this->intOrNull($event['vw'] ?? null),
            'vh' => $this->intOrNull($event['vh'] ?? null),
            'scroll_pct' => $this->floatOrNull($event['scrollPct'] ?? $event['scroll_pct'] ?? null),
            'dwell_ms' => $this->intOrNull($event['dwellMs'] ?? $event['dwell_ms'] ?? null),
            'target_id' => isset($event['targetId']) ? (string) $event['targetId'] : ($event['target_id'] ?? null),
            'label' => isset($event['label']) ? (string) $event['label'] : null,
            'meta' => isset($event['meta']) && is_array($event['meta']) ? $event['meta'] : null,
            'occurred_at' => $this->resolveTimestamp($event['ts'] ?? $event['occurred_at'] ?? null),
        ];
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Resolve a wire `ts` (ms epoch) into a Carbon instance. Falls back to now().
     */
    protected function resolveTimestamp(mixed $ts): Carbon
    {
        if (is_numeric($ts)) {
            // Wire `ts` is milliseconds since epoch.
            return Carbon::createFromTimestampMs((int) $ts);
        }

        if (is_string($ts) && $ts !== '') {
            try {
                return Carbon::parse($ts);
            } catch (\Throwable) {
                // fall through
            }
        }

        return Carbon::now();
    }
}
