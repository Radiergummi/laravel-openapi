# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`radiergummi/laravel-openapi` is a Laravel package that generates an OpenAPI 3.1 document from
existing route definitions (typed request DTOs, typed return values, PHPDoc, auth middleware) —
no hand-written YAML — and ships a documentation linter (`openapi:lint`). Requires PHP 8.4+ and
Laravel 12 or 13.

## Commands

```bash
composer test                    # Pest suite (no coverage), via Orchestra Testbench
composer lint                    # PHPStan / Larastan, level 8 (alias: composer analyse)
composer format                  # Laravel Pint — apply style fixes (alias: composer fmt)
vendor/bin/pint --test           # Pint — report style violations without fixing

vendor/bin/pest tests/Feature/ExamplesTest.php         # single test file
vendor/bin/pest --filter "substring of test name"      # single test by name
```

The suite runs on Testbench — no host Laravel app is needed. CI matrix is PHP 8.4/8.5 ×
Laravel 12/13, plus a dedicated job running the suite against `zircote/swagger-php` 5.8 (the
supported range is `^5.8 || ^6.1.2`; the byte-exact `snapshot` group is excluded from the 5.x
job). A PR is mergeable when `tests` is green, Pint reports no violations (`vendor/bin/pint
--test`), and PHPStan passes. PHPStan runs at level 8 with `treatPhpDocTypesAsCertain: false`
and is **CI-blocking** — `composer lint` must report no errors.

## Development workflow

Feature and bug work is tracked in **GitHub Issues**, not in `docs/` spec/plan files.
Specs live in issue descriptions; implementation plans live in **draft-PR descriptions**.
Planning issues carry the `spec` label and sit on the **Roadmap** project, bucketed into the
`v1.0` / `v1.1` / `v1.2` milestones; the economic-sensibility tier and affected area are labels
(`tier-0/1/2`, `area:*`).

Start each work session by opening a **draft PR**:

1. Branch from `main` (`feat/…`, `fix/…`, or `chore/…`).
2. `git commit --allow-empty` so the branch has a diff, then push.
3. `gh pr create --draft` with a proper title, the implementation plan as the body, the relevant
   labels, `Closes #<issue>`, and Moritz as reviewer.

The issue stays the durable record of *what* and *why*; the PR body is *how*. If the
implementation deviates from the plan, record the decision and its reasoning as a **PR comment**
(and edit the issue if the spec itself changed). Mirror progress on the Roadmap project's Status
field (Todo → In progress → In review → Done).

**File an issue for anything incidental.** When you hit something outside the current task's
scope worth not forgetting — a pre-existing bug, a flaky or missing test, tech debt, a doc gap, a
surprising behaviour — do **not** fix it inline (stay surgical); open a GitHub issue so it can't be
lost. Give it a clear title and a body with what/where (`file:line`, and the PR or issue you were
on), why it matters, and a repro or acceptance hint if you have one. Label it by type
(`bug` / `enhancement` / `test` / `chore`) + `area:*`; leave it unmilestoned for Moritz to triage;
do **not** add `spec` (that label is for curated planning issues, not incidental reports). Run
`gh issue list --search "<keywords>"` first to avoid duplicates, link the new issue from the PR you
were working on, and call it out in your session summary. If the finding *blocks* the current task,
say so on the issue and surface it to Moritz rather than silently widening scope.

Merge policy: **squash-merge into `main`**, gated on green CI (`tests`, `vendor/bin/pint --test`,
PHPStan) and Moritz's review; keep history linear. (No merge queue.)

## Architecture

The codebase splits into four namespaces:

