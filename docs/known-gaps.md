# Known Gaps

These are intentionally unresolved limitations of the package. They are documented here so
consumers know what to expect and where the rough edges are. Contributions addressing either are
welcome.

| ID | Title | Status |
|---|---|---|
| [OAPI-017](#oapi-017--no-method-body-inference) | No method-body inference | Open |
| [OAPI-038](#oapi-038--lint-rules-miss-allof-composed-schema-properties) | Lint rules miss `allOf`-composed schema properties | Closed |
| [OAPI-039](#oapi-039--queryparam-attribute-has-no-core-resolver) | `#[QueryParam]` attribute has no Core resolver | Closed |
| [OAPI-040](#oapi-040--no-dataresponseresolver-for-spatie-data-return-types) | No `DataResponseResolver` for Spatie Data return types | Closed |
| [OAPI-041](#oapi-041--no-response-header-authoring-attribute) | No response-header authoring attribute | Closed |
| [OAPI-042](#oapi-042--security-cannot-name-a-scheme-securityschemes-hard-coded-to-passport) | `#[Security]` cannot name a scheme; security schemes hard-coded to Passport | Closed |
| [OAPI-043](#oapi-043--no-deprecated-authoring-attribute) | No `#[Deprecated]` authoring attribute | Closed |
| [OAPI-044](#oapi-044--no-shipped-route-filter-for-laravel-passport) | No shipped route filter for Laravel Passport | Closed |
| [OAPI-045](#oapi-045--security-default-scheme-resolution-privileges-passport) | `#[Security]` default scheme resolution privileges Passport | Closed |
| [OAPI-046](#oapi-046--responseheader-cannot-be-declared-at-class-level) | `#[ResponseHeader]` cannot be declared at class level | Closed |
| [OAPI-047](#oapi-047--skippassportroutes-lacks-the-constructor--fromconfig-shape-of-its-siblings) | `SkipPassportRoutes` lacks the constructor + `fromConfig()` shape of its siblings | Closed |
| [OAPI-048](#oapi-048--dataresponseresolver-duplicates-paginator-envelope-logic-from-paginatorresponseresolver) | `DataResponseResolver` duplicates paginator-envelope logic from `PaginatorResponseResolver` | Closed |
| [OAPI-049](#oapi-049--corequeryparameterresolver-and-querybuilderparameterresolver-duplicate-schema-build-code) | `CoreQueryParameterResolver` and `QueryBuilderParameterResolver` duplicate schema-build code | Closed |
| [OAPI-050](#oapi-050--operationbuilder-issues-many-getattributesclass-calls-per-route) | `OperationBuilder` issues many `getAttributes($class)` calls per route | Closed |
| [OAPI-051](#oapi-051--per-route-reflection-and-config-reads-are-not-memoized) | Per-route reflection and config reads are not memoized | Closed |
| [OAPI-052](#oapi-052--fractal-serializer-assumed-to-be-dataarrayserializer) | Fractal serializer assumed to be `DataArraySerializer` | Closed |
| [OAPI-053](#oapi-053--fractalresponse-unbound-does-not-detect-fractal-helper-or-facade-usage) | `fractal.response-unbound` does not detect `fractal()` helper / facade usage | Open (won't-fix) |
| [OAPI-054](#oapi-054--lint-rules-do-not-share-reflection-results-within-a-walk) | Lint rules do not share reflection results within a walk | Closed |
| [OAPI-055](#oapi-055--schemafromresource-still-uses-the-eager-ref-resolver-array) | `SchemaFromResource` still uses the eager ref-resolver array | Closed |
| [OAPI-056](#oapi-056--plugin-suite-capstone-is-a-shallow-smoke-test) | Plugin-suite capstone is a shallow smoke test | Closed |
| [OAPI-057](#oapi-057--no-test-exercises-the-shipped-default-plugins-array) | No test exercises the shipped default `plugins` array | Closed |
| [OAPI-058](#oapi-058--plugin-response-resolvers-silently-degrade-on-any-throwable) | Plugin response resolvers silently degrade on any `Throwable` | Closed |
| [OAPI-059](#oapi-059--no-lint-rule-for-fractalresponse-naming-a-missing-transformer-class) | No lint rule for `#[FractalResponse]` naming a missing transformer class | Closed |
| [OAPI-060](#oapi-060--conservative-plugin-lint-rules-ship-at-default-level-despite-known-blind-spots) | Conservative plugin lint rules ship at default level despite known blind spots | Closed |
| [OAPI-061](#oapi-061--leaguefractal-constraint-over-pinned-to-0202) | `league/fractal` constraint over-pinned to `^0.20.2` | Closed |
| [OAPI-062](#oapi-062--disabled-plugin-comments-in-configopenapiphp-name-only-one-suggested-package) | Disabled-plugin comments in `config/openapi.php` name only one suggested package | Closed |

---

## OAPI-017 — No method-body inference

**Status:** Open

**Symptom:** The generator derives request bodies and responses from *signatures* — a typed
request DTO (a Spatie Data class or a `FormRequest`) and a typed return value. It does not read
the *body* of a controller method. Anything expressed only inside the method body is invisible:

- inline `$request->validate([…])` calls,
- `abort(403, '…')` / `abort_if(…)`,
- `response()->json([…])` with an ad-hoc array,
- additional payload merged at runtime.

This is the largest coverage gap compared to tools that parse method bodies (e.g. Scramble). It
affects controllers that don't follow the typed-DTO / typed-return convention.

**Workarounds:**

- Move inline validation into a Spatie Data class or a `FormRequest` so the generator can see it.
- Type the return value, or use `#[ResponseResource]` / `#[Response]` to declare responses
  explicitly.
- Use the authoring attributes (`#[RequestBody]`, `#[QueryParam]`, `#[Response]`) to document
  anything that cannot be expressed as a type.

**Why it's open:** Body inference would require a PHP-Parser pass and a bespoke type-flow analysis
— a significant build. The attribute-driven escape hatches cover the same cases at the cost of an
annotation, so this is deferred rather than scheduled.

**Update — narrowed scope:** the generator now reads one PHPDoc tag, `@return Foo<Bar>`,
to recover the item type of a paginated return value (`LengthAwarePaginator`, `Paginator`,
`CursorPaginator`). This is the only place the generator looks beyond a native signature; it
still never reads method bodies. A paginator return type whose item type is declared by
neither `#[ResponseResource]` nor a `@return` generic falls back to a bare `200 OK` and is
reported in the generation log.

**API Resources:** the `ApiResourcesPlugin` derives the response schema for `JsonResource`
subclasses from `#[ResourceField]` attributes declared on the resource class, not from the
resource's `toArray()` body. This is consistent with the no-method-body-inference rule: the
generator reads declarations, not runtime behaviour. Resources without `#[ResourceField]`
attributes are documented with an empty schema and reported by the `resource.fields-undeclared`
lint rule.

**QueryBuilder:** the optional `QueryBuilderPlugin` derives `filter[…]` / `sort` / `include`
query parameters from `#[AllowedFilter]`, `#[AllowedSort]`, and `#[AllowedInclude]` attributes on
the controller method — not from the `QueryBuilder::for(…)->allowed…()` chain in the method
body. Detection for the `query-builder.params-undeclared` lint rule is conservative: it keys off
an injected `Spatie\QueryBuilder\QueryBuilder` parameter (matched by FQCN string, no package
load required) rather than guessing intent from method bodies. The common
`QueryBuilder::for(...)` pattern that does not inject the builder is therefore not flagged.

**Fractal:** the optional `FractalPlugin` derives the response shape from class-level
`#[TransformerField]` / `#[TransformerInclude]` attributes on the transformer and a method-level
`#[FractalResponse]` binding on the endpoint — not from the transformer's `transform()` method
body or from `Manager`/`fractal()` calls in the controller body. The detection in
`fractal.response-unbound` is conservative for the same reason as QueryBuilder's: it keys off an
injected `League\Fractal\Manager` parameter (FQCN-string match, no package load required) and
does not flag the `fractal()` helper or `Spatie\Fractalistic\Fractal` facade usage inside method
bodies. See OAPI-053.

---

## OAPI-038 — Lint rules miss `allOf`-composed schema properties

**Status:** Closed

**Resolved in:** the `main` branch. `SpecTreeBuilder` now indexes component schemas by name at
the start of `build()` and the property-collection path in `buildFields()` walks each schema's
`allOf` branches before its local declarations:

- A `{$ref: '#/components/schemas/X'}` branch is resolved via the component index and the
  target schema's properties (plus its own `allOf` chain) are merged in.
- An inline branch contributes its own properties directly.
- `oneOf` / `anyOf` are left untouched — they represent alternatives, not composition.
- `required` lists are unioned across all branches.
- Cycles in the `$ref` graph (`A` ⇄ `B`) are broken with a visited-set guard keyed by component
  name; the local declarations on each visited schema still contribute, but the chain stops the
  moment the same component is encountered twice.

`schema.required-without-property` and every other `FieldRule` therefore see the same merged
property set the runtime would see at validation time. The new
`tests/Unit/Lint/Tree/SpecTreeBuilderTest.php` covers a `$ref` branch, an inline branch, and
the cyclic case.

---

## OAPI-039 — `#[QueryParam]` attribute has no Core resolver

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `CoreQueryParameterResolver` now ships in
`src/Core/Generator/` and is registered by `CoreRegistration::register()`. It reflects
`#[QueryParam]` off the controller method (and the class, for shared parameters declared once)
and emits `OA\Parameter` entries with `in: 'query'`, the attribute's name, and a schema derived
from its `FieldAttribute` surface (type, format, enum, default, nullable, numeric/string bounds).
The vanilla flavor's `#[QueryParam('page', ...)]` and `#[QueryParam('per_page', ...)]` now
appear as documented query parameters on `GET /flights`.

---

## OAPI-040 — No `DataResponseResolver` for Spatie Data return types

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `DataResponseResolver` now ships in
`src/Plugins/SpatieData/` and is registered by `SpatieDataPlugin::register()`. It detects three
return-type shapes: a `Data` subclass becomes a `$ref` to the Data component schema; a
`DataCollection<int, Item>` becomes an array of `$ref`s (item class read from the `@return`
PHPDoc generic); and `PaginatedDataCollection<…>` / `CursorPaginatedDataCollection<…>` produce
the corresponding length-aware or cursor paginator envelope via `PaginatorSchemaFactory`. The
`examples/spatie-data/` and `examples/combined/` flavors were migrated to rely on the resolver;
the `#[Response(ref: SomeData::class)]` workarounds on Data-returning actions were removed.
Explicit `#[Response]` attributes still take precedence over the auto-derived response.

---

## OAPI-041 — No response-header authoring attribute

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `#[ResponseHeader]` now ships in
`src/Core/Attributes/`. It is repeatable on methods and functions, carries `name`, `status`
(defaults to 200), optional `description`, `type`, `format`, `example`, `required`, and
`deprecated`, and is reflected by `OperationBuilder` onto the `headers` map of the response
whose status it targets. The existing `#[Header]` (request-side) is unchanged. The
form-requests flavor's `POST /flights` now declares `Location` on its 201 via
`#[ResponseHeader(name: 'Location', status: 201, type: 'string', format: 'uri', …)]`.

---

## OAPI-042 — `#[Security]` cannot name a scheme; security schemes hard-coded to Passport

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. Two changes ship together:

1. New `openapi.security_schemes` config map. Each entry maps a scheme name to the OAS 3.1
   security-scheme shape and is passed through to swagger-php's `OA\SecurityScheme` unchanged.
   `SecurityExtractor::buildSchemes()` now merges these entries with the Passport-derived
   `oauth2` / `oauth2ClientCredentials` pair (which is itself emitted only when Passport is
   installed and its named routes are registered). Config entries win on key collision.
2. `#[Security]` gained an optional `scheme:` parameter naming which configured scheme the
   requirement targets. When omitted the requirement falls back to the project default
   (Passport's pair if available, otherwise the first config-declared scheme), so
   `new Security(['scope'])` keeps working.

The `examples/combined/` showcase was migrated to register a plain bearer-JWT scheme via config
and reference it through `#[Security(['flights:write'], scheme: 'bearer')]`; the generated
snapshot now carries a `bearer` entry under `components.securitySchemes` alongside the
Passport-derived pair, and the write endpoints reference `security: [{bearer: […]}]`.

---

## OAPI-043 — No `#[Deprecated]` authoring attribute

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. The package now ships
`Radiergummi\OpenApi\Core\Attributes\Deprecated`, valid on methods, functions, properties,
promoted constructor parameters, and class constants. Placed on a controller method it sets
`deprecated: true` on the generated operation; placed on a Data-class property or constructor
parameter it sets `deprecated: true` on the schema property. The attribute is symmetric to the
PHPDoc `@deprecated` tag (still honoured) and to the PHP 8.4 native `#[\Deprecated]` (still
honoured on operations) — authors can pick whichever path fits the call site. The
`examples/spatie-data/` showcase was migrated to the attribute form; the legacy `aircraft`
property still emits `deprecated: true` in the snapshot.

---

## OAPI-044 — No shipped route filter for Laravel Passport

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `SkipPassportRoutes` now ships in
`src/Core/Routing/Filters/` and is registered by default in `config/openapi.php` alongside
`SkipNovaRoutes` / `SkipTelescopeRoutes` / `SkipIgnitionRoutes`. Host apps using Passport get
its CRUD endpoints filtered out of the generated spec out of the box; the filter tolerates
Passport being absent by matching only routes whose name starts with `passport.`.

---

## OAPI-045 — `#[Security]` default scheme resolution privileges Passport

**Status:** Closed

**Resolved in:** the `main` branch. The two parallel default paths in `requirementForScopes()`
were collapsed into a single `defaultSchemeNames()` lookup, and a new
`openapi.security_default_scheme` config option lets mixed-scheme projects override which
scheme(s) `#[Security(['scope'])]` (without `scheme:`) and middleware-derived `forRoute()`
target. Resolution order, in one place:

1. Explicit `scheme:` argument — wins.
2. `openapi.security_default_scheme` (string, or a list for multiple OR-alternatives).
3. Passport's `oauth2` + `oauth2ClientCredentials` pair, if Passport is installed and its
   routes are registered. Preserves the historic Passport-only behaviour.
4. The first scheme declared in `openapi.security_schemes`.
5. `[]` (empty requirement).

Projects with both Passport and a `bearer` scheme can now do
`'security_default_scheme' => 'bearer'` once and have it apply to every authenticated operation
without touching call sites.

---

## OAPI-046 — `#[ResponseHeader]` cannot be declared at class level

**Status:** Closed

**Resolved in:** the `main` branch. `#[ResponseHeader]` now accepts `TARGET_CLASS` in addition
to `TARGET_METHOD | TARGET_FUNCTION`, and `OperationBuilder::applyResponseHeaders()` walks
both the controller and the action reflector — mirroring the shape of `#[Header]`. Shared
response headers (`X-Request-Id`, `X-RateLimit-Remaining`) can now be declared once on the
controller and apply to every action. Method-level declarations win on `(status, name)`
collision; declaration order is otherwise preserved.

---

## OAPI-047 — `SkipPassportRoutes` lacks the constructor + `fromConfig()` shape of its siblings

**Status:** Closed

**Resolved in:** the `main` branch. `SkipPassportRoutes` now exposes a parameterless
`fromConfig()` factory and is registered through it by `OpenApiServiceProvider`, matching the
shape of `SkipNovaRoutes` / `SkipTelescopeRoutes` / `SkipIgnitionRoutes`. A class-level
docblock spells out the deviation — Passport's route-name prefix is not user-configurable, so
the constructor still takes no parameters; the factory is preserved for symmetry so the
provider does not need a special case. Behaviour is unchanged.

---

## OAPI-048 — `DataResponseResolver` duplicates paginator-envelope logic from `PaginatorResponseResolver`

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `PaginatorKind::fromClass()` now
recognises Spatie's `PaginatedDataCollection` and `CursorPaginatedDataCollection`
(matched by FQCN string to keep Core free of plugin imports — both delegate
`toArray()` to the underlying Laravel paginator with `Data`-transformed items,
so the envelope shape is identical). `PaginatorResponseResolver` now claims
those return types via the shared `RefSchemaResolver` chain (which
`DataRefSchemaResolver` already participates in). `DataResponseResolver` shrank
to single `Data` + non-paginating `DataCollection<…, Item>` — it now returns
`null` for paginated Spatie collections so the core resolver claims them.

---

## OAPI-049 — `CoreQueryParameterResolver` and `QueryBuilderParameterResolver` duplicate schema-build code

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `SchemaDescriptor` now exposes
`toSchema(string $defaultType = 'string'): OA\Schema` — the canonical place to
build a standalone `OA\Schema` from a descriptor, including the `nullable` →
OAS 3.1 `type: [..., 'null']` widening that `toOpenApi()` deliberately omits.
Both `CoreQueryParameterResolver` and `QueryBuilderParameterResolver` now call
`$attribute->descriptor()->toSchema()` and have shed the duplicated 4-line
sequence plus the explanatory comment.

---

## OAPI-050 — `OperationBuilder::build()` issues many `getAttributes($class)` calls per route

**Status:** Closed

**Resolved in:** the `main` branch. `ActionDescriptor` now owns two helper methods —
`controllerAttributes(class-string)` and `actionAttributes(class-string)` — that read
`$reflector->getAttributes()` (no filter) once per reflector and bucket the result by
attribute FQCN into an `array<class-string, list<ReflectionAttribute>>` map, cached on the
descriptor by `spl_object_id`. Every `read*` / `apply*` helper in `OperationBuilder` now reads
from that map instead of calling `getAttributes(SomeAttribute::class)` directly. Generation
runs over `n` routes now do `O(2·n)` attribute walks instead of `O(17·n)`. The cache is
naturally scoped to the descriptor's lifetime, so it carries no Octane-state risk.

---

## OAPI-051 — Per-route reflection and config reads are not memoized

**Status:** Closed

**Resolved in:** the `main` branch. Three call sites are now memoised:

- `SecurityExtractor::passportAvailable()` caches the `class_exists` + three `Router::has()`
  lookups on first call (`?bool` field).
- `SecurityExtractor::forRoute()` reads `Router::getMiddlewareGroups()` through a new
  `middlewareGroups()` helper that caches the result (`?array` field).
- `SecurityExtractor::configSchemes()` caches the parsed `openapi.security_schemes` config
  catalogue on first call.
- `ReturnTypeExtractor::genericArgument()` caches the result of the `DocBlockFactory::create()`
  parse + generic-argument extraction per `spl_object_id($reflector)`; the cache distinguishes
  "uncached" from "cached null" via `array_key_exists`. A route that two primary-response
  resolvers both consult now triggers one phpDocumentor parse instead of two.

Both extractors drop their `readonly` modifier to hold the per-run state; both are bound as
scoped singletons so the cache resets between requests under Octane.

---

## OAPI-052 — Fractal serializer assumed to be `DataArraySerializer`

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `#[FractalResponse]` now carries a
`serializer:` parameter (default `Serializer::DataArray`) naming the Fractal serializer the
endpoint runs at runtime. `FractalEnvelopeFactory` dispatches per serializer:

- `Serializer::DataArray` — unchanged: `{data}` / `{data: [...]}` / `{data: [...], meta.pagination}`.
- `Serializer::ArraySerializer` — single = bare `$ref`, collection = top-level array; paginated
  keeps the `data` wrapper because Fractal's `IlluminatePaginatorAdapter` wraps regardless.
- `Serializer::JsonApi` — `{data: {type, id, attributes: $ref}}` (single), array of resource
  objects (collection), `meta.pagination` with hyphenated keys (paginated). JsonApi responses
  are emitted under `application/vnd.api+json` instead of `application/json`.

Custom serializers (project-specific subclasses, anything outside the three named cases) still
fall back to `#[Response]` for an explicit schema declaration — the override path the gap
already pointed at, now narrowed to the rare cases the enum does not cover.

---

## OAPI-053 — `fractal.response-unbound` does not detect `fractal()` helper or facade usage

**Status:** Open (won't-fix by design)

**Symptom:** The `fractal.response-unbound` lint rule keys off an injected
`League\Fractal\Manager` parameter (a body-free signal — see OAPI-017). It does not flag methods
that produce Fractal output via the `fractal()` helper or the `Spatie\Fractalistic\Fractal`
facade, both of which are invoked inside the method body without injecting a `Manager`
parameter. Endpoints written that way will silently produce undocumented Fractal output.

**Workaround:** Declare `#[FractalResponse]` on those endpoints explicitly. Once declared, the
plugin emits the response envelope normally regardless of how the runtime Fractal call is made.

**Why it's open:** Detecting the helper or facade requires reading method bodies, which is
forbidden under OAPI-017. The narrow `Manager`-parameter signal is the conservative escape from
that trade-off — accepted misses over false positives. The rule's `description()` names the
blind spot explicitly so it surfaces in `openapi:lint --list-rules` output, and the rule ships
at level 2 (opt-in; see OAPI-060) so users do not read silence at the default lint level as
endorsement. Closing this gap further would require lifting OAPI-017 itself.

---

## OAPI-054 — Lint rules do not share reflection results within a walk

**Status:** Closed

**Resolved in:** the `main` branch. Two collaborators ship together:

- `ActionDescriptor` exposes `controllerAttributes()` / `actionAttributes()` (the OAPI-050
  helpers); rules that key off the controller method now read attributes through these
  helpers instead of `$descriptor->method->getAttributes(...)`. Sibling rules on the same
  operation share a single bucket build.
- A new `ReflectionAttributeCache` is attached to `LintContext` (default-constructed per
  context) and exposes `classAttributes(class-string, class-string)` plus
  `reflectionClass(class-string)`. Rules that introspect a target class (resource class,
  transformer class) call into the cache instead of allocating a fresh `ReflectionClass`
  per rule.

Migrated rules:

- `ResourceFieldsUndeclared`, `ResourceFieldTypeMissing` — share the resource class's
  `ReflectionClass` + `#[ResourceField]` bucket.
- `FractalFieldsUndeclared`, `FractalIncludeTransformerMissing`, `FractalDuplicateKey`,
  `FractalTransformerClassMissing`, `FractalResponseUnbound` — share the action reflector's
  `#[FractalResponse]` bucket (via `ActionDescriptor`) and the transformer class's
  `#[TransformerField]` / `#[TransformerInclude]` buckets (via the cache).
- `QueryBuilderParamsUndeclared`, `QueryBuilderFilterTypeMissing` — share the action
  reflector's `#[AllowedFilter]` / `#[AllowedSort]` / `#[AllowedInclude]` buckets.

---

## OAPI-055 — `SchemaFromResource` still uses the eager ref-resolver array

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `SchemaFromResource` now
takes `Closure(): list<RefSchemaResolver>` mirroring the sibling
`SchemaFromTransformer`, and `registerApiResourcesPlugin()` wraps the registry
walk in a memoised closure — same shape as the Fractal binding. Both sides of
the construction graph are lazy now, so the cycle the program-tracker decision
mitigated is closed against future plugins that ship a `SchemaFromX` +
`XRefSchemaResolver` pair referencing either existing builder.

---

## OAPI-056 — Plugin-suite capstone is a shallow smoke test

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. The capstone now carries
five focused scenarios with negative assertions:

- The paginator route is verified to use the *core* flat envelope (`data`,
  `total`, `per_page`, `current_page`) and to *not* carry Fractal's
  `meta.pagination` shape.
- The resource and Fractal routes are now individually asserted to carry no
  `filter[*]` / `sort` / `include` parameters — catching any QueryBuilder
  bleed onto sibling operations.
- `#[AllowedInclude(['owner'])]` is now exercised on the paginator route and
  asserted in `parameters[].name`.
- The Fractal route now covers all three envelope shapes: single (`{data: $ref}`,
  no array type on `data`), collection (`{data: [..], no meta}`), and paginated
  (`{data: [..], meta: {pagination: …}}`).
- The fixture's `SuiteWidget` carries typed `id: int` / `name: string`, the
  resource has a proper `toArray()`, and an additional included transformer
  (`SuiteOwnerTransformer`) is asserted to land in `components.schemas` to
  exercise the cross-transformer ref chain.

---

## OAPI-057 — No test exercises the shipped default `plugins` array

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch.
`tests/Feature/Plugins/DefaultPluginsConfigTest.php` calls
`app(OpenApiGenerator::class)->generate()` without touching
`config('openapi.plugins')`, first asserting the live config matches the
shipped `config/openapi.php` `plugins` array. Two controller methods carrying
`#[AllowedFilter]` and `#[FractalResponse]` respectively are then asserted to
*not* produce QueryBuilder query parameters or a Fractal `data` envelope — so
a typo in either commented-out FQCN or an accidental uncomment would fail the
test.

---

## OAPI-058 — Plugin response resolvers silently degrade on any `Throwable`

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `FractalResponseResolver`,
`ResourceResponseResolver`, and `DataResponseResolver` now catch only
`ReflectionException` — the documented tolerable failure mode (a class that
disappears between attribute resolution and schema build). Real bugs
(`TypeError` from a malformed attribute constructor, schema-build logic
errors, `Error` subclasses) now propagate so they surface in dev rather than
disappearing into a warning log. The warning message was updated to mention
"reflection failure" specifically so the surfaced log line reflects what was
caught.

---

## OAPI-059 — No lint rule for `#[FractalResponse]` naming a missing transformer class

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. The new
`fractal.transformer-class-missing` rule (level 1, registered by
`FractalPlugin::register()`) walks every operation's `#[FractalResponse]`
attribute and emits a finding when `class_exists($transformer)` returns false.
Typos like `BookTrnasformer::class` now surface during `openapi:lint` instead
of just disappearing into the generation-log warning that
`FractalResponseResolver` already produced.

---

## OAPI-060 — Conservative plugin lint rules ship at default level despite known blind spots

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. The decision was option (a) from the gap's
"Why it's open" list — push both blind-spot rules below the default `lint.level => 1`, so users
opt in explicitly and silence at the default level is not mistaken for endorsement:

- `fractal.response-unbound` moved from level 1 to level 2, matching its
  `query-builder.params-undeclared` sibling. Neither now fires at the default lint level.
- The rule's `description()` was rewritten to spell out the blind spot ("Misses the `fractal()`
  helper and the `Spatie\Fractalistic\Fractal` facade …") so the caveat is visible in
  `openapi:lint --list-rules` output, not just in this document.

Option (b) — surfacing rule descriptions in zero-finding runs — would change lint-output
formatting and remained unjustified for two rules' worth of payoff. The trade-off the rules
encode (accepted misses over false positives in the absence of method-body inference) is
unchanged; only the default visibility is.

---

## OAPI-061 — `league/fractal` constraint over-pinned to `^0.20.2`

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `composer.json` now lists
`"league/fractal": "~0.20.2"` under `require-dev` — explicit about the intent
to allow 0.20.x patch updates without claiming forward compatibility with a
future 0.21 release.

---

## OAPI-062 — Disabled-plugin comments in `config/openapi.php` name only one suggested package

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. The Fractal-plugin comment
in `config/openapi.php` now names both triggers — `league/fractal` and
`spatie/laravel-fractal` (which depends on `league/fractal`) — so a user who
has the Spatie wrapper installed has a signal from the config alone that they
already meet the requirement.
