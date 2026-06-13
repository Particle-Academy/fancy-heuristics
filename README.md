# Fancy Heuristics

[![Fancified](art/fancified.svg)](https://particle.academy)

> **End-user optimization, not search-engine optimization.** Understand and
> improve what real humans *and agents* actually do on the page — clicks, focus
> heatmaps, sessions, and the human-vs-agent split GA can't see — instead of
> chasing rankings.

`particle-academy/fancy-heuristics` is the PHP/Laravel ingestion + storage +
query backend for **human + agent interaction analytics** and **Fancy UI pixel
verification**. It is the server half of the Fancy Pixel / Fancy Heuristics
trio (paired with the `@particle-academy/fancy-pixel` badge embed and the
`@particle-academy/fancy-heuristics-js` browser collector).

- **Zero third-party runtime deps** — `illuminate/*` only.
- Namespace `FancyHeuristics\`, facade `Heuristics`, config `config/heuristics.php`.

## Install

```bash
composer require particle-academy/fancy-heuristics
php artisan vendor:publish --tag=heuristics-config      # optional
php artisan vendor:publish --tag=heuristics-migrations  # optional (auto-loaded otherwise)
php artisan migrate
```

## The wire contract

Browser/agent clients flush JSON via `navigator.sendBeacon`:

```
POST /heuristics/collect    { siteKey, sessionId, events: [ Event, ... ], context? }
POST /heuristics/pixel      { siteKey, style, mode, visible, path, ts }

Event = {
  kind: "pageview"|"click"|"scroll"|"pointer"|"dwell",
  actor: "human"|"agent",
  path, ts,
  x?, y?, vw?, vh?, scrollPct?, dwellMs?, targetId?, label?, meta?
}

// Once-per-session acquisition/audience context — sent on the FIRST batch only.
context? = {
  referrer?, utm?: { source?, medium?, campaign?, term?, content? },
  lang?, tz?, screenW?, screenH?, dpr?
}
```

On `collect`, the package upserts a derived **session** row per
`(siteKey, sessionId)`: acquisition (`referrer`/`referrer_host` + `utm_*`),
audience (`device`/`os`/`browser` classified from the request User-Agent with a
self-contained regex — no third-party UA parser — plus `lang`/`tz`/`screen_*`),
and engagement (`pageviews`, `events`, `landing_path`/`exit_path`, `duration_ms`,
`is_bounce`). The raw User-Agent is truncated and stored; the IP is never stored
raw (pixel pings hash it).

> **Cross-origin clients:** these endpoints are posted from browsers on other
> origins. Exempt the route prefix from CSRF (`VerifyCsrfToken::$except`) or keep
> them on the stateless `api` middleware group (the default). CORS headers are
> the host's responsibility.

## Facade

```php
use FancyHeuristics\Facades\Heuristics;

Heuristics::record($event);                 // persist one event
Heuristics::collect($payload, $ua);         // persist a batch + upsert its session
Heuristics::ping($ping);                    // persist a pixel liveness beacon
Heuristics::heatmap($siteKey, $path);       // normalised grid of pointer/click hits
Heuristics::events($siteKey, [...]);        // raw events, filtered
Heuristics::sessionStats($siteKey);         // sessions + counts by actor & kind
```

### GA-parity reports

Each takes a `$site`, a date `$range`, and an optional `$actor` filter
(`'human'`, `'agent'`, or `null` = all). `$range` is either an **int** (last N
days) or `['from' => Carbon|string, 'to' => Carbon|string]` — both compared
against the session's `started_at`. All return primitive, JSON-friendly arrays.

```php
Heuristics::acquisition($site, 30);                  // referrer hosts, utm, direct vs referral
Heuristics::audience($site, 30);                     // device / browser / os / language
Heuristics::timeseries($site, 30, 'day', false);     // sessions+pageviews per bucket (day|week|month)
Heuristics::sessionsSummary($site, 30);              // totals, avg duration, bounce rate, pages/session
Heuristics::topPages($site, 30);                     // top paths by pageviews
Heuristics::entryPages($site, 30);                   // landing pages by session
Heuristics::exitPages($site, 30);                    // exit pages by session
Heuristics::topElements($site, 30);                  // most-clicked target_id / label
Heuristics::realtime($site);                         // sessions active in the last 5 minutes
```

`timeseries(..., $splitActor: true)` adds `human_sessions` / `agent_sessions`
to every bucket.

## Pixel verification

`heuristics_sites` registers each site to re-poll. The verifier fetches the URL
server-side and runs the **shared detection** — the stable `data-fancy-badge`
marker **or** the literal "Powered by Fancy UI" wordmark (the exact same two
signals the showcase's `ScanShowcaseSubmission` scanner uses, kept in one place
in `HeuristicsPixelDetector`). It updates `visible` / `pixel_status` /
`last_verified_at` and fires `PixelVerificationPassed` / `PixelVerificationFailed`
for the host to toggle a listing.

Run twice daily:

```php
// bootstrap/app.php
use Illuminate\Console\Scheduling\Schedule;

->withSchedule(function (Schedule $schedule) {
    $schedule->command('heuristics:verify-pixels')
        ->cron(config('heuristics.verify.cron')); // default 03:00 & 15:00
})
```

```bash
php artisan heuristics:verify-pixels            # all sites
php artisan heuristics:verify-pixels --site=KEY # one site
```

## Tests

```bash
composer install
vendor/bin/pest
```

---

## ⭐ Star Fancy UI

If this package is useful to you, a quick ⭐ on the repo really helps us build a better kit. Thank you!
