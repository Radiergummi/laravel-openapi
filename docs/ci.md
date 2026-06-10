# CI integration

Recipes for wiring `openapi:lint` and `openapi:generate` into CI and git hooks. No bespoke
tooling is needed — the CLI already does the work; these are the recipes that connect it.

For the coverage *gate* (`--min-coverage`, `--max-findings`) and the rule catalog, see
[Linting](linting.md). This page is about *where* to run the commands.

## GitHub Actions

`openapi:lint` emits GitHub workflow commands (`::warning file=…,line=…::`) when run with
`--format=github`, so findings show up as inline annotations on the PR. The format is
auto-detected in CI, but pass it explicitly to be sure. Scope to the changed routes with
`--diff` so a PR is judged on what it touched, not the whole pre-existing surface.

```yaml
name: OpenAPI

on: pull_request

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0   # --diff needs history to find the merge-base

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - run: composer install --no-interaction --no-progress

      - run: php artisan openapi:lint --format=github --diff
```

To turn this into a hard gate rather than advisory annotations, add a coverage floor — see
[Linting → Gating CI](linting.md#gating-ci):

```yaml
      # Patch coverage: every operation this PR touches must be lint-clean
      - run: php artisan openapi:lint --format=github --diff --min-coverage=100
```

## Spec drift check

If you commit the generated spec to the repo (to publish it, or to review API changes in the
PR diff), fail CI when the committed copy is stale:

```yaml
      - run: php artisan openapi:generate --output=openapi.yaml
      - run: git diff --exit-code openapi.yaml
```

`git diff --exit-code` exits non-zero when regeneration changed the file, i.e. someone changed
the API without regenerating. For a multi-spec app, generate each spec to its own path (pass
the spec name positionally; `--output` requires a single target).

## Pre-commit / git hooks

A git hook runs locally in the developer's checkout, where PHP and `vendor/` are already
present — a clean fit. Scope it to the **staged** files and treat it as a fast lint (warn, or
block on errors), **not** a coverage gate: a hard coverage block at commit time fights
work-in-progress commits. Leave the coverage gate to CI.

Staged-scoping is what keeps the hook fast and relevant — without it the hook lints the whole
surface and fails on pre-existing, unrelated gaps. Two flags do it:

- `--diff=staged` — lint only routes touched by the staged changes (≈ `git diff --cached`).
- `--path=<file>` — repeatable; lint exactly these files (the natural input when a hook hands
  you `$STAGED_FILES`).

See [Linting](linting.md) for the full flag set.

### pre-commit.com

This repo ships a [`.pre-commit-hooks.yaml`](../.pre-commit-hooks.yaml), so you can reference it
directly:

```yaml
# .pre-commit-config.yaml
repos:
  - repo: https://github.com/Radiergummi/laravel-openapi
    rev: v1.0.0   # pin to a tag
    hooks:
      - id: openapi-lint
```

The hook runs `php artisan openapi:lint --diff=staged` as a `language: system` entry (it uses
your project's PHP and `vendor/`, not an isolated environment).

### CaptainHook

```json
{
    "pre-commit": {
        "actions": [
            {
                "action": "php artisan openapi:lint --diff=staged"
            }
        ]
    }
}
```

### GrumPHP

```yaml
# grumphp.yml
grumphp:
    tasks:
        shell:
            scripts:
                - ['php', 'artisan', 'openapi:lint', '--diff=staged']
```

## See also

- [Linting](linting.md) — rule catalog, severity levels, the coverage gate, `--fix` / `--check`.
- [Migrating from L5-Swagger](migrating-from-l5-swagger.md) — using `migration.*` in the same pipeline.
