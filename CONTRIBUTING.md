# Contributing

Contributions are welcome. This guide covers the local workflow and what CI expects.

## Setup

```bash
composer install
```

## Running the test suite

```bash
composer test
```

This runs Pest (without coverage). The suite uses Orchestra Testbench, so no host Laravel
application is required.

## Quality gate

```bash
composer lint      # Laravel Pint — code style
composer analyse   # PHPStan (via Larastan) — static analysis
```

`composer lint` reports style violations; run `vendor/bin/pint` to apply fixes.

PHPStan currently has known, pre-existing findings. The static-analysis step is **non-blocking in
CI** — it runs and reports, but does not fail the build. Please don't introduce new findings, but
clearing the existing backlog is a separate effort.

## Branches and pull requests

- Branch off `main`; use a short descriptive branch name.
- Keep changes focused — one logical change per PR.
- Add or update tests for any behaviour change.
- Update `docs/usage.md` if you change observable behaviour, and `CHANGELOG.md` under an
  `[Unreleased]` section.

## CI

Every pull request must pass the CI matrix: **PHP 8.4 and 8.5 × Laravel 12 and 13**. The `tests`
workflow runs the Pest suite across all four combinations; the `quality` workflow runs Pint and
PHPStan. A PR is mergeable when the `tests` workflow is green and Pint reports no violations.
