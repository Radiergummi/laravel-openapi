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

## Releasing

Releases are cut from **signed, annotated tags** on `main`. The `Protect release tags` ruleset
requires every `v*` tag to carry a verified signature and restricts who can create, move, or
delete them — so a lightweight (unsigned) tag will be rejected on push.

1. Ensure `main` is green and move the `CHANGELOG.md` `[Unreleased]` entries under the new
   version heading.
2. Create a signed annotated tag (requires a GPG/SSH signing key registered on your GitHub
   account — the same one used for signed commits):
   ```bash
   git tag -s vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```
   Run `git config tag.gpgsign true` once if you want every tag signed automatically.
3. Publish a GitHub Release from the tag. Packagist picks up the new version through its GitHub
   webhook — there is no separate upload step, so the signed tag is the package's provenance.
