# Changelog

All notable changes to this project are documented here.

## [Unreleased]

### Added
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

### Changed
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
