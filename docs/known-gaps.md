# Known Gaps

These are intentionally unresolved limitations of the package. They are documented here so
consumers know what to expect and where the rough edges are. Contributions addressing either are
welcome.

| ID | Title | Status |
|---|---|---|
| [OAPI-017](#oapi-017--no-method-body-inference) | No method-body inference | Open |
| [OAPI-038](#oapi-038--lint-rules-miss-allof-composed-schema-properties) | Lint rules miss `allOf`-composed schema properties | Open |
| [OAPI-039](#oapi-039--queryparam-attribute-has-no-core-resolver) | `#[QueryParam]` attribute has no Core resolver | Closed |
| [OAPI-040](#oapi-040--no-dataresponseresolver-for-spatie-data-return-types) | No `DataResponseResolver` for Spatie Data return types | Closed |
| [OAPI-041](#oapi-041--no-response-header-authoring-attribute) | No response-header authoring attribute | Closed |
| [OAPI-042](#oapi-042--security-cannot-name-a-scheme-securityschemes-hard-coded-to-passport) | `#[Security]` cannot name a scheme; security schemes hard-coded to Passport | Closed |
| [OAPI-043](#oapi-043--no-deprecated-authoring-attribute) | No `#[Deprecated]` authoring attribute | Closed |
| [OAPI-044](#oapi-044--no-shipped-route-filter-for-laravel-passport) | No shipped route filter for Laravel Passport | Closed |
| [OAPI-045](#oapi-045--security-default-scheme-resolution-privileges-passport) | `#[Security]` default scheme resolution privileges Passport | Open |
| [OAPI-046](#oapi-046--responseheader-cannot-be-declared-at-class-level) | `#[ResponseHeader]` cannot be declared at class level | Open |
| [OAPI-047](#oapi-047--skippassportroutes-lacks-the-constructor--fromconfig-shape-of-its-siblings) | `SkipPassportRoutes` lacks the constructor + `fromConfig()` shape of its siblings | Open |
| [OAPI-048](#oapi-048--dataresponseresolver-duplicates-paginator-envelope-logic-from-paginatorresponseresolver) | `DataResponseResolver` duplicates paginator-envelope logic from `PaginatorResponseResolver` | Open |
| [OAPI-049](#oapi-049--corequeryparameterresolver-and-querybuilderparameterresolver-duplicate-schema-build-code) | `CoreQueryParameterResolver` and `QueryBuilderParameterResolver` duplicate schema-build code | Open |
| [OAPI-050](#oapi-050--operationbuilder-issues-many-getattributesclass-calls-per-route) | `OperationBuilder` issues many `getAttributes($class)` calls per route | Open |
| [OAPI-051](#oapi-051--per-route-reflection-and-config-reads-are-not-memoized) | Per-route reflection and config reads are not memoized | Open |
| [OAPI-052](#oapi-052--fractal-serializer-assumed-to-be-dataarrayserializer) | Fractal serializer assumed to be `DataArraySerializer` | Open |
| [OAPI-053](#oapi-053--fractalresponse-unbound-does-not-detect-fractal-helper-or-facade-usage) | `fractal.response-unbound` does not detect `fractal()` helper / facade usage | Open |
| [OAPI-054](#oapi-054--lint-rules-do-not-share-reflection-results-within-a-walk) | Lint rules do not share reflection results within a walk | Open |

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

**Status:** Open

**Symptom:** Lint rules that inspect schema properties only see properties declared *directly* on
a schema. Properties a schema inherits by composing other schemas via `allOf` (the common
`allOf: [{$ref: Base}, {properties: {…}}]` pattern) are invisible to the rules. This produces:

- **False positives** in `schema.required-without-property`: a `required` entry naming a property
  that comes from an `allOf` `$ref` branch is reported as "required without a matching property".
- **False negatives** in `schema.enum-type-mismatch` and every other `FieldRule`: fields living on
  an `allOf` branch are never visited.

**Root cause:** `SpecTreeBuilder::buildFields()` reads only `$schema->properties`. It ignores
`$schema->allOf`, so the domain tree's `FieldNode` set for any `allOf`-composed schema is
incomplete. Every rule that walks `ComponentSchemaNode->fields` / `FieldNode` inherits the gap.

**Scope notes for a future implementer:**

- Only `allOf` composes properties. `oneOf` / `anyOf` must be left out of property resolution.
- Resolution can be eager (merge in `SpecTreeBuilder::buildFields()`) or lazy (a resolver on the
  node types). Lazy resolution keeps tree-build order intact and is the lighter touch.
- Recursive `allOf` / `$ref` chains need cycle detection.

**Impact:** Schemas built purely from the bundled generators rarely use `allOf` composition, so in
practice this surfaces only for hand-authored or transformer-injected `allOf` schemas. It is a
linter-accuracy gap, not a generation bug.

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

**Status:** Open

**Symptom:** When `#[Security]` is used without naming a scheme, `SecurityExtractor::requirementForScopes()`
emits the Passport-derived `oauth2` + `oauth2ClientCredentials` pair whenever Passport is
installed — even if the project has declared its own schemes in `openapi.security_schemes` and
expected one of those to be the default. The config-declared schemes are only consulted as a
fallback for projects without Passport.

**Root cause:** When OAPI-042 unblocked config-registered schemes it kept the existing
Passport-first branch in `requirementForScopes()` rather than threading the new config catalogue
through a single lookup. The result is two parallel default-resolution paths that aren't
visible from the call site.

**Impact:** A project that installs Passport but wants `#[Security(['scope'])]` (no `scheme:`) to
target its own `bearer` scheme has to either pass `scheme: 'bearer'` everywhere or accept that
the spec advertises Passport's OAuth2 pair on every authenticated operation.

**Workaround:** Pass `scheme: 'name'` explicitly on every `#[Security]` attribute.

**Why it's open:** The right default depends on intent — "Passport apps should advertise both
flows by default" is reasonable behaviour for Passport-only projects but wrong for the
mixed-scheme case. Needs a deliberate decision about whether the merged catalogue should treat
all schemes equally (first declared wins) or whether Passport keeps its precedence.

---

## OAPI-046 — `#[ResponseHeader]` cannot be declared at class level

**Status:** Open

**Symptom:** `#[ResponseHeader]` targets `TARGET_METHOD | TARGET_FUNCTION` only, so authors cannot
declare a shared response header (`X-Request-Id`, `X-RateLimit-Remaining`) once on the
controller and have it apply to every action. The sibling `#[Header]` (request-side) accepts
`TARGET_CLASS` and is read off both controller and method by
`OperationBuilder::readHeaderAttributes()`.

**Impact:** Authors must repeat `#[ResponseHeader(name: 'X-Request-Id', ...)]` on every method
of a controller instead of declaring it once at the top.

**Why it's open:** Pending a deliberate choice between two valid shapes — extend the target list
to include `TARGET_CLASS` and have the builder walk controller + method (mirroring `#[Header]`),
or treat per-response-header declarations as deliberately method-scoped. The asymmetry between
`#[Header]` and `#[ResponseHeader]` is the practical signal that this should be decided.

---

## OAPI-047 — `SkipPassportRoutes` lacks the constructor + `fromConfig()` shape of its siblings

**Status:** Open

**Symptom:** The three other route filters under `src/Core/Routing/Filters/` — `SkipNovaRoutes`,
`SkipTelescopeRoutes`, `SkipIgnitionRoutes` — take constructor parameters and expose a
`fromConfig()` factory; `OpenApiServiceProvider` registers them via that factory.
`SkipPassportRoutes` (OAPI-044) deviates: it hard-codes the `passport.` route-name prefix and
has no factory.

**Justification for the deviation:** Passport's route prefix is not user-configurable, so there
is genuinely nothing to configure. The deviation reflects reality, not laziness.

**Impact:** Consistency cost only — anyone reading the filter directory will notice the odd one
out and wonder if it was an oversight. A `fromConfig()` returning a parameterless instance plus
a class-level docblock explaining the deviation would erase the surprise without changing
behaviour.

**Why it's open:** Cosmetic. Not blocking anyone.

---

## OAPI-048 — `DataResponseResolver` duplicates paginator-envelope logic from `PaginatorResponseResolver`

**Status:** Open

**Symptom:** `src/Plugins/SpatieData/DataResponseResolver.php` (~156 lines) re-implements
paginator-envelope construction that already lives in
`src/Core/Extractors/PaginatorResponseResolver.php`. Specifically, the
`PaginatedDataCollection` / `CursorPaginatedDataCollection` branches build the same
length-aware / cursor envelopes that `PaginatorKind::fromClass()` + `PaginatorSchemaFactory`
build for Laravel's native paginators.

**Cleaner shape:** Teach `PaginatorKind::fromClass()` to recognise Spatie's two paginator
collection classes; the existing `PaginatorResponseResolver` then handles both flavours via the
shared `RefSchemaResolver` infrastructure (which Spatie already integrates with via
`DataRefSchemaResolver`). `DataResponseResolver` shrinks to ~30 lines handling only single
`Data` and non-paginating `DataCollection<…, Item>` — the two cases the core resolver cannot
cover.

**Impact:** Maintenance cost — paginator-envelope fixes have to be applied in two places.

**Why it's open:** Refactor opportunity; no functional defect.

---

## OAPI-049 — `CoreQueryParameterResolver` and `QueryBuilderParameterResolver` duplicate schema-build code

**Status:** Open

**Symptom:** Both resolvers run the same four-line sequence to build an `OA\Schema` from a
`FieldAttribute`'s descriptor:

```php
$descriptor = $attribute->descriptor();
$schema = new OA\Schema(['type' => 'string', ...$descriptor->toOpenApi()]);
if ($descriptor->nullable === true) {
    NullableSchema::applyTo($schema);
}
```

The same explanatory comment about `SchemaDescriptor::toOpenApi()` omitting `nullable` is
copy-pasted in both files. Extracting a static helper on `SchemaDescriptor` (e.g.
`SchemaDescriptor::toSchema(string $defaultType = 'string'): OA\Schema`) would let both
resolvers share the snippet.

**Impact:** Small. The duplication is mechanical and the rest of each resolver shapes a
different parameter (`name=foo` vs `filter[foo]`, single vs list-with-enum), so consolidation
beyond this helper isn't warranted.

**Why it's open:** Refactor opportunity.

---

## OAPI-050 — `OperationBuilder::build()` issues many `getAttributes($class)` calls per route

**Status:** Open

**Symptom:** `OperationBuilder::build()` issues ~17 separate
`$reflector->getAttributes(SomeAttribute::class)` calls per route across its read* helpers, and
each call performs an independent attribute walk. Most operations declare zero or one
attribute, so the overwhelming majority of those calls return an empty array after walking the
full attribute list.

**Cleaner shape:** Read `$reflector->getAttributes()` (no filter) once per reflector, bucket the
result by attribute class into a `array<class-string, list<ReflectionAttribute>>` map, and have
each helper read from the map instead. Collapses ~17 attribute walks into 2 (one per
reflector).

**Impact:** Generation runs over `n` routes do `O(17·n)` attribute lookups instead of `O(2·n)`.
Per-route cost is small in absolute terms, but adds up over large route tables and is invoked
on the `/api/openapi.yaml` route at runtime in dev environments.

**Why it's open:** Mechanical refactor across most of `OperationBuilder`'s read paths.

---

## OAPI-051 — Per-route reflection and config reads are not memoized

**Status:** Open

**Symptom:** Several extractors/resolvers do work per route that could be amortised across the
whole generation run:

- `SecurityExtractor::passportAvailable()` runs `class_exists` + three `Router::has()` lookups
  on every call, and is called from `requirementForScopes()` per route (and again from
  `buildSchemes()` per document).
- `SecurityExtractor::forRoute()` re-fetches `Router::getMiddlewareGroups()` per route.
- `ReturnTypeExtractor::genericArgument()` parses the same controller-method docblock via
  `DocBlockFactory::create()` once per `PrimaryResponseResolver` that consults it — so a route
  returning `DataCollection<…, FlightData>` triggers two `DocBlockFactory::create()` parses (one
  from `PaginatorResponseResolver`, one from `DataResponseResolver`).

**Cleaner shape:** Memoise each on first call (`?bool` / `?array` field for the extractors;
keyed map for `ReturnTypeExtractor`). All three are pure functions of state that does not
change during a generation run, and `ComponentSchemaRegistry`-style scoped lifecycle already
handles per-run reset cleanly.

**Impact:** `DocBlockFactory::create()` is genuinely expensive (full phpDocumentor parse +
`ContextFactory` walking the file's `use` statements) — the biggest single win is memoising
that call. The others are smaller but free.

**Why it's open:** Real wins, but want profiling in hand before committing to memoisation
strategies that interact with Octane's scoped-lifecycle reset story.

---

## OAPI-052 — Fractal serializer assumed to be `DataArraySerializer`

**Status:** Open

**Symptom:** `FractalPlugin`'s response envelopes — `{data}` for a single item, `{data: [...]}`
for a flat collection, and `{data: [...], meta: {pagination: {…}}}` for a paginated one — model
Fractal's default `League\Fractal\Serializer\DataArraySerializer` plus its
`IlluminatePaginatorAdapter` shape. Other serializers (`JsonApiSerializer`, `ArraySerializer`,
custom serializers) produce different envelope shapes (`{data, included}`,
top-level arrays, etc.) and are not modelled by the plugin. The generated document will not
match the runtime output for endpoints using them.

**Workaround:** Use `#[Response]` on the affected endpoints to declare the actual response
schema explicitly. The `#[Response]` schema takes precedence over `FractalResponseResolver`.

**Why it's open:** Modelling additional serializers requires either a per-endpoint declaration
(another attribute) or per-plugin configuration; both are sensible additions but neither is
required for the dominant `DataArraySerializer` case. Deferred until there is real demand from
codebases using a non-default serializer.

---

## OAPI-053 — `fractal.response-unbound` does not detect `fractal()` helper or facade usage

**Status:** Open

**Symptom:** The `fractal.response-unbound` lint rule keys off an injected
`League\Fractal\Manager` parameter (a body-free signal — see OAPI-017). It does not flag methods
that produce Fractal output via the `fractal()` helper or the `Spatie\Fractalistic\Fractal`
facade, both of which are invoked inside the method body without injecting a `Manager`
parameter. Endpoints written that way will silently produce undocumented Fractal output.

**Workaround:** Declare `#[FractalResponse]` on those endpoints explicitly. Once declared, the
plugin emits the response envelope normally regardless of how the runtime Fractal call is made.

**Why it's open:** Detecting the helper or facade requires reading method bodies, which is
forbidden under OAPI-017. The narrow `Manager`-parameter signal is the conservative escape
from the trade-off — accepted misses over false positives — and is documented in the rule's
`description()` so users do not read silence as endorsement.

---

## OAPI-054 — Lint rules do not share reflection results within a walk

**Status:** Open

**Symptom:** Each lint rule does its own reflection work on every operation it visits, even when
sibling rules on the same `OperationNode` need the same intermediate result. Concrete examples:

- `ResourceFieldsUndeclared` and `ResourceFieldTypeMissing` both call
  `ResourceClassLocator::locate($descriptor)` and then independently build
  `new ReflectionClass($resourceClass)` for the same descriptor on the same walk.
- The three Fractal rules that key off `#[FractalResponse]` (`FractalFieldsUndeclared`,
  `FractalIncludeTransformerMissing`, `FractalDuplicateKey`) each repeat the same six-step
  prologue: fetch the attribute off `$descriptor->method`, instantiate it, `class_exists`-check
  the transformer FQCN, and allocate a fresh `ReflectionClass`. Two of them additionally call
  `->newInstance()` on every `TransformerField` / `TransformerInclude` purely to read one
  string property.
- `QueryBuilderParamsUndeclared` and `QueryBuilderFilterTypeMissing` both invoke
  `PayloadParameterScanner::directCandidates($method)`. The scanner has its own per-method
  cache, but the rules then re-walk attribute lists independently.

This compounds OAPI-050 (per-route attribute walks in `OperationBuilder`) and OAPI-051 (lack of
memoisation in extractors): the lint pass effectively re-does some of the work the generator
already did, and then re-does it again across sibling rules within the same walk.

**Cleaner shape:** Attach a per-walk reflection cache to `LintContext` — keyed by
`ReflectionMethod` / `ReflectionClass` — that buckets `getAttributes()` results by attribute
class once per reflector, mirroring the shape proposed for OAPI-050. Rule authors would resolve
attributes through the cache instead of calling `getAttributes(SomeAttribute::class)` directly.
A second helper for "resolve transformer / resource class for this operation" could share a
single resolution across all rules that key off the same endpoint attribute.

**Impact:** Real but bounded. Reflection allocations are cheap individually; the cost grows
with `routes × rules`. Worth doing alongside OAPI-050 / OAPI-051 rather than piecemeal — the
codebase currently accepts per-rule re-computation as the norm, so optimising one plugin in
isolation creates inconsistency without moving the aggregate cost meaningfully.

**Why it's open:** Cross-cutting refactor that pairs naturally with OAPI-050 and OAPI-051.
Deferred until those are scheduled, so the cache shape can be designed once and shared by
`OperationBuilder`, extractors, and lint rules.
