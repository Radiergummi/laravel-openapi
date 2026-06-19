---
name: survey
description: Run the laravel-openapi consumer-app survey — deterministic baseline metrics (Layer A), the phase-2 annotation lift (Layer B1), and report-candidate synthesis (Layer B2). Use when measuring how the library fares against real apps, driving one app baseline→annotated to measure the lift, or emitting report candidates for maintainer review.
---

# Survey skill

Drives `radiergummi/laravel-openapi` against real consumer apps. Three flows: baseline metrics (Layer A), the annotation lift (Layer B1), and report-candidate synthesis (Layer B2).

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

## Synthesis (Layer B2 — deterministic)
After a corpus run (Layer A) and any number of lift runs (Layer B1):
`php tools/survey/synthesize.php "$WS"` reads `$WS/results.json` + `$WS/manifest.json` + every `$WS/apps/*/lift.json` and emits **two candidates** into `$WS`:
- `field-report.candidate.md` — public: corpus table, robustness rollup, response-spectrum classification, provenance. **No** published-spec/coverage numbers; carries the #159 publication-gate banner.
- `internal-synthesis.candidate.md` — maintainer-only: coverage vs each app's own published spec (self-comparison, not a third-party benchmark), recurring-lint rollup, deduped B1 gap inventory, per-app annotation-lift breakdown, provenance.

Deterministic: timestamps come from `manifest.json`, so the same inputs reproduce byte-identical Markdown. The emitter writes **only** to `$WS` — it never touches `docs/field-report.md` or `docs/internal/**` and never commits. Folding a candidate into the curated `docs/field-report.md` (app-naming per #159, final narrative voice, any third-party-number decision) is a **human editorial step**, gated by #159 — not the harness's job.

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
