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
