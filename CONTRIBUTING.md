# Contributing to particle-academy/fancy-heuristics

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Tests

```bash
composer install
vendor/bin/pest
```

`composer test` runs the same. The suite is in `tests/` (`Feature/`, `Unit/`,
helpers in `Support/`).

## Invariants

These are promises the README makes to consumers; keep them true.

- **Zero third-party runtime dependencies** — `illuminate/*` only. The
  User-Agent classifier is a self-contained regex, not a third-party parser.
- **The IP is never stored raw.** Pixel pings store `HMAC-SHA256(key, ip)`.
- **The ingest response never echoes a submitted value**, and a batch's events
  and its session rollup are written in one transaction.
- **Pixel detection lives in one place**, `HeuristicsPixelDetector`, and uses the
  same two signals as the showcase's `ScanShowcaseSubmission` scanner. Change
  one and check the other.
- **`SafePageFetcher` only reaches the public internet**, pins the connection to
  the address it validated, and checks and pins every redirect hop. Its residual
  gaps are documented in the README's Fetch safety section; keep that section
  true when the fetcher changes.

## Changelog

Update `CHANGELOG.md` (Keep a Changelog) in the same commit as the change.
