# Plugin Suite Design

**Date:** 2026-05-18
**Status:** Approved
**Workstream:** 1 of 5 in the pre-1.0 publication program (plugins → type safety → tests → docs → example apps)

## Goal

Extend `radiergummi/laravel-openapi` with plugins covering the response and
query conventions broadly used in Laravel API codebases, so the package can
document a typical app without hand-written OpenAPI. Three new plugins plus two
Core additions.

This spec covers only the plugin/Core-convention work. Documentation, the test
suite at large, type-safety cleanup, and example apps are separate workstreams
with their own specs.

## Scope

In scope:

- `ApiResourcesPlugin` — Eloquent API Resources (`JsonResource` / `ResourceCollection`).
- `QueryBuilderPlugin` — `spatie/laravel-query-builder` filter/sort/include parameters.
- `FractalPlugin` — `league/fractal` / `spatie/laravel-fractal` transformers.
- Core: native PHP enum → schema mapping.
- Core: Laravel paginator return types → response envelopes.
- Tests for all of the above (unit + feature), per the existing `SpatieData`
  plugin coverage pattern.

Out of scope (other workstreams):

- Prose documentation in `README.md` / `docs/usage.md` beyond the per-change
  updates CLAUDE.md already mandates.
- Example applications.
- Clearing the PHPStan backlog.

## Design principles

- **No method-body inference.** The generator reads signatures only (OAPI-017).
  API Resources, Fractal transformers, and query-builder calls all define their
  shape in method bodies; the plugins resolve this with **attributes** instead —
  the same "convention + attribute escape hatch" model the package already uses.
- **Core stays convention-agnostic.** `src/Core/` must not depend on any plugin
  or third-party package. Plugin-specific code and attributes live under
  `src/Plugins/<Name>/`.
- **Soft dependencies.** Third-party packages are `require-dev` + `suggest`,
  never hard `require`. A plugin loads only when listed in
  `config('openapi.plugins')`.

## Section 1 — Plugin inventory & placement

Three new plugins, each under `src/Plugins/<Name>/`, mirroring the existing
`SpatieData/` layout. Each implements `Core\Registry\Plugin` and registers its
resolvers, attributes' payload markers, and lint rules.

| Plugin | Directory | Activates on | Registers |
|---|---|---|---|
| `ApiResourcesPlugin` | `src/Plugins/ApiResources/` | return type is a `JsonResource` / `ResourceCollection` subclass, or a method carries `#[ResourceResponse]` | primary-response resolver, ref-schema resolver, lint rules |
| `QueryBuilderPlugin` | `src/Plugins/QueryBuilder/` | controller method carries query-builder attributes | query-parameter resolver, lint rules |
| `FractalPlugin` | `src/Plugins/Fractal/` | method carries `#[FractalResponse]` | primary-response resolver, ref-schema resolver, lint rules |

Enums and paginators are **not** plugins — they are language/framework
primitives and become Core behavior (Section 4).

## Section 2 — Attributes

Plugin attributes live in `src/Plugins/<Name>/Attributes/`. Core attributes
stay in `src/Core/Attributes/`.

### ApiResources

- `#[ResourceField(name, type, ...)]` — repeatable, **class-level** on the
  `JsonResource` subclass. Declares one output key. Class-level (not
  property-level) because a Resource's keys are arbitrary `toArray()` entries,
  not typed class properties.
- `#[ResourceResponse(resource: UserResource::class, collection: bool)]` —
  **method-level**, required only when the return type is generic
  (`JsonResponse`, `AnonymousResourceCollection`). When the method return type
  *is* the concrete Resource class, the signature suffices.

### QueryBuilder

- `#[AllowedFilter(name, type, ...)]` — repeatable, **method-level**; emits a
  `filter[name]` query parameter.
- `#[AllowedSort(fields)]` — **method-level**; emits the `sort` query parameter.
- `#[AllowedInclude(names)]` — **method-level**; emits the `include` query
  parameter.

### Fractal

- `#[TransformerField(name, type, ...)]` — repeatable, **class-level** on the
  transformer.
- `#[TransformerInclude(name, transformer: ..., default: bool)]` — repeatable,
  class-level; models `availableIncludes` / `defaultIncludes`.
