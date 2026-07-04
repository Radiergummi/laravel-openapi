# Full-spec attribute proof — methodology

A repeatable procedure for driving **any** real `radiergummi/laravel-openapi`
consumer app to a **complete, high-fidelity OpenAPI document for its API
surface** using the package's authoring attributes — and capturing, as it goes, a
ranked inventory of attribute-surface gaps.

Use it to prove (per app) that the attributes are a sufficient escape hatch, and
to find where they aren't. The method, gates, and attribute playbook are the same
for every app; everything app-specific (domains, fields) is derived per run.

## When to use

You have a Laravel app that consumes the package, convention covers only part of
its API (typically responses are thin/empty because Resources build `toArray()`
imperatively and writes validate outside FormRequests), and you want to (a) reach
a complete spec and (b) learn whether the attribute surface suffices.

## Invariants (apply to every app)

- **The library is the system under test — never modify it.** Annotations go in
  the *app's* code only, kept **uncommitted scratch**. If reaching completeness
  would need a library change, that is by definition an attribute-surface **gap**
  → file an issue; don't patch the library.
- **Use a PHP 8.4 binary** for `artisan`. PHP 8.5 leaks app-side deprecations into
  stdout and corrupts `--output=-`.
- **Tiered gap handling:** attribute first → if none fits, file **one issue per
  distinct gap** → apply the minimal `openapi.overrides` / `transformDocument()`
  fallback to keep completeness.
- **"Substantive" response** = the 2xx content schema, resolved through `$ref` and
  a single-key `{data:…}` envelope unwrap, carries ≥1 property (or is an explicit
  no-content 2xx). An empty `{data:$ref}` does **not** count.
- **Scope** to the API prefix (`/api` usually). Don't document the web/UI/tooling
  surface.

## Procedure

### Phase 0 — Pick target, baseline
1. Pick an app that boots far enough for `route:list` + `generate`. Note the pin
   and the linked library HEAD.
2. Baseline: `generate` → `completeness.php <spec> --prefix=/api` (expect a low %),
   and `openapi:lint --path=api` finding count. Record both in the app's runbook.

### Phase 1 — Analyze (read-only)
Discover the controller domains from `route:list`, and for each domain extract the
Resource `toArray()` fields, Action-class validation, query params, errors, and
the **exact** attributes to add (read constructor signatures from the app's
vendored package — don't guess). Produce a deduped gap inventory + an execution
order. **No edits, no issues yet.** This phase is automatable with an analysis
workflow; keep it read-only regardless.

### Phase 2 — Apply + verify (serial, one domain at a time)
Domains share the app working tree → **never parallel writers**. Per domain, in
execution order:
1. Apply the domain's attributes to the exact files (Resource once if shared;
   controller methods for requests/params/errors/docs).
