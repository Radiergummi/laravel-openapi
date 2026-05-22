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

PHPStan runs at level 8 with `treatPhpDocTypesAsCertain` disabled and is a **hard CI gate** —
`composer analyse` must report no errors before a PR can be merged.

## Branches and pull requests

- Branch off `main`; use a short descriptive branch name.
- Keep changes focused — one logical change per PR.
- Add or update tests for any behaviour change.
- Update the relevant page under `docs/` if you change observable behaviour
  (see [`docs/README.md`](docs/README.md) for the index), and `CHANGELOG.md`
  under an `[Unreleased]` section.

## CI

Every pull request must pass the CI matrix: **PHP 8.4 and 8.5 × Laravel 12 and 13**. The `tests`
workflow runs the Pest suite across all four combinations; the `quality` workflow runs Pint and
PHPStan. A PR is mergeable when the `tests` workflow, Pint, and PHPStan are all green.
