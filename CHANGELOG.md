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
