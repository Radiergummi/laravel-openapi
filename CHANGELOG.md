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
- `additionalProperties:` override on the field attributes (`#[ResponseField]`, `#[RequestField]`, `#[QueryParam]`, `#[PathParam]`): set a field's map behaviour to a bool or a typed value schema; applied last, it wins over inferred `array<string, T>` map values. (#345)
- `#[RawSchema]` class-level attribute replaces a payload class's inferred component body with a literal JSON Schema (Spatie Data, API Resource, FormRequest); keywords are bounded to what swagger-php serialises, with unsupported keywords dropped-and-logged and flagged by the new `schema.raw-keyword-unsupported` lint rule. (#344)
- Pagination query parameters from a `->paginate()`/`->simplePaginate()`/`->cursorPaginate()` body call (Tier-1): offset paginators emit `page`/`per_page`, cursor paginators emit `cursor`; an explicit `#[QueryParam]` wins for its name. (#31)
- Success-response model schema inferred from a directly-returned `Model::find()`/`findOrFail()`/`firstOrFail()` call in the controller body (Tier-1), feeding the existing Eloquent model→schema reader; composes with the `findOrFail()` 404. (#97)
- Fractal responses inferred from the `fractal()` helper, the `Spatie\Fractalistic\Fractal` facade, and injected-`Manager` `new Item`/`new Collection` resource construction (Tier-1 body scan), with `item`/`collection` envelopes and `serializeWith(...)` serializer detection; the bare two-argument `fractal($data, new T())` form, a variable transformer, and an unrecognised serializer all degrade with a note, and `#[FractalResponse]` remains authoritative. (#263)
- `schema.class-attribute-conflicts-with-field-attributes` lint rule (advisory): flags a payload/return class carrying a class-level `#[RawSchema]` alongside property-level field attributes (`#[RequestField]`/`#[ResponseField]`), which the raw schema replaces wholesale, leaving the field attributes dead. (#351)
- Regression guard: `#[RawSchema]` documents using the nested-schema keywords `additionalProperties` (as a schema), `patternProperties`, `propertyNames`, `contains`, and `discriminator` produce a document that passes swagger-php's `validate()` (the raw-array-under-a-schema-keyword shape is accepted on both supported majors, 5.8 and 6.x), with the serialised shape pinned by per-keyword assertions. No conversion is needed. (#352)
- `#[ResponseExampleFile('path.json')]` authoring attribute attaches a JSON file's contents as the singular `example` on a response (the auto-derived primary by default, `status:` targets a declared response). Skipped silently for a conventionally bodyless status (204/205/304) or a status with no matching response, and yields to named `#[ResponseExample]`s already on the same media type (`example`/`examples` are mutually exclusive). (#40)
- `openapi:lint --format=markdown` now renders a real GitHub-Flavored Markdown body (a coverage summary line and a `Severity | Rule | Location | Message` findings table) instead of aliasing the CLI tree dump, so the CI coverage-comment recipe can post it verbatim. (#389)
- ApiResources: the return-expression reader now recognises the `X::collect(...)` static resource-collection factory, emitting the same collection response schema as `X::collection(...)`. (#390)
- `openapi:lint --fix` now repairs missing and codegen-unsafe operationIds (synthesizes/sanitizes the `#[Operation(operationId: …)]`), via the new `AddAttribute` fix primitive. (#382)
- `--fix`/`--check` now detect same-node fix conflicts (apply the safe subset, skip + report the rest with a typed reason) and emit a frozen `--check --format=json` fix-run envelope for CI. (#22)

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
- ApiResources: actions that return an API Resource without declaring a return type (relying on convention or a third-party doc attribute) now resolve their response schema via the return-expression reader, instead of emitting no content; the refusal notice is suppressed on the untyped path to avoid a notice per non-resource action. (#391)

### Documentation
- README refreshed for the current feature set (Tier-1 method-body inference, SwaggerPhp plugin) and trimmed to a gentle overview that defers detail to `docs/`.
- New [Migrating from L5-Swagger](docs/migrating-from-l5-swagger.md) guide and [CI integration](docs/ci.md) guide. (#123, #158)
- Documentation restructure: the monolithic `docs/usage.md` is split into focused pages, with an accuracy sweep across the set.
- The query-builder chain reader sees value-object constructors wrapped in fluent modifiers, so `AllowedFilter::exact('healthy')->nullable()` is read as `filter[healthy]` instead of dropped. (#257)
- A `findOrFail()` / `firstOrFail()` lookup in the controller body infers a 404 response, deduped against the route-model-binding 404. (#168)
- Infer error responses from non-2xx `response()->json([...], <4xx/5xx>)` literals in the controller body (Tier-1), carrying the literal body schema inlined per operation; falls back to the configured error envelope when only the status is statically readable. (#238)
- Internal: the API Resource return-expression reader's paginate-method whitelist now routes through the shared `PaginatorKind::fromPaginatingMethod()` enum instead of a duplicated local constant. (#354)
- Eloquent model schemas seed their per-property examples from the model's Laravel factory `definition()` (scalar values only), reseeded deterministically per model from `openapi.examples.faker_seed`; disabled when example synthesis is off or the seed is null. (#36)
- `#[RequestField]` ref support: a class-string `type:` resolves to a `$ref`, and a class-string `items:` on a `type: 'array'` field resolves to `items: { $ref }` — mirroring the response-side `#[ResourceField]`; an unresolvable class degrades to a permissive object. (#150)
- A non-paginator action whose body unconditionally calls `paginate()`/`simplePaginate()`/`cursorPaginate()` now gets the matching paginated response envelope, with a declared item class (`#[ResponseResource(Model::class)]` or `@return Paginator<Item>`). Guarded so it never overrides a response API Resources or Spatie Data would shape — a resource/`Data` return type or a resource-naming `#[ResponseResource]` defers to those plugins. (#353)
- **Fortify plugin** (opt-in): documents Laravel Fortify's headless core-auth endpoints (login/logout/register/password/profile) from a stock-contract table, with request bodies always emitted and response bodies gated on the Fortify response contract being unmodified. (#134)
- Lint rule severity is now a backed `Contracts\Lint\Severity` enum (`Broken`/`Degraded`/`Underspecified`/`Inconsistent`/`Improvable`) instead of a bare int: `Rule::level(): int` becomes `Rule::severity(): Severity` and `Finding::$level` becomes `Finding::$severity`. The `--level`/`config.lint.level` threshold and `severity_overrides` config stay integer-keyed, and JSON output keeps the numeric `level`; an out-of-range `severity_overrides` int now clamps to `Improvable` (the closed scale no longer lets a stray high value suppress a finding). (#366)
- `schema.class-attribute-conflicts-with-field-attributes` now also flags class-level `#[ResourceField]` declarations on a `JsonResource` that carries a class-level `#[RawSchema]`, alongside the property-level `#[RequestField]`/`#[ResponseField]` it already detected. (#374)
- Internal: the attribute-removal lint fixer now describes its change as an AST mutation on a structurally-addressed node; `FixApplicator` clones the syntax tree, applies the removal, and reprints once per file with php-parser's format-preserving printer (byte-identical output, no whole-file reformat). (#368)
- Internal: the swagger-php `--fix` removers (`#[OA\*]` attributes and `@OA\…` docblock annotations) now emit the same AST-mutation operations; docblock removal rebuilds the doc-comment text (or drops the comment entirely) and reprints with the format-preserving printer instead of deleting physical lines. (#368)
- Internal: the byte-splice fix backend (`SourceEdit` and the `FixOperation`/`RemoveLines`/`ReplaceLines`/`InsertBefore`/`ModifyAttribute` classes) is retired; `FixApplicator` is now purely the AST-mutation pipeline and the `Lint\Fix\Ast` operations (`RemoveAttribute`, `SetDocComment`, `SetAttributeArgument`) are the sole fix-operation surface. (#368)
- `openapi:why --fields` explains the source and reason for a route's derived summary, success status code, and tags (rendered as two-column detail rows, e.g. `status … 201 ResourceConventionResolver (store → POST)`), with author overrides shown winning over the convention they superseded. The environment override is `--for-env` (short `-e`); `--env` is reserved by Laravel. Read-only instrumentation; generated output is unchanged. (#178)

## [0.1.0] - 2026-05-18

### Added
- Initial public release: OpenAPI 3.1 generation and documentation linting for Laravel.
- Bundled Spatie Data plugin and FormRequest request-schema support.
- `openapi:generate`, `openapi:lint`, `openapi:clear` artisan commands.
- Config-driven spec endpoint and Scalar playground routes.
