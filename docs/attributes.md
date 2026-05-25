# Attribute catalog

Authoring attributes live in `Radiergummi\OpenApi\Core\Attributes`. Import
once and reference via the namespace alias:

```php
use Radiergummi\OpenApi\Core\Attributes as OpenApi;

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
| `RequestBody` | method | no | Override the request-body `description`, `required`, or `mediaType` (e.g. `multipart/form-data`). |
| `ResponseResource` | class, method | no | Explicit response-resource class for the 200 response. `collection: true/false` overrides envelope detection; `null` auto-detects. |
| `Response` | method | yes | Add an extra response by status code, with optional `ref` (a resolver-resolved class), inline `schema`, and `mediaType`. |
| `Example` | method | yes | Named example payload for the request body. |
| `ResponseExample` | method | yes | Named example for a specific response status. |
| `Header` | method | yes | Document a custom request header parameter on the operation. |
| `ResponseHeader` | method | yes | Document a custom response header. Set `status:` to scope to a specific response status (defaults to the primary 2xx). |
| `Security` | class, method | no | Override the auto-derived scopes. Pass an empty list for "token required, no specific scope". `scheme:` targets a specific scheme name from `openapi.security_schemes` (or one of the Passport-derived defaults); omit for the project default. See [Declare custom security schemes](recipes.md#declare-custom-security-schemes). |
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
