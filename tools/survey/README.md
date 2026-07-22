# Survey harness

Tooling for running `radiergummi/laravel-openapi` against real Laravel
applications, to measure robustness and coverage and to drive an app to a
complete spec with authoring attributes. This is the tracked, generalized seed
of the repeatable-survey effort — see the tracking issue for the full design and
the remaining generalization work.

> **Status: salvaged + generalized.** These scripts grew out of a one-off survey.
> They are app-agnostic and parameterized, but the full parameterized survey
> (report profiles, provenance manifest, CI wiring) is not built yet.

## Corpus

`corpus.json` is the committed, pinned set of 11 apps run by the survey. Each entry contains:

| Field | Meaning |
|-------|---------|
| `name` | Human-readable app name used as the workspace directory name |
| `repo` | GitHub URL of the app |
| `ref` | Tag or branch pinned |
| `sha` | Exact commit SHA — the reproducibility fixed point |
| `apiPrefix` | URL prefix used to scope `apiOperations` and `completenessPercent` |
| `php` | PHP major.minor required by the bootstrap script |
| `typedness` | Architectural segmentation tier (`well-typed`, `mixed`, `dynamic`, `dynamic-fractal`) used by `typedness.php`. See [`well-typed-bar.md`](well-typed-bar.md) for the criteria. |
| `publishedSpec` | Repo-relative path to the app's own published spec, or `null` |
| `bootstrap` | Repo-relative path to the per-app bootstrap script |

The SHA pins results to a specific tree state. Re-running any app without
changing its SHA reproduces the same baseline numbers. Bumping a SHA is
a deliberate, reviewed change that invalidates the previous baseline for that app.

Per-app `bootstrap/<name>.sh` scripts take a clean clone and leave it in a
runnable state (env file, sqlite, package linked, config published). A script
that encounters a genuine incompatibility with the current library records
`blocked-compat` as the `bootOutcome` — it does NOT hardcode a historical verdict.
The block is empirical: the script attempts the real install and only records
failure when a step actually fails. A blocked app remains in the corpus as data;
the run is never aborted.

## Workspace layout

The harness operates on an **external scratch workspace** — never inside this
repo or any app's checkout. Set `WS` to it:

```bash
export WS="$HOME/survey-workspace"   # external, your choice
```

```
$WS/
  apps/<name>/repo/          a cloned app, pinned to a ref
  apps/<name>/runbook.md      per-app live log (stamped from runbook-template.md)
  apps/<name>/generated-spec.json, generate.log, lint.json, lint.log
  SCORECARD.md                headline results table (you maintain this)
```

Everything under `$WS` is local-only and is never committed anywhere. The app
code is never modified or committed — this is black-box.

## Prerequisites

- A **PHP 8.4** binary for `artisan`. PHP 8.5 leaks app-side deprecations into
  stdout and corrupts `openapi:generate --output=-`.
- This library installed in `$WS/apps/<name>/repo` via a composer `path`
  repository pointing at this checkout, then `composer require
  radiergummi/laravel-openapi:@dev`.

## Scripts

