# Changelog

All notable changes to this project are documented here.

## [Unreleased]

### Added
- Two new example flavors. `examples/api-resources/` isolates the Laravel
  `JsonResource` convention (output-side only; no FormRequest or Data class).
  `examples/fractal/` exercises `league/fractal` with `#[FractalResponse]` and
  class-level `#[TransformerField]` declarations on the transformer. Both are
  registered in `Examples\Shared\Flavors`, asserted by `ExamplesTest`
  (snapshot + OpenAPI 3.1 validity + clean lint), and runnable via
  `composer examples:api-resources` / `composer examples:fractal`.
- `tests/Unit/Lint/LintRouteFilterTest.php` covers the `--diff` flag in
  isolation by stubbing the git-shelling protected methods. Asserts the
  no-diff baseline, explicit-ref usage, default-ref resolution, and the
  config-touched fallback that bypasses per-descriptor filtering.
- `tests/Unit/Core/Generator/OperationBuilderTest.php` exercises
  `OperationBuilder::build` directly: baseline 200 response, `#[Response(201)]`
  primary override, multi-2xx `#[Response]` merging, `#[Header]` parameter
  emission, and `#[ExternalDocs]` plumbing.
- `EventsTest` now covers the `SkipReason::GlobalFilter` branch of
  `RouteSkipped` (alongside the existing `Visibility` and `SpecMembership`
  cases). Registers an inline `RouteFilter` via `openapi.filters` and asserts
  the event fires with the right reason and summary.
