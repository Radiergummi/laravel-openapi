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

### Coverage comment + gate

There is no first-party GitHub Action — `openapi:lint` is a peer linter, and the PHP tools it sits
next to (PHPStan, Pint, Pest) don't ship Actions either. The CLI already emits everything an Action
would, so the "Action" is just a few steps: write the linter's report to a file, post it as a
sticky PR comment with a generic comment action, and gate the job on `--min-coverage`.

```yaml
name: OpenAPI

on: pull_request

permissions:
  contents: read
  pull-requests: write   # the sticky comment writes to the PR

jobs:
  coverage:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0   # --diff resolves the merge-base from history

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - run: composer install --no-interaction --no-progress

      # One run, three outputs: a report file for the comment, inline ::warning
      # annotations on the diff, and a non-zero exit when coverage drops below the floor.
      - name: Lint documentation coverage
        run: |
          php artisan openapi:lint \
            --diff \
            --format=markdown:coverage.md \
            --format=github \
            --min-coverage=90

      # Post (and update in place) the report as a single PR comment. `if: always()`
      # keeps the comment landing even when the gate above fails the step.
      - name: Sticky coverage comment
        if: always()
        uses: marocchino/sticky-pull-request-comment@v2
        with:
          header: openapi-coverage   # the key that makes it update, not duplicate
          path: coverage.md
```

A few things worth knowing about this recipe:

- **The `markdown` target emits the linter's report, not Markdown tables.** `--format=markdown` is
  currently an alias for the `cli` formatter with color codes stripped — the file holds the same
  finding tree and `Coverage: NN%` summary you see in the terminal, which reads fine inside a PR
  comment. The sticky-comment action posts the file verbatim; wrap it in a fenced block first if you
  want the monospace tree to line up:

````yaml
      - name: Wrap report for the comment
        if: always()
        run: |
          { echo '```text'; cat coverage.md; echo '```'; } > coverage-comment.md
      - name: Sticky coverage comment
        if: always()
        uses: marocchino/sticky-pull-request-comment@v2
        with:
          header: openapi-coverage
          path: coverage-comment.md
````

- **`--diff` resolves its base ref from git history.** With no value it diffs against the
  merge-base with the default branch, which needs the full history — hence `fetch-depth: 0` on the
  checkout. To pin the base to the PR target explicitly, pass the event's base SHA:

  ```yaml
      - run: php artisan openapi:lint --diff=${{ github.event.pull_request.base.sha }} --min-coverage=100
  ```

- **Make the job a required check.** The `--min-coverage` exit code only blocks a merge once the
  job is required: in **Settings → Branches → Branch protection rules**, enable *Require status
  checks to pass before merging* and add the `coverage` job. Until then the failing run is advisory.

> Set the floor with judgement. A whole-suite `--min-coverage=90` on a large existing API will fail
> on day one; either start from the current percentage and ratchet it up, or scope the gate to the
> diff (`--diff --min-coverage=100`) so a PR is judged only on the operations it touched.

### Codecov / SonarQube instead of a comment

If you already push coverage to Codecov, Coveralls, or SonarQube, skip the PR comment entirely and
feed those tools the `cobertura` report — they render patch coverage on the PR themselves:

```yaml
      - run: php artisan openapi:lint --format=cobertura:coverage.xml --min-coverage=90

      - uses: codecov/codecov-action@v4
        with:
          files: coverage.xml
          flags: openapi
```

The `cobertura` (and `lcov`) reports key documentation coverage to controller source lines, so the
external tool shows exactly which operations are undocumented. See
[Linting → Output targets](linting.md#output-targets) for the full format list.

## Spec drift check

If you commit the generated spec to the repo (to publish it, or to review API changes in the
PR diff), fail CI when the committed copy is stale:

```yaml
      - run: php artisan openapi:generate --output=openapi.yaml
      - run: git diff --exit-code openapi.yaml
```

`git diff --exit-code` exits non-zero when regeneration changed the file, i.e., someone changed
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
