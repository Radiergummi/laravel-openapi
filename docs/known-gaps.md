# Known Gaps

These are intentionally unresolved limitations of the package. They are documented here so
consumers know what to expect and where the rough edges are. Contributions addressing either are
welcome.

| ID | Title | Status |
|---|---|---|
| [OAPI-017](#oapi-017--no-method-body-inference) | No method-body inference | Open |
| [OAPI-038](#oapi-038--lint-rules-miss-allof-composed-schema-properties) | Lint rules miss `allOf`-composed schema properties | Open |

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
