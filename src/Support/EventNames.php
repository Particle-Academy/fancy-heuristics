<?php

declare(strict_types=1);

namespace FancyHeuristics\Support;

/**
 * The event-name registry: `<domain>.<verb>`, a closed vocabulary.
 *
 * Core names ship here; a host adds its own through `heuristics.names`. A
 * name that is not registered is rejected at ingest, so a typo or an
 * arbitrary string becomes a rejection count, not a row in every report.
 *
 * Also owns the v1 to v2 mapping, used both at ingest (for producers that
 * still send v1) and by `heuristics:backfill-taxonomy` (for stored rows), so
 * the two can never disagree.
 */
final class EventNames
{
    /** @var list<string> */
    public const CORE = [
        'ui.view', 'ui.activate', 'ui.change', 'ui.submit', 'ui.open', 'ui.close', 'ui.copy',
        'engage.time', 'engage.scroll',
        'pointer.sample',
        'error.js', 'error.promise', 'error.http', 'error.component',
        'perf.vitals',
        'friction.rage', 'friction.dead', 'friction.error-click',
        'feature.used', 'feature.denied', 'feature.limit',
        'agent.act', 'agent.tool',
    ];

    /** v2 name => the v1 `kind` it is stored under, so v1-era reports keep working. */
    private const KINDS = [
        'ui.view' => 'pageview',
        'ui.activate' => 'click',
        'engage.time' => 'dwell',
        'engage.scroll' => 'scroll',
        'pointer.sample' => 'pointer',
    ];

    /** v1 kind => v2 name, for human (and non-agent-click) events. */
    private const FROM_V1 = [
        'pageview' => 'ui.view',
        'click' => 'ui.activate',
        'dwell' => 'engage.time',
        'scroll' => 'engage.scroll',
        'pointer' => 'pointer.sample',
    ];

    public const MAX_LENGTH = 64;

    /** True when the string has the `<domain>.<verb>` shape. */
    public static function isWellFormed(mixed $name): bool
    {
        return is_string($name)
            && $name !== ''
            && strlen($name) <= self::MAX_LENGTH
            && preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/D', $name) === 1;
    }

    public static function isRegistered(string $name): bool
    {
        return in_array($name, self::CORE, true)
            || in_array($name, (array) config('heuristics.names', []), true);
    }

    /**
     * The `kind` column value for a v2 name: the v1 kind where one exists,
     * "agent" for agent names (never "click"), otherwise the domain.
     */
    public static function kindFor(string $name): string
    {
        if (isset(self::KINDS[$name])) {
            return self::KINDS[$name];
        }

        return strstr($name, '.', true) ?: $name;
    }

    /**
     * Map a v1 event to [name, kind, props].
     *
     *   agent click via the relay  => agent.tool, props.tool
     *   other agent click          => agent.act,  props.action
     *   anything else              => the kind's v2 name
     *
     * A v1 label is kept as props.legacy_label. It is never a target name:
     * v1 labels were often a container's text.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array{0: string, 1: string, 2: array<string, scalar>|null}
     */
    public static function fromV1(string $kind, string $actor, ?array $meta, ?string $label): array
    {
        $props = [];
        $name = self::FROM_V1[$kind] ?? $kind;
        $storedKind = $kind;

        if ($actor === 'agent' && $kind === 'click') {
            $action = is_scalar($meta['action'] ?? null) ? mb_substr((string) $meta['action'], 0, 64) : null;
            $storedKind = 'agent';

            if (($meta['via'] ?? null) === 'relay') {
                $name = 'agent.tool';
                if ($action !== null) {
                    $props['tool'] = $action;
                }
            } else {
                $name = 'agent.act';
                if ($action !== null) {
                    $props['action'] = $action;
                }
            }
        }

        if ($label !== null && $label !== '') {
            $props['legacy_label'] = $label;
        }

        return [$name, $storedKind, $props === [] ? null : $props];
    }

    /**
     * A route pattern from a raw path: numeric, UUID and long hex segments
     * become `:id`. Used when the producer sends no host pattern, so
     * `/orders/123` and `/orders/456` group together.
     */
    public static function patternFromPath(string $path): string
    {
        $segments = explode('/', $path);

        foreach ($segments as $i => $segment) {
            if ($segment === '') {
                continue;
            }

            $isId = ctype_digit($segment)
                || (strlen($segment) === 36 && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $segment) === 1)
                || (strlen($segment) >= 16 && ctype_xdigit($segment));

            if ($isId) {
                $segments[$i] = ':id';
            }
        }

        return mb_substr(implode('/', $segments), 0, 255);
    }
}
