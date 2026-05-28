# Changelog

All notable changes to this project are documented here.

## [Unreleased]

### Added
- Bundled PHPStan extension at `extension.neon` that catches attribute misuses at edit time, before the spec is generated. Auto-registered via `phpstan/extension-installer`; otherwise included manually via `vendor/radiergummi/laravel-openapi/extension.neon`. Fourteen rules ship: `openapi.link.bothOperationTargets`, `openapi.link.missingOperationTarget`, `openapi.example.bothValueAndFile`, `openapi.example.missingValueOrFile`, `openapi.expose.onlyAndExcept`, `openapi.hide.onlyAndExcept`, `openapi.visibility.hideExposeConflict` (unconditional pairs only — env-scoped cases stay with the runtime `visibility.hide-expose-conflict`), `openapi.security.publicAndSecuredConflict`, `openapi.response.duplicateStatus`, `openapi.responseHeader.duplicate`, `openapi.response.refAndSchema` (both `ref` and `schema` set on a `#[Response]` — `schema` wins, `ref` is silently dropped), `openapi.field.rangeOrdering` (literal `min* > max*` on a `FieldAttribute` subclass), `openapi.queryParam.requiredWithDefault` (`required: true` together with a `default:` — the default makes the parameter implicitly optional), and `openapi.exceptionResponse.nonThrowable` (`#[ExceptionResponse]` on a class that doesn't implement `Throwable` — the attribute is silently ignored). Identifiers use camelCase because PHPStan's identifier grammar forbids dashes; the runtime counterparts (where they exist) keep kebab-case. See [`docs/linting.md`](docs/linting.md#static-checks-phpstan).
- Lint rule `response.ref-unresolvable` (level 0) — flags `#[Response(ref: SomeClass::class)]` where no registered `RefSchemaResolver` can resolve the class (e.g. a Spatie `Data` class while the SpatieData plugin is disabled, or a class no convention recognises). The generator silently drops the body in this case — the response is emitted with no content and no broken `$ref` for `ref.broken` to catch — so the failure was previously invisible until you inspected the output. Implemented as a `PreBuildRule` mirroring `spec.unknown-reference`. To support it, `RefSchemaResolver` gains a side-effect-free `canResolve(string $class): bool` companion to `resolveRef()` so the rule can test resolvability without building a component schema.
- `SpecStage` plugin extension point. Plugins can now contribute document-level
  transformations via `OpenApiRegistry::addStage()`, alongside the existing
  resolver and rule registration surfaces. The pipeline runs core stages
  (root, paths, components, security), then any plugin-registered stages, then
  the terminal transformer stage.
- New `openapi:diff:config` artisan command reports drift between the published `config/openapi.php` and the package default — flags added keys, removed keys, and changed default values.
- Auto-synthesised examples for fields without an authored example, using a targeted Faker map keyed by format (`email`, `uuid`, `uri`, etc.) and field-name suffix (`*_email`, `*_url`, etc.). Strict lowest-priority fallback — authored sources always win. Disabled per spec via `config('openapi.examples.synthesise') = false`. Deterministic via `config('openapi.examples.faker_seed')`. `fakerphp/faker` is an optional `require-dev` dependency that degrades to "no example" when absent.
- Field-attribute descriptions now recognise three inline directives — `@example <value>`, `@no-example`, `@enum a, b, c` — letting authors declare examples and enum domains without a separate attribute. `@enum` tokens are coerced by lexical shape (so `@enum 200, 404, 500` yields ints, not strings). An explicit `example:` / `enum:` argument on the attribute always wins over the directive — including when the explicit value is `null`, which suppresses the directive's value.
- Faker example synthesis now also runs for Spatie `Data` class properties, matching the behaviour previously limited to `FormRequest` fields so a `Data` class and its equivalent `FormRequest` produce consistent example slots.
- New `SelfDocumentingRule` interface lets custom Laravel validation rule objects declare their own schema constraints (`description`, `type`, `format`, `pattern`, `enum`, `minLength`/`maxLength`/`minimum`/`maximum`) instead of being dropped to a `rule.unknown` lint finding. A second lint rule ID, `rule.invalid-enum-value`, fires when such a rule returns a non-scalar enum entry; the offending value is dropped and the rest of the documentation still applies.
- `#[Tag]` attribute now accepts a `BackedEnum` case in addition to a string, so consumers can centralise tag taxonomies as an enum.
- Lint rule `response.success-empty-body` (level 2) — flags 2xx responses (other than 204/205/304) that declare no body schema. Catches the silent footgun where a controller with no return type produces a `200` with empty content, breaking client codegen. HEAD operations are skipped.
- Lint rule `request-body.schema-degraded` (level 1) — emitted by `SchemaFromFormRequest` when instantiating a FormRequest or calling its `rules()` method throws. Previously the failure was only visible as a single log warning; the placeholder schema landed in the spec silently. The finding surfaces in `openapi:lint` (and in CI) with the exception message and the FormRequest's file/line.
- `config('openapi.error_envelope')` config key with four presets (`none`, `laravel`, `rfc7807`, `json-api`) selecting the body shape of standard error responses.
- `ErrorDescriptor` and `ErrorResponse` value objects in `Radiergummi\OpenApi\Core\Errors\`.
- Optional `exception` key on `middleware_responses` entries, carrying the canonical thrown exception per middleware so resolvers can branch on exception class.
- `#[Summary]` and `#[Description]` attributes. **On controllers / operations**
  they're standalone alternatives to `#[Operation(summary: …, description: …)]`
  for the common case of overriding just one of those fields. Precedence:
  method `#[Summary]` → method `#[Operation(summary)]` → method docblock →
  class `#[Summary]` → class `#[Operation(summary)]`. Anything written on the
  method — including the docblock — beats class-level attributes. Class-level
  placement is for `__invoke` (single-action) controllers, where it outranks
  the class docblock. **On a Spatie `Data` class or Eloquent `JsonResource`
  class** the same attributes set the component schema's `title` /
  `description`.
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
- Restructured namespaces to clarify the public extension surface vs. internal infrastructure: extension-point interfaces moved to `Contracts\` (`Plugin`, `RequestSchemaResolver`, `RefSchemaResolver`, `QueryParameterResolver`, `PrimaryResponseResolver`, `ErrorResponseResolver`, `SpecStage`, `RouteFilter`, `SelfDocumentingRule`); internal infrastructure moved to `Support\` (`OpenApiRegistry`, generator pipeline + stages, spec resolution, inclusion evaluator, visibility resolver, extraction primitives, `ConfigDiffer`); the lint subsystem moved out of `Core\Lint\` to top-level `Lint\`. `Core\` now exclusively holds the **Core Plugin's** concrete strategies: error envelopes (`Core\Errors\`), extractors (`Core\Extractors\`), the default query-parameter resolver (`Core\Resolvers\`), the paginator schema factory (`Core\Pagination\`), the Faker example synthesiser (`Core\Examples\`), route introspection (`Core\Routing\`), and `Registration`. No behaviour change; pre-1.0 namespace cleanup.
- The `type:` argument on field and header attributes (`#[QueryParam]`, `#[RequestField]`, `#[ResponseField]`, `#[Header]`, `#[ResponseHeader]`, plus the plugin attributes `#[ResourceField]`, `#[TransformerField]`, `#[AllowedFilter]`) is now typed as the OpenAPI primitive-type literal union (`'array'`, `'boolean'`, `'integer'`, `'null'`, `'number'`, `'object'`, `'string'`) via the `OpenApiPrimitiveType` PHPStan alias. `#[ResourceField]` / `#[TransformerField]` additionally accept a `class-string` for nested `$ref`s. PHPStan now flags a misspelled scalar type (e.g. `type: 'int'`) at the call site. No runtime behaviour change.
- Tightened PHPDoc types across authoring attributes (`#[Summary]`, `#[Description]`, `#[Operation]`, `#[Tag]`, `#[Webhook]`, `#[Response]`, `#[ExceptionResponse]`, `#[Header]`, `#[ResponseHeader]`, `#[Link]`, `#[Security]`, `#[Hide]`, `#[Expose]`, `#[Spec]`, `#[IgnoreLint]`, `#[Deprecated]`, `#[ExternalDocs]`, `#[Discriminator]`, `#[RequestBody]`, `#[BaseExample]` / `#[Example]` / `#[ResponseExample]`, the `FieldAttribute` family, and the plugin attributes `#[ResourceField]`, `#[TransformerField]`, `#[AllowedFilter]`). Names, descriptions, identifiers, formats, schemes, scope strings, tag entries, environment filter entries, and similar slots are now typed `non-empty-string`; length/item constraints (`minLength`, `maxLength`, `minItems`, `maxItems`) are typed `int<0, max>`. PHPStan now flags empty strings and negative lengths passed to these attributes at the call site. No runtime behaviour change.
- Renamed `ErrorResponseFactory` → `ErrorResponseResolver`. Method renamed to `resolveErrorResponse(ErrorDescriptor): ?ErrorResponse`. `OpenApiRegistry::addErrorResponseFactory()` → `addErrorResponseResolver()`.
- `StandardResponsesExtractor` calls the resolver chain per status code (instead of once per operation) so each status can carry a distinct body shape.
- For body-bearing envelopes (`laravel`, `rfc7807`, `json-api` and any custom resolver that returns content/headers/links), error responses are inlined per operation instead of being shared via `components.responses.<Name>`. This avoids first-write-wins collisions when two operations at the same status need different bodies (e.g. `ValidationException` vs `UnprocessableEntityHttpException` at 422, where the two would otherwise silently share whichever schema registered first). Shared component schemas referenced *inside* the inlined response (e.g. `#/components/schemas/Error`) are still reused; only the response wrapper is inlined. The `none` envelope (the default) still emits a single `components.responses.<Name>` per known status.
- `StandardResponsesExtractor::resolveBody()` now defends against a misbehaving `ErrorResponseResolver`: if one throws, a `errors.resolver-failed` finding is emitted and the chain continues so a single bad resolver no longer aborts an entire generation run.
- `StandardResponsesExtractor` now accepts throwable *interfaces* (e.g. `\Throwable`) as the exception class on a `@throws` line. Previously only `class_exists()` was checked, which silently dropped interfaces from `ErrorDescriptor::exceptionClass` and blocked later concrete `@throws` on the same status from filling the slot.
- `StandardResponsesExtractor::buildResponse()` no longer overrides the curated default description when a resolver returns an empty-string `description`. OpenAPI 3.1 requires `response.description` to be non-empty; only non-empty resolver descriptions override the default.
- `ComponentSchemaRegistry::registerNamed()` now reserves the key in the basename-collision index so a later user-class registration with the same basename (e.g. an app `App\Errors\Error` Data class while the Laravel envelope holds `Error`) is disambiguated via the normal namespace-prefixing rule instead of silently overwriting the named schema.
- `openapi:generate` now rejects unrecognised `--format` values with a
  non-zero exit and an explicit error message. The previous behaviour silently
  fell back to YAML for anything other than `json`. Covered by
  `GenerateCommandTest::it rejects unsupported --format values with a clear error`.
- `ExamplesTest`'s validity check now boots each flavor, regenerates the
  document in-memory via `OpenApiGenerationOrchestrator`, and runs
  swagger-php's `Analysis::validate()` directly. The previous version only
  parsed the committed YAML and asserted on the top-level keys, which was
  redundant with the snapshot-equality test.
- Auto-wired pipeline classes now carry the `#[Scoped]` container attribute
  (`Illuminate\Container\Attributes\Scoped`) and self-register on first resolve;
  the matching one-arg `$this->app->scoped(X::class)` calls in
  `OpenApiServiceProvider` are gone. Closure-form bindings remain only where the
  binding needs config values, factory methods, registry-derived arrays, or
  decorated wrappers reflection cannot supply.
- `SkipNovaRoutes`, `SkipTelescopeRoutes`, `SkipIgnitionRoutes`,
  `SkipPassportRoutes`, `PayloadParameterScanner`, and `SpecRegistry` read
  their config values through `#[Config]` parameter attributes
  (`Illuminate\Container\Attributes\Config`) instead of `config()` calls in a
  service-provider closure. The `Skip*Routes::fromConfig()` static factories
  are gone — the container resolves these classes directly.
- The seven naming-convention lint rules (`OperationIdNamingInconsistent`,
  `FieldNameNamingInconsistent`, `PathSegmentNamingInconsistent`,
  `ParameterNameNamingInconsistent`, `TagNameNamingInconsistent`,
  `HeaderNameNamingInconsistent`, `ComponentNameNamingInconsistent`) each read
  their `openapi.lint.style.*` config key via `#[Config]` on the constructor
  parameter; `AbstractNamingRule` accepts both an `IdentifierCase` enum
  (test-friendly) and the raw config string. `VisibilityResolver` follows the
  same pattern for `openapi.visibility.default`.
- `SuppressionCollector` now takes `OpenApiRegistry` directly and reads
  `payloadClasses()` itself; the indirection list comes from `#[Config]`.
- The `EventDispatchingFindingsCollector → LoggingFindingsCollector` decorator
  chain is now assembled by the container via `#[Scoped]` on both classes
  plus `#[Give(LoggingFindingsCollector::class)]` on the decorator's `$inner`
  param (which breaks the otherwise circular interface resolution). A
  one-line interface alias in the service provider maps `FindingsCollector`
  to the decorator so Testbench environments — which skip the framework's
  `resolveEnvironmentUsing()` bootstrap step that `#[Bind]` needs — also work.
- `IdentifierCase` gained a `fromConfig()` static factory mirroring
  `VisibilityMode::fromConfig()`; `AbstractNamingRule` uses it instead of
  inlining the enum coercion, and the cached `$pattern` property is gone —
  `$case->pattern()` is a pure `match`, so the caching was buying nothing.
- `VisibilityAttributeNoOp` now takes a `VisibilityResolver` and reads
  `defaultMode()` from it instead of re-running `config('openapi.visibility.default')`
  through `VisibilityMode::fromConfig()` on every route check.
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
- Internal refactor of `OpenApiGenerator`: document assembly now runs through a
  `SpecPipeline` composed of typed stages (`RootStage`, `PathsStage`,
  `ComponentsStage`, `SecurityStage`, `TransformersStage`). No behaviour
  change. `OpenApiExtensions` (static-callable transformer surface) is
  unchanged.

### Documentation
- Document exception → response resolution precedence in `docs/config.md` (new
  *Exception-response precedence* section) and cross-link from `docs/recipes.md`.
  Clarify `middleware_responses` keyed by Laravel middleware alias and that it
  only fills statuses no `@throws`-derived response already supplied. Fix the
  inverted "short name first, then FQCN" comment in `config/openapi.php` —
  actual lookup is FQCN first, short name second. Clarify
  `lint.enabled_rules: null` semantics in the config comment.
- `meta.no-suppression-reason` finding now shows the actual rule ID being
  silenced (`#[IgnoreLint('rule.id', reason: '…')]`) instead of a generic
  placeholder.
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
- `#[Deprecated]` authoring attribute (in `Radiergummi\OpenApi\Attributes`) — symmetric to
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
