<?php

declare(strict_types=1);

namespace FancyHeuristics\Services;

use FancyHeuristics\Models\HeuristicsEvent;
use FancyHeuristics\Models\HeuristicsSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        // Order by when things happened, not by where they sat in the array.
        // sortBy is stable, so events with the same timestamp keep array order.
        $ordered = $events
            ->sortBy(fn (HeuristicsEvent $e) => $e->occurred_at?->getTimestampMs() ?? 0)
            ->values();

        /** @var HeuristicsEvent $first */
        $first = $ordered->first();
        /** @var HeuristicsEvent $last */
        $last = $ordered->last();
        $batchFirst = $first->occurred_at ?? Carbon::now();
        $batchLast = $last->occurred_at ?? $batchFirst;

        return DB::transaction(function () use ($siteKey, $sessionId, $ordered, $first, $last, $batchFirst, $batchLast, $context, $userAgent) {
            $table = (new HeuristicsSession)->getTable();

            // First sight: insert the row with its immutable acquisition and
            // audience context. If a concurrent batch inserted it first, the
            // unique (site_key, session_id) key makes this a no-op.
            $fresh = new HeuristicsSession([
                'site_key' => $siteKey,
                'session_id' => $sessionId,
                'actor' => $first->actor ?: 'human',
                'started_at' => $batchFirst,
                'last_event_at' => $batchFirst,
                'landing_path' => $first->path,
                'exit_path' => $first->path,
                'duration_ms' => 0,
                'pageviews' => 0,
                'events' => 0,
                'is_bounce' => true,
            ]);
            $this->applyContext($fresh, $context ?? []);
            $this->applyUserAgent($fresh, $userAgent);
            $fresh->created_at = $fresh->updated_at = Carbon::now();

            DB::table($table)->insertOrIgnore($fresh->getAttributes());

            // Lock the row for the rest of the transaction, so two batches for
            // one session cannot interleave their read and write.
            /** @var HeuristicsSession $session */
            $session = HeuristicsSession::query()
                ->where('site_key', $siteKey)
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            $startedAt = $session->started_at;
            $lastAt = $session->last_event_at;
            $extra = ['updated_at' => Carbon::now()];

            // A batch that starts earlier than anything seen moves the start
            // and the landing page back (a late beacon from the first page).
            if ($startedAt === null || $batchFirst->lessThan($startedAt)) {
                $startedAt = $batchFirst;
                $extra['started_at'] = $batchFirst;
                $extra['landing_path'] = $first->path;
            }

            // Only a batch that reaches further forward moves the exit page,
            // the last-event time and the actor.
            if ($lastAt === null || $batchLast->greaterThanOrEqualTo($lastAt)) {
                $lastAt = $batchLast;
                $extra['last_event_at'] = $batchLast;
                $extra['exit_path'] = $last->path;
                if ($last->actor !== null && $last->actor !== '') {
                    $extra['actor'] = $last->actor;
                }
            }

            $extra['duration_ms'] = (int) max(0, $startedAt->diffInMilliseconds($lastAt));

            $addEvents = $ordered->count();
            $addPageviews = $ordered->where('kind', 'pageview')->count();
            $events = (int) $session->events + $addEvents;
            $pageviews = (int) $session->pageviews + $addPageviews;

            // Bounce: a single pageview and shallow engagement (≤1 interaction beyond it).
            $extra['is_bounce'] = $pageviews <= 1 && ($events - $pageviews) <= 1;

            // Counters are incremented in SQL, never written back from a value
            // read earlier.
            DB::table($table)->where('id', $session->getKey())->incrementEach([
                'events' => $addEvents,
                'pageviews' => $addPageviews,
            ], $extra);

            return $session->refresh();
        });
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
