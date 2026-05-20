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
composer lint                    # Laravel Pint — reports style violations
vendor/bin/pint                  # Pint — apply style fixes
composer analyse                 # PHPStan / Larastan, level 8

vendor/bin/pest tests/Feature/Oapi024Test.php          # single test file
vendor/bin/pest --filter "substring of test name"      # single test by name
```

The suite runs on Testbench — no host Laravel app is needed. CI matrix is PHP 8.4/8.5 ×
Laravel 12/13; a PR is mergeable when `tests` is green, Pint reports no violations, and PHPStan
passes. PHPStan runs at level 8 with `treatPhpDocTypesAsCertain: false` and is **CI-blocking** —
`composer analyse` must report no errors.

## Architecture

The codebase splits into a convention-agnostic **Core** (`src/Core/`) and **Plugins**
(`src/Plugins/`) that teach Core about specific packages. One plugin ships: **SpatieData**.

### Generation pipeline

`OpenApiServiceProvider` wires everything. The flow:

1. `RouteIntrospector` walks Laravel routes (after applying `RouteFilter`s), producing an
   `ActionDescriptor` per route. `DocCommentParser` extracts summary/description/`@throws`.
2. `OperationBuilder` builds each operation by running every resolver/extractor registered in
   the `OpenApiRegistry`: query-parameter resolvers, request-schema resolvers, primary-response
   resolvers, `SecurityExtractor`, `StandardResponsesExtractor`, `UriParametersExtractor`.
3. `ComponentSchemaRegistry` is the shared `$ref` pool for reusable Data-class schemas.
4. `OpenApiGenerator` assembles the final OpenAPI 3.1 document (YAML or JSON).

### Registry and plugins

`OpenApiRegistry` is the extension point. `CoreRegistration::register()` runs first (registers
`FormRequestRequestSchemaResolver` and all core lint rules), then each plugin in
`config/openapi.plugins` order, then any `config/openapi.lint.rules` extras. A plugin implements
`Core\Registry\Plugin` and registers resolvers, extractors, error-response factories, payload
class markers, and lint rules. `FormRequest` request bodies are handled by Core directly;
Spatie Data classes are handled by the SpatieData plugin.

### Lint subsystem (`src/Core/Lint/`)

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
- `src/Core/` must not depend on any plugin or third-party convention package — plugin-specific
  code belongs in `src/Plugins/`.
- Behaviour changes need test updates, a `docs/usage.md` update if observable, and a
  `CHANGELOG.md` entry under `[Unreleased]`.
- Authoring attributes live in `src/Core/Attributes/`; they are the escape hatch for cases
  convention cannot derive.

## Known gaps

See `docs/known-gaps.md`. Notably: no controller method-body inference (OAPI-017) — the
generator reads signatures only — and lint rules miss `allOf`-composed schema properties
(OAPI-038).