| Script | Role |
|--------|------|
| `corpus.sh [--only <name>]` | Runner + aggregator: iterates `corpus.json`, running clone → bootstrap → `run.sh` → `metrics.php` for each app. Writes `$WS/results.json` (per-app metrics) and `$WS/manifest.json` (provenance). |
| `metrics.php <app-dir> [--prefix=/api] [--published=<spec>]` | Deterministic per-app metrics extractor. Reads `generated-spec.json`, `lint.json`, and `run.json` from `<app-dir>` and prints the metrics JSON. Safe to `require` in tests. |
| `setup.sh <name> <repo-url> <ref>` | Shallow-clone an app at a pinned ref into `$WS/apps/<name>/repo` and stamp a runbook. Does not bootstrap the app (that step is app-specific, manual). |
| `run.sh <name>` | Run `openapi:generate` + `openapi:lint` in the app; capture spec, logs, exit codes; print a scorecard line. A crash is captured as data, not aborted on. |
| `compare.php <generated> <published>` | Path×method coverage of our spec vs an app's published one. Accepts JSON or YAML. Defaults `LIB` to this repo; override the `LIB` env var to point elsewhere. |
| `completeness.php <generated> [--prefix=/api] [--classify=<classify.json>]` | Per-operation completeness scoreboard and a presenter over `metrics.php`: the response-axis score with its basis, the request-body bucket, and the remaining incomplete ops, under an API prefix. Reads a `classify.json` sitting beside the spec when `--classify` is not given, and prints the path it used. The gate for the attribute-completion pass. |
| `typedness.php <app-dir> [--prefix=/api]` / `typedness.php --corpus <ws>` | Segments API operations by action-return shape (typed payload / correctly-empty / dynamic) and reports unaided substantive coverage *conditioned* on that shape — the well-typed coverage bar (#460). The `--corpus` mode rolls up by each app's `typedness` tier. Consumes a per-app `classify.json` (the #413 classifier artifact); without it, emits only the spec-only substantive figure. See [`well-typed-bar.md`](well-typed-bar.md). |
| `synthesize.php <ws-dir>` | Reads `results.json` + `manifest.json` + every `apps/*/lift.json` and emits two report **candidates** into `<ws-dir>` (a public field-report candidate and an internal synthesis candidate). Deterministic; writes only to `<ws-dir>`, never the curated docs. Safe to `require` in tests. |
| `bootstrap/_lib.sh` | Shared bootstrap helpers sourced by every per-app script: `survey_link_library` (composer path repo + require, then asserts the vendor link actually resolves to `$LIB`), `survey_scaffold_env` (`.env`, sqlite, `key:generate`), `survey_publish_config` (publishes the package config), `survey_blocked` (records `blocked-compat` as data without aborting). |
| `bootstrap/<name>.sh` | Per-app clean-clone → runnable. Sources `_lib.sh`, calls the shared scaffold functions, then applies any app-specific deltas (database driver, queue config, extra composer packages, etc.). |
| `runbook-template.md` | Stamped per app by `setup.sh`. |

## Running the corpus

```bash
export WS="$HOME/survey-workspace"     # external scratch, never inside this repo
export LIB="/path/to/laravel-openapi"  # the library checkout under test
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"  # a PHP 8.4 binary (8.5 corrupts --output=-)
tools/survey/corpus.sh                 # whole corpus
tools/survey/corpus.sh --only BookStack
```

`corpus.sh` reads `corpus.json`, clones each app at its pinned SHA (via
`setup.sh`), resets any stale composer state the previous run left behind (so the
library always relinks to the current `$LIB`), runs the per-app bootstrap, runs
`run.sh`, and calls `metrics.php`. After iterating, it writes two files to `$WS`:

- **`results.json`** — array of `{name, metrics}` objects, one per app. A full
  run rewrites every entry; `--only <name>` **merges** — it replaces just that
  app's entry and preserves the rest, so it is a safe backfill.
- **`manifest.json`** — provenance record: per-app pinned SHA + actual on-disk
  HEAD SHA + the library commit actually installed into that app's vendor, the
  library commit under test, and a run timestamp. The manifest covers the full
  corpus regardless of `--only`.

A run holds an exclusive lock on `$WS` (`$WS/.survey.lock`): a second `corpus.sh`
against the same workspace fails fast rather than racing on the shared aggregate
and flipping each app's vendor link mid-generation. A stale lock left by a killed
run is reclaimed automatically.

CI exposure is a manually-dispatched `survey` workflow
(`.github/workflows/survey.yml`), milestone-gated. It is never triggered per-PR.

## Naming & permission

The corpus apps are named in this tracked tooling because they are public OSS
projects and these scripts only encode public setup steps (clone, composer install,
env scaffold). Tooling-level naming is fine.

Naming apps — or publishing before/after numbers — in the **field report** (the
published artifact discussed in issue #159) is a separate question, gated by
maintainer permission or anonymization. Keep the distinction: names in `corpus.json`
and bootstrap scripts are uncontroversial; names in a published write-up require
explicit permission or anonymization per that issue.

## The two phases

1. **Baseline (these scripts).** Generate + lint on **unmodified** source; record
   robustness and coverage. Deterministic — the numbers come from here.
2. **Attribute-completion (judgment).** Add the library's authoring attributes to
   the app's code (kept as uncommitted scratch) to close inference gaps, and
   measure the lift. See [`methodology.md`](methodology.md).

## Synthesis (candidates)

After a corpus run (and any lift runs), `synthesize.php "$WS"` merges the
artifacts into two report **candidates** written to `$WS`:

- `field-report.candidate.md` — public: corpus table, robustness rollup,
  response-spectrum classification, provenance. No published-spec/coverage
  numbers; carries the issue #159 publication-gate banner.
- `internal-synthesis.candidate.md` — maintainer-only: coverage vs each app's own
  published spec (self-comparison), the recurring-lint rollup, the deduped Layer B1
  gap inventory, and the per-app annotation-lift breakdown.

Synthesis is deterministic — timestamps come from `manifest.json`, so the same
inputs reproduce byte-identical Markdown. The emitter writes **only** to `$WS`; it
never touches the curated `docs/field-report.md` or `docs/internal/**` and never
commits. Folding a candidate into the published report (app-naming per #159, final
narrative, any third-party-number decision) is a human editorial step.

## Reproducibility

`corpus.json` pins every app to a ref + SHA. Results are point-in-time: they
reflect a specific app commit and a specific library commit. The `manifest.json`
written by `corpus.sh` records both, making any run fully traceable. Re-running
requires re-cloning at the pinned SHA.
