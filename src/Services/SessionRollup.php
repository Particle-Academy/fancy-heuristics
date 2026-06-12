<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Upserts the per-session rollup row off each collect batch.
 *
 * First sight of a (site_key, session_id) stamps the immutable acquisition +
 * audience context (referrer, utm, device/os/browser, lang, tz, screen). Every
 * batch then rolls up the engagement totals (events, pageviews, landing/exit
 * path, duration, bounce) and the latest actor. The session row is derived,
 * not authoritative: dropping it loses no raw events, it can be rebuilt.
 */
class SessionRollup
{
    public function __construct(
        protected UserAgentClassifier $ua = new UserAgentClassifier,
    ) {}

    /**
     * Roll the just-persisted batch of events into its session row.
     *
     * @param  Collection<int, HeuristicsEvent>  $events  The events saved this batch.
     * @param  array<string, mixed>|null  $context  Wire SessionContext (first batch only): { referrer, utm{...}, lang, tz, screenW, screenH, dpr }.
     */
    public function rollUp(
        string $siteKey,
        ?string $sessionId,
        Collection $events,
        ?array $context = null,
        ?string $userAgent = null,
    ): ?HeuristicsSession {
        if ($sessionId === null || $sessionId === '' || $events->isEmpty()) {
            return null;
        }

        /** @var HeuristicsSession $session */
        $session = HeuristicsSession::query()->firstOrNew([
            'site_key' => $siteKey,
            'session_id' => $sessionId,
        ]);

        $isNew = ! $session->exists;

        // Timestamps span of this batch.
        $occurredAt = $events
            ->map(fn ($e) => $e->occurred_at)
            ->filter()
            ->sort()
            ->values();

        $batchFirst = $occurredAt->first() ?? Carbon::now();
        $batchLast = $occurredAt->last() ?? $batchFirst;

        // First-sight, immutable acquisition + audience context.
        if ($isNew) {
            $session->started_at = $batchFirst;
            $session->landing_path = $events->first()?->path;

            $this->applyContext($session, $context ?? []);
            $this->applyUserAgent($session, $userAgent);
        }

        // Actor: latest non-empty wins (an agent taking over flips the session).
        $latestActor = $events->last()?->actor;
        if ($latestActor !== null && $latestActor !== '') {
            $session->actor = $latestActor;
        } elseif ($isNew) {
            $session->actor = 'human';
        }

        // Engagement rollups.
        $pageviewsThisBatch = $events->where('kind', 'pageview')->count();

        $session->events = (int) $session->events + $events->count();
        $session->pageviews = (int) $session->pageviews + $pageviewsThisBatch;

        // last_event_at only ever moves forward.
        $existingLast = $session->last_event_at;
        $session->last_event_at = ($existingLast && $existingLast->greaterThan($batchLast))
            ? $existingLast
            : $batchLast;

        // exit_path = path of the latest event this batch (if any carried one).
        $latestPath = $events->last()?->path;
        if ($latestPath !== null && $latestPath !== '') {
            $session->exit_path = $latestPath;
        }

        // Duration = last_event_at − started_at (never negative).
        if ($session->started_at && $session->last_event_at) {
            $session->duration_ms = (int) max(
                0,
                $session->started_at->diffInMilliseconds($session->last_event_at),
            );
        }

        // Bounce: a single pageview and shallow engagement (≤1 interaction beyond it).
        $session->is_bounce = $session->pageviews <= 1
            && ($session->events - $session->pageviews) <= 1;

        $session->save();

        return $session;
    }

    /**
     * Apply the once-per-session wire context.
     *
     * @param  array<string, mixed>  $context
     */
    protected function applyContext(HeuristicsSession $session, array $context): void
    {
        $referrer = isset($context['referrer']) && is_string($context['referrer']) && $context['referrer'] !== ''
            ? $context['referrer']
            : null;

        if ($referrer !== null) {
            $session->referrer = $referrer;
            $session->referrer_host = $this->hostFromUrl($referrer);
        }

        $utm = isset($context['utm']) && is_array($context['utm']) ? $context['utm'] : [];
        $session->utm_source = $this->stringOrNull($utm['source'] ?? null);
        $session->utm_medium = $this->stringOrNull($utm['medium'] ?? null);
        $session->utm_campaign = $this->stringOrNull($utm['campaign'] ?? null);
        $session->utm_term = $this->stringOrNull($utm['term'] ?? null);
        $session->utm_content = $this->stringOrNull($utm['content'] ?? null);

        $session->lang = $this->stringOrNull($context['lang'] ?? null);
        $session->tz = $this->stringOrNull($context['tz'] ?? null);
        $session->screen_w = $this->intOrNull($context['screenW'] ?? null);
        $session->screen_h = $this->intOrNull($context['screenH'] ?? null);
    }

    protected function applyUserAgent(HeuristicsSession $session, ?string $userAgent): void
    {
        $parsed = $this->ua->classify($userAgent);
        $session->device = $parsed['device'];
        $session->os = $parsed['os'];
        $session->browser = $parsed['browser'];
    }

    /**
     * Parse the host out of a referrer URL. Returns null when the URL has no
     * host (e.g. a bare path, or an unparseable string).
     */
    protected function hostFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        // Drop a leading www. so referral hosts group cleanly.
        return preg_replace('/^www\./i', '', $host);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
