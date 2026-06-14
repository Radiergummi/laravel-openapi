# Changelog

All notable changes to this project are documented here.

## [Unreleased]

### Added
- **Tier-1 controller body inference**: request bodies from inline `validate()`, error responses from `abort()`/`abort_if()`/`abort_unless()`, primary responses from literal `response()->json()`, API Resource shapes from `toArray()`, Fractal `transform()` literals, and `spatie/laravel-query-builder` fluent chains; query parameters from `$request->*()` accessor reads, and inline `validate()` keys on GET/HEAD routes as query parameters. (#9, #10, #12, #13, #14, #15, #16)
- **SwaggerPhp plugin** harvests hand-authored `#[OA\*]` / `@OA` annotations into the spec, with `migration.*` lint rules and `--fix` to delete annotations inference already covers. (#17, #122, #196)
- Response schemas inferred from Eloquent model metadata (`$casts`, `$hidden`, `$appends`, migration columns, framework timestamps) and from concrete API Resources resolved off the controller's return expression. (#18, #108, #250)
- A backed enum referenced anywhere emits a single reusable component `$ref`'d at every site. (#35)
- Array-shape PHPDoc (`array{foo: string}`) resolves to an object schema instead of a bare `array`. (#127)
- Discriminated request bodies via `#[RequestBody(discriminator: …)]` + repeatable `#[RequestVariant]` (OpenAPI `oneOf` + `discriminator`).
- New authoring attributes/options: `#[SchemaName]` (pins the component key), `#[RequestField]` on actions, `#[Summary]`/`#[Description]`, `#[Deprecated]` (and `@deprecated` PHPDoc), `#[Expose]`, `#[Security]` (repeatable), `#[ResponseHeader]`, `#[ResponseField]`/`#[ResourceField]` enum and nested-collection support, and `#[Tag]`/field `enum:` accepting backed enums. (#38, #110, #126, #128, #205)
- Richer Tier-0 inference: route-model-bound and enum-backed path parameters typed from the model key / backed enum, custom-key bindings (`{post:slug}`), nested/wildcard validation keys, `mimes:`/`mimetypes:` file uploads, `Route::any()` verb fan-out, and conventional success status + summary for resourceful actions.
- Security inference: Sanctum auto-derivation, `abilities:`/`ability:` middleware scopes, `openapi.security_schemes`, `openapi.security_default_scheme`, and `openapi.security_middleware_map` config keys.
- `SelfDocumentingRule` interface lets custom validation rules contribute schema constraints (description, type, format, pattern, example).
- Faker-backed example synthesis for FormRequest and Spatie Data fields, with `@example` / `@no-example` / `@enum` inline directives.
- Multi-spec support: `config('openapi.specs')` emits multiple documents; per-spec `info` overrides deep-merge.
- `openapi.error_envelope` config key with `none` / `laravel` / `rfc7807` / `json-api` presets for standard error response bodies.
- `openapi.overrides` config escape hatch for operation-level fields, with `overrides.unknown-field` / `overrides.unused` lint rules.
- Served docs playground can render with Swagger UI as an alternative to Scalar.
- Bundled PHPStan extension catches attribute misuse at edit time, before generation.
- Lint coverage: `openapi:lint` reports a documentation-coverage metric and gates CI via `--min-coverage` / `--max-findings`; repeatable, target-addressable `--format=<format>[:<target>]` with a new `lcov` format; `--fix`/`--check` auto-fix; sharper route-scoping flags (`--uri`, `--diff`). New rules include `query-builder.filter-duplicate`, `resource.response-empty`, `operation.return-type-missing`, `response.success-empty-body`, `request-body.schema-degraded`, `response.ref-unresolvable`, and the visibility/fractal families.
- `SpecStage`, `ErrorResponseContributor`, and `ResourceTargetLocator` plugin extension points; observability events for generation and linting.
- Default route filters for first-party operational packages (Horizon, Pulse, Nova, Telescope, Ignition, Passport, and the library's own routes via `SkipSelfRoutes`).
- `openapi:diff:config` command reporting drift between the published config and the package default.
- String-keyed array/collection properties on Spatie `Data` classes (`array<string, T>`) emit `additionalProperties` (a map) instead of a bare array. (#334)
- `#[CookieParam]` authoring attribute documenting an `in: cookie` parameter read off the request at runtime. (#335)
- `x:` vendor-extension passthrough on the field attributes (`#[ResponseField]`, `#[RequestField]`, `#[QueryParam]`, `#[PathParam]`, `#[CookieParam]`): attach `x-*` specification extensions to a field's schema, co-located with the field (the field-level analogue of `openapi.overrides`). (#336)

### Changed
- `spatie/laravel-data` is now a soft dependency (moved from `require` to `require-dev`); Fractal and query-builder packages are opt-in.
- Widened dependency support: `zircote/swagger-php` `^5.8 || ^6.1.2` and `symfony/type-info` `^7.3 || ^8.0`; PHPDoc parsing now uses `phpstan/phpdoc-parser` + `symfony/type-info`.
- Auto-derived operation tags come from the controller's pluralised short class name; operation-ID derivation is selectable via `openapi.operation_id_strategy`.
- Pipeline order is expressed as a single ordered sequence in `BaselineRegistration::assemble()`; resolver fault isolation is centralized in `ResolverFaultBoundary`; pipeline classes are scoped singletons (Octane-safe).
- Restructured namespaces to separate the public extension surface (`Contracts\`) from internal infrastructure (`Support\`).
- `PaginatorKind` recognises Spatie's `PaginatedDataCollection`; `DataResponseResolver` handles union return types as `oneOf`.
- The inline `response()->noContent($status)` body-scan reads an explicit literal/named 2xx status (body-less); a non-literal or non-2xx status degrades. (#328)
- A typed `UploadedFile` property on a Spatie `Data` class (transitively, through nested Data classes) emits a `multipart/form-data` body with a `format: binary` field, matching the FormRequest file-rule path; the `multipart.file-without-multipart` lint rule is reframed as a contradiction guard that fires only when a `#[RequestBody]` override forces a non-multipart media type onto a file-carrying payload. (#333)

### Fixed
- Lint now surfaces top-level and field-level bare `oneOf` / `anyOf` schemas across components, responses, and request bodies. (#294, #318)
- Edit-time PHPStan rules resolve positionally-written attribute arguments, not just named ones. (#322)
- `ErrorResponseInferenceStage` catches `Exception` only, so misbehaving resolvers throwing `Error`/`TypeError` fail loudly. (#308)
- `ResourceConventionResolver` gates on the pinned emitted verb on multi-verb routes. (#307)
- Generation no longer crashes on stale route definitions, closure middleware, FormRequests reading runtime state, malformed import-type docblocks, bare Rule objects, or non-instantiable controllers.
- Schema correctness fixes: untyped descriptors no longer default to `type: string` (#291); JSON list casts no longer typed as `object` (#249); `DateTimeInterface` maps to date-time strings (#148); array fields always emit `items`; path parameters always `required`; OpenAPI 3.1 nullable type arrays preserved on swagger-php 6; encrypted casts mapped like their plain counterparts; class-form object casts recognised (#252).
- `#[PublicEndpoint]` suppresses the middleware-derived 401/403 (#259); `#[IgnoreLint]` scoping fixed at class, payload, and schema level; query parameters deduplicated across resolvers; `operationId`s sanitised for codegen-safe identifiers (#42).
- `#[AllowedFilter(nullable: true)]` widens the generated `filter[…]` schema to a nullable type array.
- `--path` / `--diff` no longer leak findings for out-of-scope routes (#50).
- `ValidationRulesToSchema` maps additional rules (`multiple_of`, Email/Exists/Unique/NotIn objects, wildcard keys) it previously dropped (#83).

### Documentation
- README refreshed for the current feature set (Tier-1 method-body inference, SwaggerPhp plugin) and trimmed to a gentle overview that defers detail to `docs/`.
- New [Migrating from L5-Swagger](docs/migrating-from-l5-swagger.md) guide and [CI integration](docs/ci.md) guide. (#123, #158)
- Documentation restructure: the monolithic `docs/usage.md` is split into focused pages, with an accuracy sweep across the set.
- The query-builder chain reader sees value-object constructors wrapped in fluent modifiers, so `AllowedFilter::exact('healthy')->nullable()` is read as `filter[healthy]` instead of dropped. (#257)

## [0.1.0] - 2026-05-18

### Added
- Initial public release: OpenAPI 3.1 generation and documentation linting for Laravel.
- Bundled Spatie Data plugin and FormRequest request-schema support.
- `openapi:generate`, `openapi:lint`, `openapi:clear` artisan commands.
- Config-driven spec endpoint and Scalar playground routes.
