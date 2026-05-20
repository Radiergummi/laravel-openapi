# Plugin Suite Program — Agent Context & Tracker

> **Read this before implementing any plan in this program.** It carries the shared
> ground rules, the locked cross-cutting decisions, the build order, and the live
> status. The individual plan files assume you have read this.

**Last updated:** 2026-05-19 — build steps 1–3 complete on the branch; steps 4–5 planned, not started.

---

## What this program is

Workstream 1 of 5 in the pre-1.0 publication program. It extends
`radiergummi/laravel-openapi` so it can document the response and query
conventions of a typical Laravel API codebase without hand-written OpenAPI.

- **Spec (authoritative):** `docs/superpowers/specs/2026-05-18-plugin-suite-design.md` — status *Approved*.
- **Scope:** three new plugins + two Core additions. Documentation prose, the wider
  test suite, type-safety cleanup, and example apps are *separate* workstreams.

## Build steps & status

Execute in order. Each step is a self-contained plan that leaves the suite green.

| Step | Plan file | Status |
|---|---|---|
| 1 — Core PHPDoc generics + paginator resolver | `2026-05-18-core-phpdoc-generics-and-paginators.md` | ✅ **Complete** (on branch, not merged) |
| 2 — `ApiResourcesPlugin` | `2026-05-19-apiresources-plugin.md` | ✅ **Complete** (on branch, not merged) |
| 3 — `QueryBuilderPlugin` | `2026-05-19-querybuilder-plugin.md` | ✅ **Complete** (on branch, not merged) |
| 4 — `FractalPlugin` | `2026-05-19-fractal-plugin.md` | ⬜ Not started — plan ready |
| 5 — composer.json + config wiring | `2026-05-19-plugin-suite-wiring.md` | ⬜ Not started — plan ready |

**Status legend:** ⬜ not started · 🔄 in progress · ✅ complete · ⛔ blocked.
**When you finish a plan (or a notable chunk), update this table and the "Last updated" line.**

## Current state

- All work happens on the branch **`feature/plugin-suite`**, rebased onto `main`
  (which already carries the merged type-safety workstream — PHPStan level 8,
  swagger-php 6, `phpdocumentor/reflection-docblock` 6).
- **Step 1 is done on the branch:** `PaginatorKind`, `ReturnTypeExtractor`,
  `PaginatorSchemaFactory`, `PaginatorResponseResolver`, registered and wired.
- **Step 2 is done on the branch:** `ApiResourcesPlugin` with `#[ResourceField]`,
  `ResourceClassLocator`, `SchemaFromResource`, `ResourceRefSchemaResolver`,
  `ResourceResponseResolver`, three lint rules, registered, wired, and
  default-enabled. The shared `tests/Support/OperationNodeFactory` helper exists.
  Full suite green (`composer test`), Pint clean, PHPStan level 8 clean. The
  branch is **not yet merged to `main`** and has no PR.
- **Step 3 is done on the branch:** `QueryBuilderPlugin` with `#[AllowedFilter]`,
  `#[AllowedSort]`, `#[AllowedInclude]`, `QueryBuilderParameterResolver`, and the
  two lint rules `query-builder.params-undeclared` (level 2) and
  `query-builder.filter-type-missing` (level 3). Shipped commented-out in
  `config/openapi.php`. Full suite green (1064 tests), Pint clean, PHPStan
  level 8 clean.
- Steps 4–5 are planned but no code exists yet.

## Shared ground rules (apply to every plan)

- **Branch:** `feature/plugin-suite`. Do not branch off elsewhere.
- **File header:** every new PHP file is `<?php` · blank · the MIT/copyright
  docblock (verbatim from `src/Core/Generator/OperationBuilder.php` lines 3-8) ·
  blank · `declare(strict_types=1);` · blank · `namespace`. Plan code blocks
  abbreviate the docblock as `// <copyright header>`.
- **Verification gate — all three must pass before every commit:**
  `composer test` (green), `vendor/bin/pint --test` (no violations),
  `composer analyse` (PHPStan level 8, **CI-blocking — no errors**).
