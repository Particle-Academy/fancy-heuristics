<?php

declare(strict_types=1);

namespace FancyHeuristics\Support;

use FancyHeuristics\Models\HeuristicsEvent;
use Illuminate\Support\Collection;

/**
 * The outcome of one collect batch: the stored events, and every rejected
 * event by its index in the submitted array with a short code. Never carries
 * a submitted value, so it is safe to return to an anonymous caller.
 */
final class IngestResult
{
    /**
     * @param  Collection<int, HeuristicsEvent>  $events
     * @param  list<array{index: int, code: string}>  $errors
     */
    public function __construct(
        public readonly Collection $events,
        public readonly array $errors = [],
        public readonly bool $replayed = false,
        public readonly ?int $acceptedCount = null,
    ) {}

    public function accepted(): int
    {
        return $this->acceptedCount ?? $this->events->count();
    }

    public function rejected(): int
    {
        return count($this->errors);
    }

    /**
     * @return array{ok: bool, replayed: bool, accepted: int, rejected: int, errors: list<array{index: int, code: string}>}
     */
    public function toResponse(): array
    {
        return [
            'ok' => true,
            'replayed' => $this->replayed,
            'accepted' => $this->accepted(),
            'rejected' => $this->rejected(),
            'errors' => $this->errors,
        ];
    }
}