- Observability events. The generator and linter dispatch four Laravel events for use as
  read-only notification hooks (mutation still belongs to `OpenApiExtensions` transformers):
  `SpecGenerationStarted`, `SpecGenerationCompleted` (carries the assembled document and
  duration), `RouteSkipped` (carries the route, spec, `SkipReason`, and inclusion summary),
  and `LintFindingEmitted` (fires from any `FindingsCollector::emit()` — covers both
  extractor-emitted findings during generation and rule-emitted findings during lint runs).
  See [`docs/extensions.md`](docs/extensions.md#events).
- Multi-spec support: `config('openapi.specs')` lets one app emit multiple OpenAPI documents,
  partitioned by URL `prefix`, `middleware`, or controller `namespace`. New `#[Spec]` attribute
  pins a route to specific specs explicitly. New `openapi:why` command explains per-route, per-
  spec inclusion; `openapi:generate --explain` prints the same decisions for every (route × spec).
  Lint now runs per spec (`--spec=` narrows; pre-build rules always run). Three new pre-build
  rules: `spec.unknown-reference`, `spec.route-orphaned`, `spec.config-orphaned`. See
  [`docs/multi-spec.md`](docs/multi-spec.md).
- `Radiergummi\OpenApi\Core\Lint\LintRunner` — reusable service that orchestrates one
  lint run from a structured `LintOptions` value object and returns a `LintResult` value
  object (findings + threshold level + exit code). Extracted out of `LintCommand` so the
  lint pipeline is unit-testable without driving the artisan command and reusable from
  programmatic entry points (custom CLI wrappers, HTTP endpoints).
- `Radiergummi\OpenApi\Core\Lint\LintRouteFilter` — separates the --path / --diff
  descriptor-filtering logic (including default-branch detection via
  `git symbolic-ref refs/remotes/origin/HEAD`) into a composable service.
- Console tests for `openapi:generate` (`tests/Unit/Console/GenerateCommandTest.php`)
  covering the configured output path, explicit path-argument override, `--format=json`,
  stdout sink (`path: '-'`), and missing-output-directory failure. The previous coverage
  exercised the command only indirectly via `Kernel::call` in `ExamplesTest`.
- Unit tests for `LintRunner` (`tests/Unit/Lint/LintRunnerTest.php`) covering happy
  path, --no-suppress, config-driven level fallback, --only allowlist filtering,
  config-driven --skip merging, and `--level=max` resolution.

### Changed
- `SchemaFromFormRequest` now takes a `Psr\Log\LoggerInterface` constructor
  argument instead of reaching for `Illuminate\Support\Facades\Log`, matching
  the sibling pipeline classes (`PaginatorResponseResolver`,
  `SchemaFromDataClass`). The warning message changed from
  "[OpenAPI] Schema introspection failed for FormRequest …" to
  "SchemaFromFormRequest failed for …" for consistency with the sibling
  classes.
- `GenerateCommand`, `ClearCommand`, and `WhyCommand` now use the
  `#[Signature]` / `#[Description]` attribute pair (already used by
  `LintCommand`) instead of the `$name` / `$description` properties plus
  `configure()`. Argument and option names continue to be exposed as
  `ARGUMENT_*` / `OPTION_*` constants on each command class.
- `examples/generate.php` now passes the output path via `--output` (the
  documented option name). The previous `path` positional was rejected by
  Symfony's argument parser after the `path` arg was renamed to `spec` in
  the multi-spec refactor.
- `docs/config.md` lists `output_path` under the top-level keys table.
- `docs/attributes.md` lists `#[Spec]` in the operation-level catalog with a
  pointer to `docs/multi-spec.md`.
- `config/openapi.php` no longer references the internal "Plan A2" identifier
  next to the `lint.baseline` placeholder.
- `#[Header]` constructor shape now mirrors `#[ResponseHeader]` (minus `status`). New
  optional arguments: `format` (passed through to the schema) and `deprecated` (passed
  through to the parameter). Argument order is now `name, description, type, format,
  example, required, deprecated` — the previous order was `name, description, required,
  type, example`. Existing call sites use named arguments and are unaffected.
- Route exclusion now lives entirely in `InclusionEvaluator`. `RouteIntrospector` no longer
  takes a filter list and unconditionally yields every Laravel route; vendor-route skippers
  (Telescope/Nova/Ignition/Passport) and any `config('openapi.filters')` entries are applied
  at the evaluator stage. Consequence: every exclusion — including vendor routes — now
  produces a `RouteSkipped` event, a `trace` entry visible to `openapi:why`, and a
  `SkipReason::GlobalFilter` on the decision. The lint pipeline pre-filters descriptors via
  the new `InclusionEvaluator::passesGlobalFilters()` to keep vendor routes out of pre-build
  rules and the tree walk.
- **`spatie/laravel-data` is now a soft dependency.** Moved from `require` to
  `require-dev`; the `SpatieDataPlugin::register()` body is guarded by
  `class_exists(\Spatie\LaravelData\Data::class)` and silently no-ops when the package
  is absent. `OpenApiServiceProvider::registerSpatieDataPlugin()` is guarded similarly,
  while the Core FormRequest bindings (`PayloadParameterScanner`,
  `SchemaFromFormRequest`, `FormRequestRequestSchemaResolver`, `RequestBodyExtractor`,
  `StandardResponsesExtractor`, `ExampleFileLoader`) moved out of that method into a new
  `registerRequestSchemas()` so they survive without Spatie installed. Consumers using
  Spatie Data: `composer require spatie/laravel-data` and everything works as before.
- `LintCommand` shrank from ~700 LOC to ~150 LOC. The body of `handle()` now parses CLI
  options into a `LintOptions`, hands off to `LintRunner`, and renders the resulting
  `LintResult` through the chosen `Formatter`. No behaviour change.
- `--diff` no longer hardcodes `develop` as the upstream branch. The default ref is now
  derived from git itself: `git symbolic-ref refs/remotes/origin/HEAD` first, then the
  first existing local branch among `main`, `master`, `trunk`, then `HEAD~1`. The
  `--diff infra-touched` detection also drops the host-project-specific paths
  (`app/Support/OpenApi/`, `app/Providers/OpenApiServiceProvider.php`) — only the
  published OpenAPI config (`config/openapi.php`) triggers the full-route-set fallback.
- `config/openapi.php` defaults tightened: `info.version` is now
  `env('API_VERSION', '1.0.0')` instead of the hardcoded `'0.1.0'` (consumers who
  publish the config no longer ship `0.1.0` by accident), and `lint.baseline` is now
  `null` instead of `base_path('openapi-baseline.json')` (the existing comment already
  documented this as the disable sentinel; the default is now aligned).
- Removed `reset()` methods on the scoped pipeline classes — they were redundant under
  the `scoped` container lifecycle (each scope yields a fresh instance) and the
  docblock on `OpenApiServiceProvider` already acknowledged this. Affected classes:
  `OpenApiGenerator`, `OperationBuilder`, `ComponentSchemaRegistry`, `ExampleFileLoader`,
  `ThrowsExtractor`, `RouteIntrospector`. The lint-rule `Resettable` interface is
  unaffected — `SpecTreeWalker` still calls `Rule::reset()` before each walk. Callers
  that need a fresh pipeline mid-scope should call `$app->forgetScopedInstances()`
  (one existing test was migrated to demonstrate the pattern).

### Documentation
- **Documentation restructure.** The single 1,335-line `docs/usage.md` is split
  into per-concept pages under `docs/`: `getting-started.md`,
  `auto-derivation.md`, `request-bodies.md`, `attributes.md`, `recipes.md`,
  `plugins.md`, `linting.md`, `extensions.md`, `plugin-authoring.md`,
  `config.md`, `troubleshooting.md`, `architecture.md`, plus a `docs/README.md`
  index. The README is refreshed with the auto-derivation pitch, a comparison
  table vs. l5-swagger/vyuldashev/Scramble, and links to the new pages.
  `docs/usage.md` is removed; existing deep links to anchors in that file
  need to be updated to point at the new pages.
- `#[ResponseHeader]` attribute is now documented in `docs/attributes.md`
  (previously missing from the catalog).
- Internal `docs/test-cleanup.md` working tracker moved to
  `docs/internal/test-cleanup.md` so user-facing `docs/` only contains
  user-facing pages.

### Added
- `#[Expose]` attribute (`src/Core/Attributes/Expose.php`) — opt routes into the
  generated document when the new hidden-default mode is active. Mirrors
  `#[Hide]` with the same mutually-exclusive `only` / `except` environment
  scoping. `#[Hide]` always wins on conflict.
- `visibility.default` config flag (`config/openapi.php`) — accepts `'public'`
  (the current behaviour) or `'hidden'` (every route excluded unless `#[Expose]`
  applies).
- `visibility.hide-expose-conflict` lint rule (level 1) — reports routes whose
  `#[Hide]` and `#[Expose]` env scopes overlap in the current environment.
- `visibility.attribute-no-op` lint rule (level 2) — reports unconditional
  visibility attributes that have no effect under the active default mode.

- `Radiergummi\OpenApi\Plugins\Fractal\Serializer` enum (cases `DataArray`,
  `ArraySerializer`, `JsonApi`) plus a `serializer:` parameter on
  `#[FractalResponse]` — names the Fractal serializer the endpoint runs at
  runtime so the generated envelope matches it. `FractalEnvelopeFactory` now
  dispatches per serializer: ArraySerializer single is a bare `$ref` (no
  envelope), collection is a top-level array; JsonApi produces resource
  objects `{data: {type, id, attributes: $ref}}` under
  `application/vnd.api+json` with hyphenated `meta.pagination` keys when
  paginated. The default `Serializer::DataArray` is unchanged. Custom
  serializers outside this set still use `#[Response]` to override. (OAPI-052)

- `fractal.transformer-class-missing` lint rule (level 1, registered by
  `FractalPlugin`) — flags `#[FractalResponse]` attributes that name a
  transformer class that does not exist (typos like `BookTrnasformer::class`).
  Surfaces during `openapi:lint` what would otherwise only appear in the
  generation log when `FractalResponseResolver` silently drops the operation's
  response. (OAPI-059)
- `SchemaDescriptor::toSchema(string $defaultType = 'string')` — canonical
  helper that builds a standalone `OA\Schema` from a descriptor and applies the
  OAS 3.1 `type: [..., 'null']` widening (which `toOpenApi()` deliberately
  omits). Used by `CoreQueryParameterResolver` and
  `QueryBuilderParameterResolver`, replacing the duplicated 4-line snippet they
  carried. (OAPI-049)
- Feature test (`DefaultPluginsConfigTest`) asserts the shipped default
  `config/openapi.php` `plugins` array generates a clean document — a typo in
  either commented-out FQCN or an accidental uncomment would now fail in CI.
  (OAPI-057)
- `openapi.security_schemes` config map — registers OpenAPI security schemes by name. Each entry
  is passed through to swagger-php's `OA\SecurityScheme`; the map key becomes `securityScheme`.
  Entries are merged with the Passport-derived `oauth2` / `oauth2ClientCredentials` pair (emitted
  only when Passport is installed and its named routes are registered); config entries win on
  key collision. `#[Security]` now accepts an optional `scheme:` parameter naming which
  configured scheme the requirement targets — existing `#[Security(['scope'])]` usages keep
  working against the project default scheme. (OAPI-042)
- `#[ResponseHeader]` authoring attribute — repeatable on methods/functions, declares a header
  on the response whose `status:` it targets (defaults to 200). Carries `name`, `description`,
  `type`, `format`, `example`, `required`, `deprecated`. Replaces the request-`#[Header]`
  workaround for documenting headers like `Location` on 201 responses. (OAPI-041)
- `DataResponseResolver` (SpatieData plugin) — auto-derives the primary `200 OK` response from a
  Spatie `Data` return type, a `DataCollection<int, Item>` (item class read from the `@return`
  PHPDoc generic), or a `PaginatedDataCollection` / `CursorPaginatedDataCollection` (renders the
  matching paginator envelope). Mirrors the ApiResources plugin's `ResourceResponseResolver`.
  Explicit `#[Response]` attributes still take precedence. (OAPI-040)
- `CoreQueryParameterResolver` — reflects `#[QueryParam]` attributes off controller methods (and
  classes, for shared parameters declared once) and emits OpenAPI query-parameter entries with
  the attribute's full `FieldAttribute` surface (type, format, enum, default, nullable, bounds).
  Closes the documentation-vs-implementation gap where `#[QueryParam]` existed but was never
  read. (OAPI-039)
- `#[Deprecated]` authoring attribute (in `Radiergummi\OpenApi\Core\Attributes`) — symmetric to
  the PHPDoc `@deprecated` tag and the PHP 8.4 native `#[\Deprecated]` attribute. Targets
  methods, functions, properties, promoted constructor parameters, and class constants. Sets
  `deprecated: true` on the generated operation (method-level) or schema property
  (property / parameter level). (OAPI-043)
- `SkipPassportRoutes` route filter registered by default — Laravel Passport's CRUD endpoints
  (route names under the `passport.*` prefix) are filtered out of generated specs alongside
  Nova / Telescope / Ignition. The filter tolerates Passport being absent. (OAPI-044)
- `examples/` suite: five runnable flavors (vanilla, form-requests, spatie-data, query-builder, combined)
  that all expose the same flights+bookings API and ship a generated `openapi.yaml` snapshot.
  Verified in CI against fresh generation, OpenAPI 3.1 validity, and `openapi:lint`.
- Laravel paginator return types (`LengthAwarePaginator`, `Paginator`, `CursorPaginator`) are
  now documented automatically. The paginated item type is resolved from a `#[ResponseResource]`
  attribute or a `@return Paginator<Item>` PHPDoc generic.
- Eloquent API Resources (`JsonResource` / `ResourceCollection`) are now
  documented automatically via the default-enabled `ApiResourcesPlugin`. Each
  resource declares its output keys with repeatable class-level
  `#[ResourceField]` attributes; single responses emit the `{data}` envelope and
  collections the `{data, links, meta}` envelope. Three lint rules
  (`resource.fields-undeclared`, `resource.field-type-missing`,
  `resource.response-ambiguous`) report incomplete declarations.
- `spatie/laravel-query-builder` filter/sort/include query parameters are now
  documented via the optional `QueryBuilderPlugin` (shipped disabled — uncomment
  it in `config/openapi.php` after installing the package). Endpoints declare
  parameters with `#[AllowedFilter]`, `#[AllowedSort]`, and `#[AllowedInclude]`.
  Two lint rules (`query-builder.params-undeclared`,
  `query-builder.filter-type-missing`) report incomplete declarations.
- `league/fractal` transformer responses are now documented via the optional
  `FractalPlugin` (shipped disabled — uncomment it in `config/openapi.php` after
  installing the package). Transformers declare output keys with
  `#[TransformerField]` and includes with `#[TransformerInclude]`; endpoints bind
  to a transformer with `#[FractalResponse]`, which accepts `collection: true`
  for a flat collection and `paginated: true` for a paginated one (envelope
  includes `meta.pagination` matching Fractal's `IlluminatePaginatorAdapter`).
  Four lint rules (`fractal.response-unbound`, `fractal.fields-undeclared`,
  `fractal.include-transformer-missing`, `fractal.duplicate-key`) report
  incomplete or invalid declarations.
- `openapi.security_default_scheme` config option — names the scheme that
  `#[Security(['scope'])]` (without `scheme:`) and middleware-derived
  `forRoute()` security target by default. Accepts a string (single scheme) or
  a list of strings (multiple OR-alternatives). When unset, resolution falls
  back to Passport's `oauth2` + `oauth2ClientCredentials` pair if installed,
  otherwise the first scheme declared in `openapi.security_schemes`, otherwise
  an empty requirement — preserving the previous behaviour for projects that
  do not opt in. Mixed-scheme projects (Passport + custom bearer) can now set
  this once instead of passing `scheme:` on every `#[Security]`. (OAPI-045)
- `Radiergummi\OpenApi\Core\Lint\ReflectionAttributeCache` — per-walk cache
  attached to `LintContext` that wraps `getAttributes()` bucketing and
  `ReflectionClass` construction. Sibling lint rules that introspect the same
  target class (resource, transformer) or the same operation method now share
  the cache. Resource, Fractal, and QueryBuilder lint rules migrated to use it
  (and to read controller / method attributes through the new
  `ActionDescriptor::actionAttributes()` helpers instead of allocating fresh
  `ReflectionClass` / `getAttributes()` walks per rule). (OAPI-054)

### Changed (breaking)
- `#[Hide]` constructor argument renamed: `environments` → `only`. Also gains
  `except` as an exclusive alternative. Migration: rewrite
  `#[Hide(environments: [...])]` to `#[Hide(only: [...])]`.

### Changed
- `SpecTreeBuilder` now resolves `allOf`-composed schema properties when
  building `FieldNode` trees. A schema written as
  `allOf: [{$ref: Base}, {properties: {…}}]` exposes both the
  `$ref`-inherited properties and the local ones in
  `ComponentSchemaNode->fields`, with a cycle guard against recursive `allOf`
  chains; the `required` list is unioned across branches. `oneOf` / `anyOf`
  are deliberately not composed. False positives in
  `schema.required-without-property` and false negatives in
  `schema.enum-type-mismatch` / every other `FieldRule` are now closed for
  `allOf`-composed schemas. (OAPI-038)
- `SecurityExtractor` and `ReturnTypeExtractor` now memoise per-run state on
  the instance: Passport availability (`class_exists` + 3× `Router::has()`),
  the router's middleware-groups snapshot, the parsed
  `openapi.security_schemes` catalogue, and per-reflector
  `genericArgument()` results. Both are bound as scoped singletons so the
  caches reset between requests under Octane. The biggest win is the
  `DocBlockFactory::create()` parse, which is now done once per method
  across all primary-response resolvers that consult the same `@return`
  generic. (OAPI-051)
- `ActionDescriptor` now exposes `controllerAttributes()` /
  `actionAttributes()` helpers that read each reflector's attribute list once
  per descriptor and bucket by attribute FQCN. `OperationBuilder` switched
  every `getAttributes(SomeAttribute::class)` call onto these helpers, so a
  build over `n` routes does `O(2·n)` attribute walks instead of `O(17·n)`.
  No behaviour change; the bucket cache is scoped to the descriptor's lifetime,
  so it carries no Octane-state risk. (OAPI-050)
- `#[ResponseHeader]` now also targets `TARGET_CLASS` and is read off both the
  controller and the action reflector by `OperationBuilder` — shared response
  headers (`X-Request-Id`, `X-RateLimit-Remaining`) can be declared once on the
  controller instead of repeated on every method. Method-level declarations win
  on `(status, name)` collision; declaration order is otherwise preserved. The
  shape now mirrors `#[Header]`. (OAPI-046)
- `SkipPassportRoutes` now exposes a parameterless `fromConfig()` factory and
  is registered through it in `OpenApiServiceProvider`, matching the shape of
  the sibling filters (`SkipNovaRoutes` / `SkipTelescopeRoutes` /
  `SkipIgnitionRoutes`). Behaviour is unchanged — Passport's route-name prefix
  is not user-configurable, so the constructor still takes no parameters; a
  class-level docblock spells that out so the deviation no longer reads as an
  oversight. (OAPI-047)
- `fractal.response-unbound` lint rule moved from level 1 to level 2 (opt-in),
  matching its `query-builder.params-undeclared` sibling. The rule's
  `description()` now spells out the blind spot (`fractal()` helper /
  `Spatie\Fractalistic\Fractal` facade are not detected) so the caveat surfaces
  in `openapi:lint --list-rules` output. Default-level lint runs no longer
  produce a silent zero-findings result that could be mistaken for endorsement
  in Fractal-heavy codebases. (OAPI-060)
- `PaginatorKind::fromClass()` now recognises Spatie's `PaginatedDataCollection`
  and `CursorPaginatedDataCollection` (FQCN-matched to keep Core free of plugin
  imports). `PaginatorResponseResolver` now claims those return types via the
  shared `RefSchemaResolver` chain (with `DataRefSchemaResolver` already in it),
  so `DataResponseResolver` shrank to single `Data` + non-paginating
  `DataCollection<…, Item>`. Paginator-envelope construction now lives in one
  place. (OAPI-048)
- `SchemaFromResource` now takes `Closure(): list<RefSchemaResolver>` to mirror
  the sibling `SchemaFromTransformer`; both sides of the cross-plugin
  construction graph are lazy, closing the latent OOM tripwire for a future
  third `SchemaFromX` + `XRefSchemaResolver` pair. (OAPI-055)
- `FractalResponseResolver`, `ResourceResponseResolver`, and
  `DataResponseResolver` now catch only `ReflectionException` — the documented
  tolerable failure mode. Real bugs (`TypeError`, schema-build logic errors,
  `Error` subclasses) now propagate so they surface in dev rather than
  disappearing into a warning log. (OAPI-058)
- Plugin-suite integration test (`PluginSuiteIntegrationTest`) tightened with
  negative assertions (paginator route is not Fractal-wrapped; resource and
  Fractal routes carry no QueryBuilder params), full Fractal envelope-shape
  coverage (single / collection / paginated), `#[AllowedInclude]` coverage on
  the paginator route, and an included transformer asserted to land as its own
  component schema. (OAPI-056)
- `composer.json` `require-dev` constraint for `league/fractal` loosened from
  `^0.20.2` to `~0.20.2` — explicit about allowing 0.20.x patch updates without
  claiming 0.21 forward compatibility. (OAPI-061)
- `config/openapi.php` Fractal-plugin comment now names both triggers
  (`league/fractal` and `spatie/laravel-fractal`) so users coming in via the
  Spatie wrapper have a signal that they already meet the requirement. (OAPI-062)
- PHPStan now runs at level 8 with `treatPhpDocTypesAsCertain` disabled and is a blocking CI check.
- Document generation now skips routes whose controller class cannot be resolved at introspection time, instead of aborting the entire run with a `ReflectionException`.
- Upgraded core dependencies to current major versions: `zircote/swagger-php` 6, `symfony/type-info` 8, and `phpdocumentor/reflection-docblock` 6.
- `league/fractal`, `spatie/laravel-fractal`, and `spatie/laravel-query-builder`
  are now listed under `suggest` — install the relevant package and uncomment
  the matching plugin in `config/openapi.php` to enable it.

### Fixed
- The `schema.constraints-missing` lint rule now handles OpenAPI 3.1 nullable type arrays (`type: [string, null]`). Previously such schemas caused a `TypeError` and were silently left unchecked.
- The lint spec-tree builder no longer fails when a media type's schema is a plain `$ref` string rather than an inline schema object; such schemas are now handled gracefully.
- Generated documents keep the OpenAPI 3.1 nullable form (`type: ['…', 'null']`). swagger-php 6 defaults its serialisation context to OpenAPI 3.0, which down-converts nullable unions to the removed `nullable` keyword; generation now pins a 3.1 context.
- `#[AllowedFilter(nullable: true)]` now widens the generated `filter[…]` schema to `type: ['…', 'null']`. Previously the `nullable` flag was accepted on the attribute but silently dropped from the wire schema.

## [0.1.0] - 2026-05-18

### Added
- Initial public release: OpenAPI 3.1 generation and documentation linting for Laravel.
- Bundled Spatie Data plugin and FormRequest request-schema support.
- `openapi:generate`, `openapi:lint`, `openapi:clear` artisan commands.
- Config-driven spec endpoint and Scalar playground routes.
