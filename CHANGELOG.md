# Changelog

Notable changes to `particle-academy/fancy-heuristics`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases — read those entries before
upgrading.

> Entries below **1.0** were reconstructed from git history when this file was
> introduced, so they summarise commit subjects rather than consumer impact.
> Everything from the next release onward is written by hand, in the same commit
> as the change.

---

## [Unreleased]

### Added

- **`php artisan heuristics:prune`** (and `Heuristics::prune()`) enforces
  retention. `retention_days` was advisory until now: nothing deleted
  anything. Defaults: raw events 90 days, pointer samples 14, pixel pings 90,
  sessions 395 (by last activity). `--dry-run` prints the counts and deletes
  nothing. **What you must do:** schedule it daily if you want the windows
  enforced; until you do, nothing is deleted, exactly as before.
- **Ingest limits** under `heuristics.limits`: events per batch (500), key
  length (128), path length (255), legacy label length (120), `meta` keys (16)
  and bytes (2048), coordinate range, scroll percentage 0 to 100, dwell 0 to 1
  hour, and timestamp skew (1 day ahead, 30 days back).
- **`Heuristics::ingest()`** returns accepted and rejected counts plus
  `errors: [{index, code}]`. `collect()` is unchanged.
- **Optional range** on `sessionStats($site, $range)` and
  `heatmap($site, $path, $grid, $range)`. With no range they behave as
  before.
- An index on `heuristics_events (site_key, session_id, occurred_at)`. Run
  `php artisan migrate`.

### Changed

- **The collect response** is now
  `{ ok, replayed, accepted, rejected, errors }`. `ok` and `accepted` keep
  their meaning, so a client that read them needs no change.
- **BREAKING (public endpoint only): events outside the limits are now
  rejected** instead of stored. That covers a path or label over the bound,
  `meta` that is nested or over its caps, impossible numbers, and a `ts` more
  than a day ahead or 30 days behind the server clock. **What you must do:**
  nothing, unless you post historical events through `/collect`. The facade
  (`Heuristics::collect()` / `record()`) does not apply the skew check, so
  server-side imports keep working.
- **Paths are stored without their query string or fragment**, on every
  path. They carried reset tokens and per-visit state. Per-path reports now
  merge `/a?x=1` and `/a?x=2` into `/a`.
- **Legacy labels have their whitespace collapsed** and are cut to 120
  characters.
- **The session rollup is ordered by timestamp, not by position in the
  batch.** A late beacon from an earlier page now moves the landing page back
  and never overwrites the exit page. Counters are incremented in SQL under a
  row lock, and the events and their rollup are written in one transaction,
  so concurrent batches for one session can no longer lose an increment.
- The heatmap streams its samples in pages instead of loading every row.

### Security

- **Pixel IP hashes are now keyed.** They were a bare SHA-256 of the IP,
  which anyone with the table can reverse by hashing every IPv4 address. They
  are now HMAC-SHA256 under `heuristics.ip_hash_key`, which defaults to the
  app key. If no key exists at all, no hash is stored. Hashes written before
  this release cannot be compared with new ones; prune them with
  `heuristics:prune` once they age out.

## [0.5.0] - 2026-10-27

### Security

- **Pixel verification could be pointed at your internal network (SSRF).**
  `PixelVerifier` fetched each registered site's URL with Laravel's HTTP client
  as given: any scheme the client accepted, any address, and redirects followed
  automatically. Whoever registered a site could make your server send GET
  requests to loopback, RFC 1918, link-local addresses including
  `169.254.169.254` cloud metadata, CGNAT, and IPv6 ULA / link-local or
  IPv4-mapped equivalents. They could do it directly, through a hostname that
  resolves there, or through a redirect. Only pass/fail was exposed, so it was
  blind, but it ran twice daily and on demand.

  Fetches now go through `SafePageFetcher`:
  - http/https only;
  - every resolved address must be public;
  - decimal, octal and hex IPv4 spellings are refused;
  - the connection is pinned to the validated address via `CURLOPT_RESOLVE`,
    which closes DNS rebinding;
  - redirects are followed by hand, at most `verify.max_redirects` (default
    3), and each hop is re-checked and re-pinned;
  - the body is capped on the wire at `verify.max_bytes` (default 2 MB).

  **What you must do:** nothing, unless a registered site legitimately lives on
  a private address, for example a staging host on your LAN. Its verification
  now fails with `fetch refused: …`. To allow it, implement
  `FancyHeuristics\Contracts\PageFetcher` with your own policy and set
  `heuristics.verify.fetcher` to that class, or rebind the contract.

### Added

- `FancyHeuristics\Contracts\PageFetcher`, the seam the verifier fetches
  through, plus `heuristics.verify.fetcher` to select an implementation.
- `FancyHeuristics\Contracts\HostResolver`: DNS resolution for the fetcher,
  rebindable for tests or custom resolution.
- `verify.max_redirects` and `verify.max_bytes` config keys
  (`HEURISTICS_VERIFY_MAX_REDIRECTS`, `HEURISTICS_VERIFY_MAX_BYTES`).

### Changed

- `PixelVerifier`'s constructor takes an optional `PageFetcher` as a second
  argument. Existing `new PixelVerifier($detector)` calls keep working and
  resolve the bound fetcher.

## 0.4.0 — 2026-08-07

### Changed

- **BREAKING — PHP 8.2 is no longer supported.** `require.php` moves from `^8.2` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.2, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- **BREAKING — Laravel 11 and 12 are no longer supported.** The framework requirement narrows from `^11.0|^12.0|^13.0` to `^13.0`.

  **What you must do:** on Laravel 13, nothing. On 11 or 12, stay on the previous release until you upgrade the framework.

- CI now tests PHP 8.4 with Laravel 13 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.


## 0.3.1 — 2026-07-07

### Fixed

- **cors:** make ingestion OPTIONS routes route:cache-safe (0.3.1)

## 0.3.0 — 2026-07-07

### Added

- built-in CORS for the ingestion endpoints (default on)

## 0.2.2 — 2026-06-12

### Changed

- lead positioning with EUO-not-SEO tagline

## 0.2.1 — 2026-06-12

### Fixed

- **query:** Postgres-safe bounce count (boolean has no = 1 operator)

## 0.2.0 — 2026-06-12

### Added

- sessions + GA-parity queries (acquisition/audience/timeseries/realtime)

## 0.1.0 — 2026-06-05

### Added

- **detector:** match the fancy-pixel loader script signature
- initial fancy-heuristics MVP