- **Commit messages:** imperative mood, `feat:` / `test:` / `docs:` / `build:`
  prefix, and the trailer
  `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.
- **Per-change obligations:** every behaviour change lands with a `CHANGELOG.md`
  `[Unreleased]` entry and the minimal `docs/usage.md` update CLAUDE.md mandates.
- **Lint-rule severity → `level()`:** High = `1`, Medium = `2`, Low = `3`
  (lower = runs at the default lint level).
- **Core stays convention-agnostic:** `src/Core/` must not depend on any plugin
  or third-party convention package. Plugin code lives under `src/Plugins/<Name>/`.

## Locked cross-cutting decisions — do not contradict

These were resolved during planning. If you believe one is wrong, raise it with
the maintainer before deviating — do not silently change course.

1. **Container-cycle break.** A plugin's schema builder (`SchemaFromResource`,
   `SchemaFromTransformer`) and its own `RefSchemaResolver` would form a
   construction cycle. Resolution mirrors `SchemaFromDataClass`: the builder
   **recurses directly** for its own type, and is injected a `RefSchemaResolver`
   list with **its own resolver filtered out** (done in the service-provider
   binding).
   - **Cross-plugin cycle (added 2026-05-20):** filtering out only the
     same-plugin resolver is **not enough once two plugins coexist**. Each
     plugin's `RefSchemaResolver` references the other plugin's `SchemaFromX`
     builder, so eager construction of one closure re-enters the other's
     mid-construction closure → infinite recursion → OOM. **The resolver list
     must be a lazy `Closure(): list<RefSchemaResolver>`**, not an eagerly
     resolved array. `SchemaFromTransformer` uses this pattern; the
     service-provider binding wraps the registry iteration in a memoised
     closure. Future plugin schema builders MUST follow the same pattern, and
     `SchemaFromResource` should be migrated to the same pattern next time it
     is touched.
2. **Plugins are package-free at generation time.** A plugin reads **only its own
   attributes** — never the third-party convention class. `QueryBuilderPlugin`
   never references `Spatie\QueryBuilder\QueryBuilder`; `FractalPlugin` never
   references `League\Fractal\TransformerAbstract` (a "transformer" is *any class
   carrying `#[TransformerField]`*). Third-party packages are `require-dev` +
   `suggest` only.
3. **`#[ResponseResource]` is the consumed attribute.** The ApiResources plugin
   consumes the existing Core `#[ResponseResource]` — there is **no**
   `#[ResourceResponse]` attribute (the spec's Section 1 table is a typo;
   Section 2 is authoritative).
4. **Conservative lint detection.** `query-builder.params-undeclared` and
   `fractal.response-unbound` cannot detect intent without method-body inference
   (forbidden, OAPI-017). They key off an *injected* `QueryBuilder` / `Manager`
   parameter, matched by FQCN **string**. Low false positives, accepts misses.
5. **Resource-collection envelope** models the paginated `{data, links, meta}`
   shape; single resources use `{data}`. The bare paginator `{data, …flat…}`
   envelope is Core's `PaginatorSchemaFactory` (step 1), a different shape.

## Plan dependencies & ordering

- **Step 1 must be merged-or-present** before steps 2–5: they rely on the
  `PrimaryResponseResolver` pipeline, `ReturnTypeExtractor`, and
  `PaginatorSchemaFactory`.
- **Steps 2 → 3 → 4 → 5 in order.** Step 5 wires composer.json and verifies the
  config defaults that steps 2–4 each touch.
- **Shared test helper:** `tests/Support/OperationNodeFactory::forDescriptor()`
  is introduced by step 2 (ApiResources, Task 10) and reused by steps 3–4. If you
  start with a later plan, create it per that task's description first.
- **Within a plan:** the plugin class registers lint rules created in *later*
  tasks, so `composer test` goes green only at the plan's final task. `pint` and
  `analyse` stay green throughout. This is expected and called out in each plan.

## Before you start a plan

1. Read this document and the plan file end-to-end.
2. Confirm you are on `feature/plugin-suite` and the working tree is clean.
3. Run the verification gate once to confirm a green baseline.
4. Adopt the required sub-skill named in the plan header
   (`superpowers:subagent-driven-development` or `superpowers:executing-plans`).
5. Track per-task progress with the plan's `- [ ]` checkboxes.

## After you finish a plan

1. Run the full verification gate; confirm all three pass.
2. Update the **Status** table and the **Last updated** line in this file.
3. Commit the plan's checkbox state and this tracker update.

## Open items for the maintainer

Two planning decisions are reasonable but worth a maintainer's eye before
execution — revisiting them is cheap now, expensive after the code lands:

- The resource-collection envelope always emits `links` + `meta` (decision #5).
  Fine for paginated collections (the dominant case); slightly over-documents a
  non-paginated `ResourceCollection`.
- The conservative lint detection (decision #4) will miss the common
  `QueryBuilder::for(...)` / `fractal(...)`-in-body patterns. Acceptable under
  the no-method-body-inference rule, but it means those two rules fire rarely.
