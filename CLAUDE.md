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

## Known gaps

See `docs/internal/known-gaps.md` (the gap registry, by `OAPI-###` ID) and
`docs/internal/inference-roadmap.md` (the forward plan to close the planned ones). Notably:
no controller method-body inference (OAPI-017) — the generator reads signatures only;
no Eloquent-model or `@OA`-annotation response-schema inference (OAPI-063 / OAPI-064);
no Sanctum security-scheme auto-derivation, only Passport (OAPI-065); and untyped path
parameters (OAPI-066). (`allOf`-composed schema lint, formerly OAPI-038, is resolved — see
`CHANGELOG.md`.)