- `#[FractalResponse(transformer: ..., collection: bool)]` — **method-level**,
  binds an endpoint to its transformer.

### Nested-object shorthand

`#[ResourceField]` and `#[TransformerField]` accept a **class-string** for
`type:`. When `type` is a class, it is resolved through the existing
ref-schema resolvers and emitted as a `$ref`, so nested Resources / Data
classes / transformers compose without re-declaring their fields.

## Section 3 — Lint rules

Each plugin registers rules with stable prefixed string IDs, using the existing
`Rules/*` + visitor-interface model, config-driven severity overrides, and
`#[IgnoreLint]` suppression.

### ApiResources

- `resource.fields-undeclared` — Resource used as a response but carries no
  `#[ResourceField]` (shape unknown → empty schema). High severity.
- `resource.field-type-missing` — a `#[ResourceField]` without a resolvable
  type. Medium.
- `resource.response-ambiguous` — generic return type with no
  `#[ResourceResponse]`. High.

### QueryBuilder

- `query-builder.params-undeclared` — query-builder attributes expected but
  none declared. Medium.
- `query-builder.filter-type-missing` — an `#[AllowedFilter]` without a type.
  Low.

### Fractal

- `fractal.response-unbound` — endpoint resolves to Fractal output with no
  `#[FractalResponse]`. High.
- `fractal.fields-undeclared` — transformer used with no `#[TransformerField]`.
  High.
- `fractal.include-transformer-missing` — a `#[TransformerInclude]` without a
  transformer class. Medium.

Severity rule of thumb: "shape entirely unknown" defaults high; "missing
polish" defaults low. All overridable via `config('openapi.lint.rules')`.

## Section 4 — Core changes: enums & paginators

These extend Core's type→schema mapping; no new attributes, no plugin.

### Enums

When a type resolves to a native PHP `enum`:

- Backed enum → schema `type:` of `string` or `integer` (matching the backing
  type), `enum:` listing the case backing values.
- Pure enum → `type: string`, `enum:` listing the case names.

Slots into the existing JSON-schema-from-type path.

### Paginators

When a primary-response resolver sees a return type of `LengthAwarePaginator`,
`Paginator`, or `CursorPaginator`:

- Wrap the inner item schema in the matching Laravel envelope:
  - `LengthAwarePaginator` / `Paginator` → `data`, `links`, `meta`.
  - `CursorPaginator` → `data`, `meta` with cursor links.
- The inner type comes from the generic parameter
  (`LengthAwarePaginator<UserResource>`) — a signature, no body inference.

This also fixes paginated `ResourceCollection` results. `docs/known-gaps.md` is
updated to reflect the narrowed OAPI-017 surface.

## Section 5 — Config, dependencies, structure

### Config defaults (`config/openapi.php` → `plugins`)

- `ApiResourcesPlugin` — **enabled by default**. `JsonResource` is Laravel
  core; no third-party dependency; dominant convention.
- `QueryBuilderPlugin`, `FractalPlugin` — **shipped commented-out**; they
  require third-party packages. Users uncomment after installing the package.

### composer.json

Add to `require-dev` (for the test suite) and `suggest` (discoverability),
never to hard `require`:

- `spatie/laravel-query-builder`
- `league/fractal`
- `spatie/laravel-fractal`

### Tests

Mirror the existing `SpatieData` plugin coverage:

- Unit tests: attribute parsing, each resolver, each lint rule in isolation.
- Feature tests: fixture controller + Resource/transformer under
  `tests/Fixtures/`, generate a document, assert emitted schema/parameters.
- Core enum & paginator handling: unit tests in the Core type-mapping tests
  plus one feature test each.

### Per-change obligations (CLAUDE.md)

Each plugin/Core change lands with a `CHANGELOG.md` `[Unreleased]` entry and the
minimal `docs/usage.md` update CLAUDE.md mandates for observable behavior. The
full prose documentation pass is a separate workstream.

## Build sequence

1. Core enums + paginators (no dependencies; benefits later plugins).
2. `ApiResourcesPlugin` (no third-party dependency; default-enabled).
3. `QueryBuilderPlugin`.
4. `FractalPlugin`.
5. composer.json + config defaults wiring.

Each step is independently testable and leaves the suite green.
