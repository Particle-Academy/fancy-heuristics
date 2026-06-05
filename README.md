# Fancy Heuristics

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
POST /heuristics/collect    { siteKey, sessionId, events: [ Event, ... ] }
POST /heuristics/pixel      { siteKey, style, mode, visible, path, ts }

Event = {
  kind: "pageview"|"click"|"scroll"|"pointer"|"dwell",
  actor: "human"|"agent",
  path, ts,
  x?, y?, vw?, vh?, scrollPct?, dwellMs?, targetId?, label?, meta?
}
```

> **Cross-origin clients:** these endpoints are posted from browsers on other
> origins. Exempt the route prefix from CSRF (`VerifyCsrfToken::$except`) or keep
> them on the stateless `api` middleware group (the default). CORS headers are
> the host's responsibility.

## Facade

```php
use FancyHeuristics\Facades\Heuristics;

Heuristics::record($event);                 // persist one event
Heuristics::collect($payload);              // persist a { siteKey, events } batch
Heuristics::ping($ping);                    // persist a pixel liveness beacon
Heuristics::heatmap($siteKey, $path);       // normalised grid of pointer/click hits
Heuristics::events($siteKey, [...]);        // raw events, filtered
Heuristics::sessionStats($siteKey);         // sessions + counts by actor & kind
```

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
