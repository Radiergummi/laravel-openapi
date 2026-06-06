---
name: survey
description: Run the laravel-openapi consumer-app survey — deterministic baseline metrics (Layer A) and the phase-2 annotation lift (Layer B1). Use when measuring how the library fares against real apps, or driving one app baseline→annotated to measure the lift.
---

# Survey skill

Drives `radiergummi/laravel-openapi` against real consumer apps. Two flows today; synthesis (reports) is a later layer.

## Prerequisites
- `export WS="$HOME/Projects/laravel-openapi-dogfood"` (external scratch; bootstrapped apps live under `$WS/apps/<App>/repo`).
- `export LIB="<this repo checkout>"`.
- `export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"` — PHP 8.4 (8.5 corrupts `--output=-`).

## Baseline (Layer A — deterministic)
Run the pinned corpus and aggregate metrics:
`tools/survey/corpus.sh [--only <App>]` → `$WS/results.json` + `$WS/manifest.json`. See `tools/survey/README.md`.

## Annotation lift (Layer B1)
Drive one app baseline→annotated and measure the lift:
`Workflow({ scriptPath: ".claude/skills/survey/lift.js", args: { app: "Vito", repoPath: "<$WS>/apps/Vito/repo", apiPrefix: "/api" } })`
It: resets to baseline → deterministic doc-harvest (`bin/survey-harvest-docs`) → plans (`fullspec-analysis.js`) → applies shape/error/security attributes serially per domain → measures → writes `$WS/apps/Vito/lift.json` + `lift-report.md` + `annotation.patch`. **Review the candidate diff + `lift.json`.**

Invariants: the library is the system under test — **never modified** (annotations go in app code only, uncommitted scratch); serial apply (never parallel writers); gaps are emitted as a candidate inventory, **never auto-filed**.

## bin/ helpers (agent operations)
| Helper | Role |
|--------|------|
| `survey-pin-library <app>` | point the app's vendor symlink at `$LIB` (stable library under test); save the original |
| `survey-unpin-library <app>` | restore the vendor symlink saved by `survey-pin-library` |
| `survey-reset <app>` | discard annotation scratch; restore the pinned baseline tree |
| `survey-generate <app>` | `openapi:generate` → `generated-spec.json` (+ gen_exit) |
| `survey-completeness <app>` | per-op completeness on the current spec |
| `survey-attr-sigs <Attr…>` | exact `__construct` signatures of authoring attributes |
| `survey-capture-patch <app>` | save the app-code diff as `annotation.patch` |
| `survey-assemble-spec <dir-or-file>` | merge a split published spec into one document |
| `survey-harvest-docs <app>` | deterministic transcription of Summary/Description/Tag (+ deduped QueryParam) from the published spec |

**Extending:** when an apply-agent repeats an operation across domains/apps, promote it into a new `bin/` helper and add a row here — keep the agent surface terse.

## gap → attribute (lift reference)
Responses (Resource): `#[ResourceField]`. Responses (custom): `#[Response]`/`#[ResponseField]`. Errors: `#[ExceptionResponse]`/`@throws`. Request bodies: `#[RequestBody]`+`#[RequestField]`. Query/path params: `#[QueryParam]`/`#[PathParam]`. Security: Sanctum auto; `#[Security]`/`#[PublicEndpoint]`. Docs: `#[Summary]`/`#[Description]`/`#[Tag]`. (Harvest covers docs + query params deterministically where a published spec exists; agents cover the rest.) See `tools/survey/methodology.md`.