2. `generate` → expect exit 0, empty stderr. A crash = an edit error (fix) or a
   library bug (file a gap, don't patch the library).
3. `completeness.php` (the domain's ops drop out of INCOMPLETE) +
   `lint --path=api/<fragment>` trending to 0. (Nested URIs make `--path`
   best-effort; the full-`/api` lint is authoritative.)
4. Record in the runbook; file/note gaps + any escape-hatch fallback.

### Phase 3 — Finalize
1. Full gate: `completeness.php` → **100%**; `lint --path=api` → **0 findings**
   (or `#[IgnoreLint]` with a reason).
2. Parity spot-check ~10 varied ops vs the app's published spec, if any: match /
   ours-thinner / ours-richer / theirs-drift.
3. File the deduped gaps as issues (one per gap).
4. Final report: completeness start→end, lint delta, parity table, ranked gap
   list — and the verdict: did attributes alone suffice, or where was a fallback
   needed?
5. Confirm the library checkout is clean; app edits stay uncommitted scratch.

## Attribute playbook (gap → attribute) — app-agnostic

| Need | Attribute | Notes |
|------|-----------|-------|
| Response fields (API Resource) | `#[ResourceField]` (ApiResources plugin) | Resources with imperative `toArray()`; names key + type. Primary response lever. |
| Response (non-Resource / custom) | `#[Response]` / `#[ResponseField]` | |
| Error responses | `#[ExceptionResponse]` / `@throws` / `#[Response]` | clears `response.no-error` |
| Request body | `#[RequestBody]` + `#[RequestField]` | for apps validating outside FormRequests (Action/service classes) |
| Query params | `#[QueryParam]` | filters / pagination / sort |
| Path params | route-model binding (convention); else `#[PathParam]` | |
| Security | Sanctum auto-derived; `#[Security]` / `#[PublicEndpoint]` to override | |
| Summary / description / tags | `#[Summary]` / `#[Description]` / `#[Tag]` | clears doc-quality lint rules |
| Examples / headers | `#[ResponseExample]` / `#[ResponseHeader]` | for parity |

Read the exact constructor signatures from the app's vendored package
(`<repo>/vendor/radiergummi/laravel-openapi/src/...`) at annotation time — don't
guess.

## Baseline metrics (definitions)

`metrics.php` computes a deterministic, spec/lint-derived metrics record for each
app. The numbers in `$WS/results.json` come exclusively from this function — never
from an LLM or manual inspection. Inputs are the three artifact files that
`run.sh` writes (`generated-spec.json`, `lint.json`, `run.json`) and the `apiPrefix`
from `corpus.json`. All fields are reproducible: re-running with the same artifacts
and prefix yields identical output.

| Field | Definition |
|-------|------------|
| `paths` | Number of distinct path items in `spec.paths`. |
| `operations` | Total operations across all verbs (`get post put patch delete`) in all paths. |
| `apiOperations` | Operations whose path starts with `apiPrefix`. All per-operation metrics below are scoped to this set. |
| `responseSchemas` | API operations with a **substantive** 2xx response. Substantive means the 2xx content schema, resolved through `$ref` hops and a single-key `{data:…}` envelope, carries ≥1 property, OR is a scalar/array/`additionalProperties` type. An empty object does not count, and a contentless 2xx (no `content` key) carries no schema so it does not count here — it lands in `documentedResponses`. |
| `documentedResponses` | API operations that document **any** 2xx outcome — a substantive schema, an empty-schema body, or a contentless 2xx (e.g., `204`) alike. The superset of `responseSchemas`; tracks "does the op describe a success outcome at all" without flip-flopping when a bare-200 gains a not-yet-substantive schema. |
| `requestBodies` | API operations that have a request body with a schema in at least one media type. |
| `maxRequestProperties` | Largest property count across all request-body schemas (following one `$ref` hop). |
| `componentSchemas` | Number of entries in `spec.components.schemas`. |
| `completenessPercent` | `round(100 × complete / apiOperations, 1)`. An operation is complete when it has a substantive 2xx response **or** a contentless 2xx (a `204` is a complete response, mirroring `completeness.php`) AND, for `post`/`put`/`patch`, also a request body. An empty-schema 2xx does not count. |
| `lintFindings.total` | Total number of findings in `lint.json`. |
| `lintFindings.byLevel` | Map of `level → count` across all findings. |
| `lintFindings.byRule` | Map of `rule_id → count` across all findings. |
| `crash.generateExit` | Exit code of `openapi:generate`. |
| `crash.lintExit` | Exit code of `openapi:lint`. |
| `crash.generateStderr` | Whether `openapi:generate` produced any stderr output. |
| `crash.bootOutcome` | Bootstrap outcome written by the bootstrap script: `ok`, `blocked-compat`, or `unknown`. |
| `crash.routesIntrospected` | Number of routes seen by the generator, if captured; `null` otherwise. |
| `coverage` | Only present when the corpus entry provides a `publishedSpec`. Compares path×method keys (with `{param}` collapsed to `{}`) between the generated spec and the published one. Contains: `publishedOps` (keys in their spec), `ours` (keys in our spec), `intersection` (keys present in both), `covPercent` (`round(100 × intersection / publishedOps, 1)`). |
| `responseCoverage` | Only present when the app dir has a `classify.json` (from `classify.php`). The honest three-way split of `apiOperations`, since neither `responseSchemas` (pessimistic: counts a correct empty 2xx as a miss) nor `completenessPercent` (optimistic: credits a give-up empty 2xx as complete) is right. Contains `substantive` (a real payload — today's `responseSchemas`), `correctlyEmpty` (the action is genuinely no-content: `void`/`never`, `return;`, `return null;`, `response()->noContent()`), `genuinelyMissing` (the action returns a body the generator emitted empty/thin), and `genuinelyMissingByShape` (a rollup of the give-up shapes). The three counts partition `apiOperations` exactly; `genuinelyMissing` is the real denominator to size response-inference levers against. An op with no classification record counts conservatively as `genuinelyMissing` under the `unclassified` shape. |

### `classify.php` (action source-shape classifier)

The correctly-empty vs give-up-empty split is not in the spec — it needs the action's return shape.
`classify.php <repo-dir> [--prefix=/api]` boots the consumer app, reflects each in-prefix action, and
AST-classifies its return expression into a source-shape string, emitting `classify.json` (one record
per route: `{uri, verb, action, returnType, shape}`). `metrics.php` reads `classify.json` from the app
dir when present and joins it with spec substantive-ness; `completeness.php --classify=<classify.json>`
prints the same three-way line. The classifier mirrors the library's reader whitelist — it labels
refused shapes too, but never decides substantive-ness itself.

## The short version (a new app)

1. Clone + boot the app (sqlite, no real services); link the package via a
   composer `path` repo.
2. Phase 1: analyze read-only → a per-domain plan + gap inventory.
3. Phase 2: apply sequentially, one domain at a time, reviewing between.
4. Phase 3: gate + parity + file gap issues + report.

## Synthesis emits candidates, not the report

`synthesize.php` (Layer B2) merges the corpus + lift artifacts into two Markdown
**candidates** for maintainer review — it does **not** write the published field
report. The mechanical substrate (measured numbers, per-app classification,
recurring-gap rollup, lift breakdown, provenance manifest) is derived
deterministically; the editorial half — app-naming per issue #159, final narrative
voice, and any decision about third-party numbers — stays with the maintainer, who
diffs a candidate against the curated `docs/field-report.md` and weaves it in.
