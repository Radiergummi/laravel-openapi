# Survey harness

Tooling for running `radiergummi/laravel-openapi` against real Laravel
applications, to measure robustness and coverage and to drive an app to a
complete spec with authoring attributes. This is the tracked, generalized seed
of the repeatable-survey effort — see the tracking issue for the full design and
the remaining generalization work.

> **Status: salvaged + generalized.** These scripts grew out of a one-off survey.
> They are app-agnostic and parameterized, but the full parameterized survey
> (report profiles, provenance manifest, CI wiring) is not built yet.

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
| `setup.sh <name> <repo-url> <ref>` | Shallow-clone an app at a pinned ref into `$WS/apps/<name>/repo` and stamp a runbook. Does not bootstrap the app (that step is app-specific, manual). |
| `run.sh <name>` | Run `openapi:generate` + `openapi:lint` in the app; capture spec, logs, exit codes; print a scorecard line. A crash is captured as data, not aborted on. |
| `compare.php <generated> <published>` | Path×method coverage of our spec vs an app's published one. Accepts JSON or YAML. Defaults `LIB` to this repo; override the `LIB` env var to point elsewhere. |
| `completeness.php <generated> [--prefix=/api]` | Per-operation completeness scoreboard: request body (where the verb needs one) + substantive 2xx response, under an API prefix. The gate for the attribute-completion pass. |
| `runbook-template.md` | Stamped per app by `setup.sh`. |

## The two phases

1. **Baseline (these scripts).** Generate + lint on **unmodified** source; record
   robustness and coverage. Deterministic — the numbers come from here.
2. **Attribute-completion (judgment).** Add the library's authoring attributes to
   the app's code (kept as uncommitted scratch) to close inference gaps, and
   measure the lift. See [`methodology.md`](methodology.md).

## Reproducibility

Pin every app to a ref/SHA in your invocation of `setup.sh` and record it in the
runbook. Results are point-in-time: they reflect a specific app version and a
specific library commit. Re-running requires re-cloning at the pin.
