# Attribute catalog

Authoring attributes live in `Radiergummi\OpenApi\Attributes`. Import
once and reference via the namespace alias:

```php
use Radiergummi\OpenApi\Attributes as OpenApi;

#[OpenApi\Operation(summary: 'Create a flight.')]
public function store(FlightData $data): FlightData { … }
```

Use them when convention can't derive what you need. Runnable examples live
in [Recipes](recipes.md).

## Operation-level attributes

Attach to controller classes or methods.

| Attribute | Target | Repeatable | Purpose |
|---|---|---|---|
| `Operation` | class, method | no | Override `summary`, `description`, `operationId`, or the auto-derived tag set. `replace: true` discards auto-derived tags; the default merges. `streaming: true` advertises `text/event-stream` as the response content type. |
| `Summary` | class, method | no | On a controller/operation: set the operation summary. Standalone alternative to `#[Operation(summary: …)]`. Precedence: method `#[Summary]` → method `#[Operation(summary)]` → method docblock → class `#[Summary]` → class `#[Operation(summary)]`. Class-level placement is for `__invoke` controllers. On a Spatie `Data` class or Eloquent `JsonResource` class: sets the component schema's `title`. |
| `Description` | class, method | no | Same as `#[Summary]` but for the long-form description. On a Data / JsonResource class it sets the component schema's `description`. |
| `Tag` | class, method | yes | Add a tag to the already-derived set (merge, not replace). |
| `QueryParam` | class, method | yes | Document an ad-hoc query string parameter. Each instance defines one parameter. |
| `RequestBody` | method | no | Override the request-body `description`, `required`, or `mediaType` (e.g. `multipart/form-data`). Set `discriminator:` to a property name to switch the body to a `oneOf` + `discriminator` built from `#[RequestVariant]` branches. |
| `RequestVariant` | method | yes | Declare one branch of a discriminated request body. Requires `#[RequestBody(discriminator: '…')]` on the same method. See [Discriminated request bodies](#discriminated-request-bodies). |
| `ResponseResource` | class, method | no | Explicit response-resource class for the 200 response. `collection: true/false` overrides envelope detection; `null` auto-detects. |
| `Response` | method | yes | Add an extra response by status code, with optional `ref` (a resolver-resolved class), inline `schema`, and `mediaType`. |
| `Example` | method | yes | Named example payload for the request body. |
| `ResponseExample` | method | yes | Named example for a specific response status. |
| `Header` | method | yes | Document a custom request header parameter on the operation. |
| `ResponseHeader` | method | yes | Document a custom response header. Set `status:` to scope to a specific response status (defaults to the primary 2xx). |
| `Security` | class, method | yes | Override the auto-derived scopes. Pass an empty list for "token required, no specific scope". `scheme:` targets a specific scheme name from `openapi.security_schemes` (or one of the Passport-derived defaults); omit for the project default. Stack multiple instances to advertise OR-alternatives (any one set of credentials satisfies the operation). See [Declare custom security schemes](recipes.md#declare-custom-security-schemes). |
| `PublicEndpoint` | class, method | no | Mark as public (no auth advertised) even if middleware would imply otherwise. |
| `Hide` | class, method | no | Exclude from the spec. `only: ['production']` hides only in those environments; `except: ['local']` hides everywhere except. Pass no argument to hide unconditionally. The two arguments are mutually exclusive. |
| `Expose` | class, method | no | Include in the spec when `config('openapi.visibility.default')` is `'hidden'`. Same `only` / `except` semantics as `Hide`. No-op in public-default mode (flagged by `visibility.attribute-no-op`). |
| `ExternalDocs` | method | no | Add an "external documentation" link to the operation. |
| `Link` | method | yes | Declare an OpenAPI Link on the primary 2xx response. `operationId` (preferred) or `operationRef` must be provided. See [Link to another operation from a response](recipes.md#link-to-another-operation-from-a-response). |
| `Discriminator` | class | no | Mark a polymorphic base class (a `Data` class or a response-resource class). Schema becomes `oneOf` + `discriminator`. See [Document a polymorphic response with a discriminator](recipes.md#document-a-polymorphic-response-with-a-discriminator). |
| `Webhook` | method | no | Divert the route from `paths` into the OpenAPI 3.1 top-level `webhooks` block. `name` is the map key. |
| `Spec` | class, method | yes | Pin the route to one or more named specs explicitly, bypassing the partition's `match` config. `#[Spec]` (no argument) means "only the `default` spec". Method-level declarations replace class-level ones. See [Multi-spec](multi-spec.md). |
| `IgnoreLint` | class, method, property | yes | Suppress one `openapi:lint` rule for the annotated symbol. See [Suppress a finding](linting.md#suppress-a-finding). |
| `#[\Deprecated]` (PHP native) | class, method | no | Marks the operation `deprecated: true` and appends the message to the description. |
| `@deprecated` (PHPDoc tag) | method | no | Convention alternative to the attribute: marks the operation `deprecated: true` and appends the tag's trailing text to the description. Lowest precedence — a `#[\Deprecated]` / `#[Deprecated]` attribute (method, then class) wins. |

## Field-enrichment attributes

`FieldAttribute` has four scoped subclasses. Pick the one matching the
target. The wrong scope is caught by `field.attribute-wrong-scope`.

| Attribute | Target | Scope | Notes |
|---|---|---|---|
| `RequestField` | property, parameter, class-constant | Request-body input fields | Place on a Spatie Data class property / promoted constructor parameter, or on a `FormRequest` field constant. Supports `writeOnly`. No `readOnly` or `default`. |
| `ResponseField` | class-constant, property | Response output fields | Place on a response class field constant or property. Supports `readOnly` and `conditional`. `conditional: true` keeps the field in `properties` but removes it from `required`. Use for conditionally-present fields. |
| `PathParam` | parameter | URI path parameters | Place on a controller action parameter for a route-bound model or scalar segment. Only `description`, `example`, `format`, and `pattern` apply (type is inferred from the binding). |
| `QueryParam` | class, method | Ad-hoc query parameters | See operation-level table above. |

All four subclasses share the same JSON Schema field surface inherited from
`FieldAttribute`:

`title`, `description`, `example`, `type`, `format`, `nullable`, `enum`,
`minimum` / `maximum`, `exclusiveMinimum` / `exclusiveMaximum`, `multipleOf`,
`minLength` / `maxLength`, `pattern`, `minItems` / `maxItems`, `uniqueItems`,
`readOnly`, `writeOnly`.

### Enum from a backed-enum class-string

`enum:` accepts a backed-enum class-string in place of a literal value list — the generator
resolves it to the enum's cases and infers the scalar `type` (`string` or `integer`) from the
backing type:

```php
#[ResourceField('status', enum: ServerStatus::class)]
```

This is equivalent to hand-listing `enum: ['ready', 'installing', …]` plus `type: 'string'`, but
stays in sync with the enum automatically — useful for status-like fields whose values come from
a backed enum the resource exposes as a string. An explicit `type:` still wins over the inferred
one, and the literal-array form keeps working as an override.

### Inline description directives

The `description` argument of `#[RequestField]`, `#[QueryParam]`, `#[ResponseField]`, and
`#[PathParam]` accepts three keyword directives, each on its own line. The `@` prefix is required —
it keeps directives visibly distinct from prose so a sentence like `Enum: see docs at /enums` is
not silently parsed as a directive.

- `@example <value>` — declare the field's example without a separate attribute. The value is
  coerced by lexical shape (`42` → int, `3.14` → float, `true`/`false` → bool, anything else → string).
- `@no-example` — suppress example generation for this field. Wins against any `@example` directive
  (whether earlier or later in the description).
- `@enum a, b, c` — declare the field's enum domain. Tokens are coerced by the same lexical rules
  as `@example`, so `@enum 200, 404, 500` yields ints, not strings.

Explicit attribute arguments (`example:`, `enum:`) always beat directives — including when the
explicit value is `null`, which is the conventional way to suppress directive-derived values from
a single field. Directive lines are stripped from the rendered description; when multiple
`@example` / `@enum` directives appear, the last one wins.

### Discriminated request bodies

When an action validates a plain `Request` and the body shape depends on a discriminator field
(e.g. `provider` or `type`), use `#[RequestBody(discriminator: '…')]` together with one
repeatable `#[RequestVariant]` per branch to emit a `oneOf` + `discriminator` body:

```php
#[RequestBody(discriminator: 'provider')]
#[RequestVariant('aws', fields: [new RequestField('region', required: true)])]
#[RequestVariant('hetzner', fields: [new RequestField('api_token', required: true)])]
#[RequestVariant('custom', schema: CustomProviderData::class)]
public function store(Request $request) { … }
```

Each `#[RequestVariant]` supplies exactly one of:

- **`fields: [new RequestField(…), …]`** — inline fields describing the branch's shape (array form, not variadic).
- **`schema: SomeClass::class`** — a class-string the ref-resolver chain can build (a Spatie Data
  class or API Resource; not a FormRequest).

**Inline branches** — the discriminator property (`provider` above) is auto-injected into each
inline branch as a required `string` whose `enum` is restricted to that branch's value. To
override (e.g. to attach a description), declare a `#[RequestField]` with the same name in that
branch — the explicit field wins.

**Class-string branches** — the variant emits an opaque `$ref` to the resolved component schema.
The class must already declare the discriminator property; a missing discriminator property is
reported by the `discriminator.invalid-mapping` lint rule.

Malformed usage (a `discriminator:` with no `#[RequestVariant]` at all, a variant with neither or
both of `schema`/`fields`, a duplicate `value`, an unresolvable class-string, or a colliding
sanitised key) is reported as `request.discriminator-malformed`.

## Component schema attributes

Attach to a class that becomes a reusable component schema (a Spatie `Data` class, an API
Resource, a `FormRequest`, an Eloquent model).

| Attribute | Target | Purpose |
|---|---|---|
| `SchemaName` | class | Override the component key the class maps to in `#/components/schemas/{key}`. |

By default the key is derived from the class basename (disambiguated with namespace segments, or a
short hash as a last resort). That key is a public, consumer-facing contract — it becomes the type
name in generated clients — yet it tracks the PHP class name and location, so a rename or move
silently changes it. Use `#[SchemaName]` as a scoped escape hatch: pin a name against refactors, or
replace an ugly derived name.

```php
use Radiergummi\OpenApi\Attributes as OpenApi;

#[OpenApi\SchemaName('CustomerProfile')]
final class UserResource extends JsonResource { … }
// → #/components/schemas/CustomerProfile, regardless of the class name
```

It is an override, not a thing to put on every class — naming a class the same as its basename only
restates what derivation already produces. The name still goes through the `component_name_case`
lint rule, so keep it consistent with the rest of the document (PascalCase by default). Two distinct
classes declaring the same name is an unresolvable conflict: generation throws
`DuplicateSchemaNameException`.

## Exception-level attribute

Attach to an exception class to map it to a status code wherever the
exception appears in a `@throws` tag.

| Attribute | Target | Purpose |
|---|---|---|
| `ExceptionResponse` | exception class | Declare the HTTP status and description that this exception produces. Checked before `config/openapi.exception_responses`. |

```php
#[OpenApi\ExceptionResponse(status: 418, description: "I'm a teapot")]
class TeapotException extends RuntimeException {}
```

## Plugin attributes

Bundled plugins ship their own attributes under
`Radiergummi\OpenApi\Plugins\<plugin>\Attributes`. See [Plugins](plugins.md)
for full details.

- **ApiResources**: `#[ResourceField]`
- **QueryBuilder**: `#[AllowedFilter]`, `#[AllowedSort]`, `#[AllowedInclude]`
- **Fractal**: `#[FractalResponse]`, `#[TransformerField]`, `#[TransformerInclude]`
