# Known Gaps

These are intentionally unresolved limitations of the package. They are documented here so
consumers know what to expect and where the rough edges are. Contributions addressing either are
welcome.

| ID | Title | Status |
|---|---|---|
| [OAPI-017](#oapi-017--no-method-body-inference) | No method-body inference | Open |
| [OAPI-038](#oapi-038--lint-rules-miss-allof-composed-schema-properties) | Lint rules miss `allOf`-composed schema properties | Open |
| [OAPI-039](#oapi-039--queryparam-attribute-has-no-core-resolver) | `#[QueryParam]` attribute has no Core resolver | Open |
| [OAPI-040](#oapi-040--no-dataresponseresolver-for-spatie-data-return-types) | No `DataResponseResolver` for Spatie Data return types | Open |
| [OAPI-041](#oapi-041--no-response-header-authoring-attribute) | No response-header authoring attribute | Open |
| [OAPI-042](#oapi-042--security-cannot-name-a-scheme-securityschemes-hard-coded-to-passport) | `#[Security]` cannot name a scheme; security schemes hard-coded to Passport | Open |
| [OAPI-043](#oapi-043--no-deprecated-authoring-attribute) | No `#[Deprecated]` authoring attribute | Open |

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

**Status:** Open

**Symptom:** `src/Core/Attributes/QueryParam.php` exists and `docs/usage.md` documents it as a
method-scope attribute for declaring ad-hoc query-string parameters. Lint rules
(`ParameterNameNamingInconsistent`, `QueryParamDuplicate`) reference it. But no code reads the
attribute off a controller method — `OperationBuilder` does not call `getAttributes(QueryParam::class)`,
and the only `QueryParameterResolver` implementation is the QueryBuilder plugin's, which reads
`#[AllowedFilter]`/`#[AllowedSort]`/`#[AllowedInclude]` instead. As a result a method annotated
`#[QueryParam('page', type: 'integer')]` emits no parameter in the generated spec.

**Workarounds:**

- Express the query parameter through a Spatie Data class or `FormRequest` field (which the
  generator does read).
- Live without documenting ad-hoc query parameters until a resolver lands.

**Why it's open:** Discovered while building the `examples/vanilla/` showcase, which prominently
uses `#[QueryParam]` for pagination. The fix is a small Core resolver that reflects
`#[QueryParam]` on the action and emits `OA\Parameter`s — roughly mirroring
`QueryBuilderParameterResolver`. Scheduled but not yet implemented.

---

## OAPI-040 — No `DataResponseResolver` for Spatie Data return types

**Status:** Open

**Symptom:** The `SpatieData` plugin handles request bodies (via `DataClassRequestSchemaResolver`)
and reusable schema refs (via `DataRefSchemaResolver`) but does not auto-derive a primary
response from a Data return type. A controller method declared

```php
public function show(string $flight): FlightData { … }
```

emits a `200 OK` with an empty schema. To get the FlightData schema on the response the author
must add an explicit `#[Response(status: 200, ref: FlightData::class)]` — repetitive when every
read endpoint returns a Data class.

`ApiResources` ships an equivalent for `JsonResource` (`ResourceResponseResolver`); the
SpatieData plugin lacks the symmetric piece.

**Workarounds:**

- Annotate the method with `#[Response(ref: SomeData::class)]`.
- Wrap the return in a `JsonResource` so the ApiResources plugin's existing resolver kicks in
  (loses the Data benefits).

**Why it's open:** Discovered while building the `examples/spatie-data/` and `examples/combined/`
showcases. The fix is a `PrimaryResponseResolver` that detects `Data` / `DataCollection` return
types and emits a `$ref` to the appropriate component schema — mirroring
`ResourceResponseResolver` against the `DataRefSchemaResolver`'s schema pool.

---

## OAPI-041 — No response-header authoring attribute

**Status:** Open

**Symptom:** `#[Header]` is request-scope only — its constructor is `(name, description, required,
type, example)` and the attribute target is the request side of the contract. There is no
`#[ResponseHeader]` (and no response-side mode on `#[Header]`). The canonical use case — declaring
`Location` on a `201 Created` response — cannot be expressed.

**Workarounds:**

- Omit the response header from the spec.
- Hand-author the spec post-processing step (e.g. a `Transformer`) that injects the header into
  the right response.

**Why it's open:** Discovered while building the `examples/form-requests/` showcase, where the
plan called for `Location` on `POST /flights`'s 201. Two viable fixes: a new `#[ResponseHeader]`
attribute (cleaner API, smaller blast radius) or an optional response-target mode on the existing
`#[Header]` (fewer attributes but ambiguous semantics). Not yet scheduled.

---

## OAPI-042 — `#[Security]` cannot name a scheme; security schemes hard-coded to Passport

**Status:** Open

**Symptom:** Two related limitations:

1. `#[Security]` takes a `list<string>` of OAuth scopes — it has no parameter for the security
   scheme name. Callers cannot say "this operation requires a bearer JWT" because the choice of
   *scheme* is not theirs to make.
2. `SecurityExtractor::buildSchemes()` is hard-coded to emit two schemes named `oauth2` and
   `oauth2ClientCredentials` (derived from Laravel Passport). There is no config key — no
   `openapi.security_schemes` — to register additional schemes (e.g. plain `bearer` JWT,
   `apiKey`, basic auth) or override the Passport defaults.

In combination: a non-Passport app can declare `#[Security(['read:thing'])]` but the resulting
spec references Passport-derived schemes the app does not actually use.

**Workarounds:**

- Live with the Passport-named schemes in the spec.
- Post-process the generated YAML/JSON to rewrite `securitySchemes` and operation `security`
  blocks.

**Why it's open:** Discovered while building the `examples/combined/` showcase. The fix has two
halves: (a) introduce an `openapi.security_schemes` config key consumed by `SecurityExtractor`
and (b) add an optional `scheme:` parameter to `#[Security]` (defaulting to the project's first
registered scheme so existing usage doesn't change). Not yet scheduled.

---

## OAPI-043 — No `#[Deprecated]` authoring attribute

**Status:** Open

**Symptom:** OpenAPI 3.1 lets you mark operations and schema properties as deprecated. The
package emits `deprecated: true` on properties when it sees PHPDoc `@deprecated` (via
`DocCommentParser`) but there is no `#[Deprecated]` attribute parallel to the other authoring
attributes. Authors who want to mark something deprecated via attribute (or who don't have
PHPDoc on the property, e.g. on enum cases or constructor-promoted parameters where PHPDoc
parsing is awkward) have no symmetric path.

**Workarounds:**

- Use PHPDoc `@deprecated` on properties — works for class properties and Data-class
  constructor parameters with PHPDoc blocks.
- Author the `deprecated: true` keyword via a `Transformer` post-processing pass.

**Why it's open:** Discovered while building the `examples/spatie-data/` showcase, which uses
PHPDoc `@deprecated` on the legacy `aircraft` property in `FlightData`. The fix is a small
attribute targeting properties / parameters / methods that flips `deprecated: true` on the
emitted schema or operation. Low priority — the PHPDoc path covers the common case.

---

## OAPI-044 — No shipped route filter for Laravel Passport

**Status:** Closed

**Resolved in:** the `feature/plugin-suite` branch. `SkipPassportRoutes` now ships in
`src/Core/Routing/Filters/` and is registered by default in `config/openapi.php` alongside
`SkipNovaRoutes` / `SkipTelescopeRoutes` / `SkipIgnitionRoutes`. Host apps using Passport get
its CRUD endpoints filtered out of the generated spec out of the box; the filter tolerates
Passport being absent by matching only routes whose name starts with `passport.`.
