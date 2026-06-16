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
(`tier-0/1/2`, `area:*`). The full label catalog (so you needn't re-query `gh label list` per PR):

- **Kind:** `bug`, `spec` (planning issue).
- **Tier:** `tier-0` (reflection & signatures), `tier-1` (bounded AST whitelist), `tier-2` (refused).
- **Area:** `area:core` (extraction/pipeline), `area:lint`, `area:plugins`, `area:requests`,
  `area:params` (query/path/header), `area:responses`, `area:cli`, `area:security`,
  `area:multi-spec`, `area:survey`.

Start each work session by opening a **draft PR**:

1. Branch from `main` (`feat/…`, `fix/…`, or `chore/…`).
2. `git commit --allow-empty` so the branch has a diff, then push.
3. `gh pr create --draft` with a proper title, the implementation plan as the body, the relevant
   labels, `Closes #<issue>`, and Moritz as reviewer.

The issue stays the durable record of *what* and *why*; the PR body is *how*. If the
implementation deviates from the plan, record the decision and its reasoning as a **PR comment**
(and edit the issue if the spec itself changed). Mirror progress on the Roadmap project's Status
field (Todo → In progress → In review → Done).

Merge policy: **squash-merge into `main`**, gated on green CI (`tests`, `vendor/bin/pint --test`,
PHPStan) and Moritz's review; keep history linear. (No merge queue.)

## Architecture

The codebase splits into four namespaces:

- `Contracts\` — public extension surface (interfaces like `Plugin`,
  `RequestSchemaResolver`, `RefSchemaResolver`, `QueryParameterResolver`,
  `PrimaryResponseResolver`, `ErrorResponseResolver`, `SpecStage`, `RouteFilter`).
- `Support\` — internal infrastructure (generator pipeline + stages, spec
  resolution, inclusion evaluator, visibility resolver, extraction primitives —
  including the plugin-agnostic `Support\Extraction\ValidationRulesToSchema`
  rule→schema mapper and `Support\Extraction\FakerExampleSynthesiser`, shared by
  Core and the convention plugins).
  PHPDoc/type parsing lives here: `Support\PhpDoc\DocBlockParser` +
  `Support\Types\TypeNodeResolver`, built on `phpstan/phpdoc-parser` +
  `symfony/type-info` — the `phpdocumentor`/`reflection-docblock` stack was dropped.
  Treat as `@internal`; not a stable extension point.
- `Plugins\` — bundled plugins. Five ship: **Core** (`Plugins\Core\`) is the
  **Core Plugin** — bundled extraction/processing strategies (FormRequest extractor,
  error-envelope strategies, paginator response resolver, standard-response extractor,
  default query-parameter resolver, route introspection); registers itself as
  `Plugins\Core\CorePlugin`. **SpatieData** and **ApiResources** are enabled by
  default in `config/openapi.plugins`; **QueryBuilder** and **Fractal** are present
  but commented out (each requires opting into a third-party package).

### Generation pipeline

`OpenApiServiceProvider` wires everything. The flow (realized as the ordered `SpecStage`
pipeline registered by `BaselineRegistration` — see Registry and plugins):

1. `RouteIntrospector` walks Laravel routes (after applying `RouteFilter`s), producing an
   `ActionDescriptor` per route. `DocCommentParser` extracts summary/description/`@throws`.
2. `OperationBuilder` builds each operation by running every resolver/extractor registered in
   the `OpenApiRegistry`: query-parameter resolvers, request-schema resolvers (`RequestBodyExtractor`),
   primary-response resolvers, `SecurityExtractor`, and `UriParametersExtractor`. (Standard
   error responses are inferred separately by the `ErrorResponseInferenceStage`, not here.)
3. `ComponentSchemaRegistry` is the shared `$ref` pool for reusable Data-class schemas.
4. `OpenApiGenerator` assembles the final OpenAPI 3.1 document (YAML or JSON).

### Registry and plugins

`OpenApiRegistry` (in `Registry\`) is the extension point. The **entire stage order lives in one
place** — `Support\Generator\BaselineRegistration::assemble()` — as a single top-to-bottom sequence
of `addStage` calls: pre-plugin baseline stages (`RootStage` → `PathsStage` →
`ErrorResponseInferenceStage`), then each plugin in the given order (Core first), then post-plugin
stages (`ComponentsStage` flush → `SecurityStage` → `OverridesStage` → `TransformersStage`); it then
registers the baseline + `config/openapi.lint.rules` lint rules and the error-envelope resolver, and
finally calls `OpenApiRegistry::seal()`. `assemble()` stays plugin-agnostic (it lives in `Support\`):
the `OpenApiServiceProvider` registry factory closure owns only the Laravel/config glue and passes
the plugin list (`[CorePlugin::class, ...config('openapi.plugins')]`), config rules, and resolved
envelope class in as **class-strings**. A plugin implements `Contracts\Registry\Plugin` and registers
resolvers, extractors, error-response factories, payload class markers, lint rules, and additional
`SpecStage`s. `FormRequest` request bodies are handled by Core directly; Spatie Data classes are
handled by the SpatieData plugin.

The `ComponentsStage` **flush runs after the plugin loop**, so a late stage that contributes
schemas (e.g., the SwaggerPhp harvester) registers them on `ComponentSchemaRegistry` like any other
contributor and gets dedup + schema-transformer dispatch — no direct `$document->components` writes.

`SpecPipeline` is a **pure executor**: it loops `registry->stages` and applies each, nothing else.
The terminal precedence `baseline+plugin stages → OverridesStage (config escape hatch) →
TransformersStage (user code)` is now **positional**, not structural — it holds because all
registration funnels through `assemble()` before the terminal `addStage` calls, and `seal()`
enforces that funnel by rejecting out-of-band `addX()` on the built registry
(`RegistrySealedException`, marked unchecked in `phpstan.neon`).

### Lint subsystem (`src/Lint/`)

`SpecTreeBuilder` converts the generated document into a domain tree (`Tree/*Node`).
`SpecTreeWalker` walks it; each `Rules/*` rule implements one or more visitor interfaces
(`Rules/Visitors/*Rule`) and emits `Finding`s into a `FindingsCollector`. `RuleRegistry` holds
the active rules with config-driven severity overrides. `SuppressionCollector` reads
`#[IgnoreLint]` attributes. Each lint rule has a stable string ID (e.g., `operation.id-missing`).

### Service lifecycle

All pipeline classes are bound as **scoped** singletons (not regular singletons). Octane resets
scoped bindings between requests, so each generation run gets fresh instances —
`ComponentSchemaRegistry` and `ExampleFileLoader` carry mutable per-run state and would corrupt
concurrent runs otherwise. `reset()` methods exist but are redundant under the scoped lifecycle.

## Conventions

- Every PHP file has a strict-types declaration.
- `src/Support/` and `src/Contracts/` must not depend on any plugin or third-party convention
  package — plugin-specific code belongs in `src/Plugins/`.
- `src/Plugins/Core/` holds **only concrete strategies** that participate in the Core Plugin
  (extractors, envelope strategies, the default query-parameter resolver, route
  introspection, etc.). Infrastructure shared across plugins goes in `src/Support/`.
- **The Core Plugin exists only to understand vanilla Laravel code patterns** (FormRequests,
  API Resources, validation rules). The package must stay fully functional with Core disabled —
  just without the smarts to read those structures. So config-driven, plugin-agnostic
  infrastructure (e.g., the `openapi.overrides` escape hatch and its lint rules) belongs in the
  general package (`src/Support/`, `src/Lint/Rules/`, registered by `BaselineRegistration` or
  `SpecPipeline`), **never** in `src/Plugins/Core/` or gated behind a plugin.
- The lint rule catalog in `docs/linting.md` (between the `lint-rule-catalog` markers) is
  hand-maintained — add a row when you add a rule. `openapi:lint --list` is the live source.
- Classes intended only for internal use (not part of the documented extension
  surface) should carry an `@internal` PHPDoc tag.
- Behaviour changes need test updates, an update to the relevant page under `docs/`
  if observable (see `docs/README.md` for the page index), and a `CHANGELOG.md`
  entry under `[Unreleased]`.
- Authoring attributes live in `src/Attributes/`; they are the escape hatch for cases
  convention cannot derive.
- Do not use abbreviations in class names, method names, or variable names. The codebase favors
  verbosity for clarity.
- Code comments — keep them short, and write them for the next reader:
  - Comment the **why** (intent, rationale, a non-obvious decision or edge case), never the **how**.
    Delete comments that merely restate what the code plainly does.
  - No references to implementation process: no plan steps, tier numbers, phase numbers, or issue
    numbers. An issue reference is acceptable only when the code does something genuinely unusual
    for a reason that issue documents.
  - No em-dashes in comments; reword with a comma, colon, parentheses, or a sentence break.
  - Section/folding separators use `// region <title>` / `// endregion`, not banner-style rules.
  - DocBlock bodies open with a concise one-line summary; add a longer description only when needed,
    separated by a blank line. Never alter or remove the type-bearing tags (`@param`, `@return`,
    `@var`, `@throws`, `@template`, etc.) — they are load-bearing for PHPStan and extraction.
  - Classes, methods, and properties relevant to end users or plugin developers (the `Contracts\`,
    `Attributes\`, and registry surface) get a docblock saying what they are *for* — not
    `getFoo() gets the foo` — but never ramble or pad with prose.
- Write modern PHP 8.4: constructor property promotion, property hooks, asymmetric visibility, 
  readonly, enums, first-class callables, named arguments where they aid clarity, `match` over 
  `switch`, typed properties everywhere.
- Soft line-width limit of 100 characters; wrap rather than overrun where it stays readable.

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