- `Contracts\` — public extension surface (interfaces like `Plugin`,
  `RequestSchemaResolver`, `RefSchemaResolver`, `QueryParameterResolver`,
  `PrimaryResponseResolver`, `ErrorResponseResolver`, `SpecStage`, `RouteFilter`).
- `Core\` — the **Core Plugin**: bundled extraction/processing strategies
  (FormRequest extractor, error-envelope strategies, paginator response resolver,
  standard-response extractor, default query-parameter resolver, Faker example
  synthesiser, route introspection). Registers itself as `Core\CorePlugin`.
- `Support\` — internal infrastructure (generator pipeline + stages, spec
  resolution, inclusion evaluator, visibility resolver, extraction primitives).
  PHPDoc/type parsing lives here: `Support\PhpDoc\DocBlockParser` +
  `Support\Types\TypeNodeResolver`, built on `phpstan/phpdoc-parser` +
  `symfony/type-info` — the `phpdocumentor`/`reflection-docblock` stack was dropped.
  Treat as `@internal`; not a stable extension point.
- `Plugins\` — bundled third-party convention plugins. Four ship: **SpatieData**
  and **ApiResources** are enabled by default in `config/openapi.plugins`;
  **QueryBuilder** and **Fractal** are present but commented out (each requires
  opting into a third-party package).

### Generation pipeline

`OpenApiServiceProvider` wires everything. The flow (realized as the ordered `SpecStage`
pipeline registered by `BaselineRegistration` — see Registry and plugins):

1. `RouteIntrospector` walks Laravel routes (after applying `RouteFilter`s), producing an
   `ActionDescriptor` per route. `DocCommentParser` extracts summary/description/`@throws`.
2. `OperationBuilder` builds each operation by running every resolver/extractor registered in
   the `OpenApiRegistry`: query-parameter resolvers, request-schema resolvers, primary-response
   resolvers, `SecurityExtractor`, `StandardResponsesExtractor`, `UriParametersExtractor`.
3. `ComponentSchemaRegistry` is the shared `$ref` pool for reusable Data-class schemas.
4. `OpenApiGenerator` assembles the final OpenAPI 3.1 document (YAML or JSON).

### Registry and plugins

`OpenApiRegistry` (in `Registry\`) is the extension point.
`Support\Generator\BaselineRegistration::register()` runs first — it adds the load-bearing
stage pipeline (`RootStage` → `PathsStage` → `ErrorResponseInferenceStage` → `ComponentsStage`
→ `SecurityStage`) and the library-wide lint rules. Then each plugin in `config/openapi.plugins`
order runs, starting with `Core\CorePlugin` (registers `FormRequestRequestSchemaResolver`,
`CoreQueryParameterResolver`, `PaginatorResponseResolver`, and the core lint rules); finally any
`config/openapi.lint.rules` extras. A plugin implements `Contracts\Registry\Plugin` and registers
resolvers, extractors, error-response factories, payload class markers, lint rules, and
additional `SpecStage`s. `FormRequest` request bodies are handled by Core directly; Spatie Data
classes are handled by the SpatieData plugin.

### Lint subsystem (`src/Lint/`)

`SpecTreeBuilder` converts the generated document into a domain tree (`Tree/*Node`).
`SpecTreeWalker` walks it; each `Rules/*` rule implements one or more visitor interfaces
(`Rules/Visitors/*Rule`) and emits `Finding`s into a `FindingsCollector`. `RuleRegistry` holds
the active rules with config-driven severity overrides. `SuppressionCollector` reads
`#[IgnoreLint]` attributes. Each lint rule has a stable string ID (e.g. `operation.id-missing`).

### Service lifecycle

All pipeline classes are bound as **scoped** singletons (not regular singletons). Octane resets
scoped bindings between requests, so each generation run gets fresh instances —
`ComponentSchemaRegistry` and `ExampleFileLoader` carry mutable per-run state and would corrupt
concurrent runs otherwise. `reset()` methods exist but are redundant under the scoped lifecycle.

## Conventions

- Every PHP file has a strict-types declaration and the MIT/copyright docblock header.
- `src/Core/`, `src/Support/`, and `src/Contracts/` must not depend on any plugin or
  third-party convention package — plugin-specific code belongs in `src/Plugins/`.
- `src/Core/` holds **only concrete strategies** that participate in the Core Plugin
  (extractors, envelope strategies, the default query-parameter resolver, route
  introspection, etc.). Infrastructure shared across plugins goes in `src/Support/`.
- Classes intended only for internal use (not part of the documented extension
  surface) should carry an `@internal` PHPDoc tag.
- Behaviour changes need test updates, an update to the relevant page under `docs/`
  if observable (see `docs/README.md` for the page index), and a `CHANGELOG.md`
  entry under `[Unreleased]`.
- Authoring attributes live in `src/Attributes/`; they are the escape hatch for cases
  convention cannot derive.

## Inference philosophy & boundaries

The generator infers as much of the OpenAPI document as it can from **conventional Laravel
usage**; authoring attributes (`src/Attributes/`) are the escape hatch, used only where the code
genuinely cannot express the information. Every proposed inference is judged on an
**economic-sensibility ladder**, and built at the *lowest* tier that captures the idiom:

- **Tier 0 — reflection & signatures.** Class/method signatures, PHPDoc tags, attributes, model
  metadata (`$casts`, `$hidden`, `$visible`, `$appends`, `$fillable`, migration columns), backed
  enums, route-model-binding types, middleware names. Deterministic, cheap, no body parsing —
  the library's whole current basis. Always prefer it.
- **Tier 1 — bounded AST whitelist.** Parse a method body but match only a small whitelist of
  well-known call shapes in the first N statements, with no variable tracking across calls (e.g.
  inline `validate()`, `abort()`, `response()->json([…])`). Economical for broadly-used idioms;
  adopt selectively, and always degrade gracefully + log when the shape isn't matched.
- **Tier 2 — full type-flow / dataflow.** Tracking values across calls, into services, through
  conditionals. **Refused** — fragile, expensive, never complete. Where only Tier 2 would close a
  case, that case is by definition the authoring attribute's job.

Consequences worth knowing:

- **Responses are return-type-shaped.** Where an app *types* its returns (or annotates them),
  response-schema fidelity is strong; where the shape exists only at runtime, the generator emits
  little. "Type your returns or annotate them to get response schemas" is the honest framing.
- An attribute is **healthy** when it supplies something the code cannot express (a runtime shape,
  a human description, an intentional override) and a **smell** when it merely re-states something
  a Tier 0/1 read could have inferred.

The live worklist of inference measures, current gaps, and fixes is the repository's GitHub Issues
(label `spec`) and the **Roadmap** project — not a doc in this tree.
