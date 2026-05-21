# Usage Guide

`radiergummi/laravel-openapi` generates an OpenAPI 3.1 document from your application's
route definitions — there is no hand-written YAML. Most endpoints are documented automatically
by following conventions (Spatie Data request classes, typed return values, PHPDoc summaries,
auth/scope middleware). Authoring attributes in `Radiergummi\OpenApi\Core\Attributes` cover the
cases where convention isn't enough.

This document is the reference for what's auto-derived, when you need to reach for an attribute,
how the lint system works, and how to extend the subsystem with a new plugin.

## Worked examples

If you want to see this in action against real Laravel code rather than read a reference, check
out `examples/` in the repository. Each subdirectory is a small Laravel app exposing the same API
surface with a different stack (vanilla, FormRequest, Spatie Data, QueryBuilder, or a mix) and
ships its generated `openapi.yaml` next to its code.

## Architecture Overview

The subsystem is split into a convention-agnostic **Core** (`src/Core/`) and **Plugins**
(`src/Plugins/`) that teach Core about specific packages. The package ships one plugin —
**SpatieData** — and a `Plugin` interface so you can write your own.

```
                                    ┌─ DocCommentParser ─ summary / description / @throws
Laravel routes                       │
              │                     ├─ UriParametersExtractor ─ path params + regex constraints
              ▼                     │
       RouteIntrospector ────────── ActionDescriptor ──────► OperationBuilder
                                                                    │
                                                                    │ for each resolver (from OpenApiRegistry):
                                                                    ▼
                                    ┌── QueryParameterResolver(s)
                                    ├── RequestSchemaResolver(s)      ← SpatieDataPlugin: DataClassRequestSchemaResolver
                                    │                                 ← Core: FormRequestRequestSchemaResolver
                                    ├── PrimaryResponseResolver(s)
                                    ├── SecurityExtractor (auth + scope middleware)
                                    └── StandardResponsesExtractor    (@throws + middleware → error responses)
                                                                    │
                                                                    ▼
                                                          ComponentSchemaRegistry
                                                  (shared $ref pool for Data schemas)
                                                                    │
                                                                    ▼
                                                            OpenApiGenerator
                                                                    │
                                                                    ▼
                                                     OpenAPI 3.1 YAML / JSON document
```

### Plugin System

`Core` itself is package-agnostic. It ships a `Plugin` interface
(`src/Core/Registry/Plugin.php`). Plugins register resolvers, extractors, error-response
factories, payload class markers, and lint rules into an `OpenApiRegistry` instance.

```php
// The Plugin interface
namespace Radiergummi\OpenApi\Core\Registry;

interface Plugin
{
    public function register(OpenApiRegistry $registry): void;
}
```

The built-in plugins:

| Plugin | Class | Registers |
|---|---|---|
| **SpatieData** | `Plugins\SpatieData\SpatieDataPlugin` | `DataClassRequestSchemaResolver`, `DataRefSchemaResolver`, `Data::class` as a payload base, lint rules: `field.attribute-wrong-scope` (level 1), `multipart.file-without-multipart` (level 1) |
| **ApiResources** | `Plugins\ApiResources\ApiResourcesPlugin` | `ResourceResponseResolver`, `ResourceRefSchemaResolver`, lint rules: `resource.fields-undeclared` (level 1), `resource.field-type-missing` (level 2), `resource.response-ambiguous` (level 1) |
| **QueryBuilder** (opt-in) | `Plugins\QueryBuilder\QueryBuilderPlugin` | `QueryBuilderParameterResolver`, lint rules: `query-builder.params-undeclared` (level 2), `query-builder.filter-type-missing` (level 3) |
| **Fractal** (opt-in) | `Plugins\Fractal\FractalPlugin` | `FractalResponseResolver`, `TransformerRefSchemaResolver`, lint rules: `fractal.response-unbound` (level 2), `fractal.fields-undeclared` (level 1), `fractal.include-transformer-missing` (level 2), `fractal.duplicate-key` (level 1), `fractal.transformer-class-missing` (level 1) |

Plugins are listed in `config/openapi.plugins` and resolved from the container. `CoreRegistration`
runs first (registering `FormRequestRequestSchemaResolver` and all core lint rules), then each
plugin in declaration order, then any `config/openapi.lint.rules` extras.

The full wiring happens in `OpenApiServiceProvider`. All pipeline classes are bound as **scoped**
singletons — Octane resets them between requests without any manual `reset()` call.

The plugin authoring guide is at the end of this document — see [Adding a New Plugin](#adding-a-new-plugin).

### Request Payload Indirection

If your controllers inject an intermediate object (e.g. a Domain Action) instead of a Data class
directly, `PayloadParameterScanner` can descend into that object's constructor to find Data-class
parameters. List the indirection base class in `config/openapi.request_payload_indirection`. The
default is empty — controllers that type-hint a Data class directly need no configuration.

## Where to View the Spec

| URL / Command | Purpose |
|---|---|
| `GET /api/docs` | Scalar API reference — interactive playground with "Try it out" |
| `GET /api/openapi.yaml` | Raw OpenAPI 3.1 YAML — what tooling consumes |
| `php artisan openapi:generate [path]` | Regenerate the YAML file (defaults to the configured document path); pass `-` to print to stdout; pass `--format=json` for JSON output |
| `php artisan openapi:lint` | Lint documentation gaps across the API surface |
| `php artisan openapi:clear` | Drop the cached spec |

Route prefixes and URIs are configurable in `config/openapi.routes` (see
[Config Reference](#config-reference-configopenapiphp)). The playground route defaults to enabled
only when `APP_ENV` is `local`; the spec route defaults to always enabled.

## What's Auto-Derived

If you follow conventions, an endpoint is fully documented with zero attributes:

| Aspect | Source |
|---|---|
| **Tag** | Last meaningful segment of the controller's namespace. Skips generic segments (`Controllers`, `Http`, `App`, `Internal`, `External`, `Global`, `V0`, …) and the controller class itself. |
| **Summary** | First paragraph of the controller method's PHPDoc. |
| **Description** | Remaining paragraphs (markdown permitted). |
| **operationId** | Route name (if named) or `{method}_{sanitized_path}` otherwise. |
| **Path parameters** | Reflected from the action signature — type hints, `#[Where*]` regex constraints, and route-model-binding heuristics drive type/format. |
| **Request body** | Spatie Data class found on the action or its injected payload-indirection object — schema is built from PHP types + validation rules (see below). Legacy `FormRequest` is also supported. |
| **Security** | `auth:api` and `scope:*` middleware drive OAuth2 schemes on the operation. |
| **Error responses** | `@throws ExceptionClass` PHPDoc on the action maps to status codes; `auth`/`scope`/`throttle` middleware contribute 401/403/429. |
| **Validation constraints** | `Data::rules()` and Spatie validation attributes are compiled and merged into property schemas: `maxLength`, `minLength`, `pattern`, `enum`, `format`, `minimum`/`maximum`, `minItems`/`maxItems`. |

If your controllers are shaped conventionally, **stop reading here** — your endpoints are
documented. The rest of this doc is for overrides and edge cases.

## FormRequest vs Spatie Data

Both conventions document a request body — pick the one that fits the rest of your application.
The generator treats them symmetrically: validation rules become schema constraints either way,
and the lint surface is identical. The differences are in what each convention offers *beyond*
input validation.

| Aspect | `FormRequest` (Core) | Spatie `Data` class (plugin) |
|---|---|---|
| Validation rules live in | `rules()` (Laravel core) | `rules()` and/or `#[Validation*]` attributes |
| Request-side support | Yes — `FormRequestRequestSchemaResolver` (always on) | Yes — `DataClassRequestSchemaResolver` (SpatieData plugin) |
| Response-side support | No — return a typed resource (`JsonResource`) or use `#[ResponseResource]` | Yes — return a `Data`, `DataCollection<…>`, or paginated variant; `DataResponseResolver` handles it |
| Nested objects | No — flat key→rule map only | Yes — nested `Data` classes become nested component schemas via `$ref` |
| Enums | `Rule::in([...])` / `enum:` validation rule → `enum` | Native PHP enum property → `enum`; validation `Rule::in([...])` still works |
| Field-level enrichment | `#[RequestField]` on a `PARAM_*` class-constant whose value matches the field name | `#[RequestField]` on the promoted constructor parameter or property |
| Transformations / computed properties | No — runtime concern, generator reads signatures only (OAPI-017) | The `Data` class can carry `Optional`-typed and computed properties; PATCH semantics are inferred from `Optional|…\|null` |
| File / multipart | Detected from `file` / `image` / `File::…` validation rules | Same, plus a typed `UploadedFile` property is auto-detected |
| Runtime dependency | None — ships with Laravel | `spatie/laravel-data` |
| Brownfield fit | Direct: existing `FormRequest`s document themselves with no changes | Requires migrating request DTOs (or introducing them) |
| Greenfield fit | Works, but loses nesting / response symmetry | Recommended — one DTO covers both directions |

**Rules of thumb:**

- An existing Laravel codebase with `FormRequest`s in place: leave them, they document themselves.
  Reach for `Data` classes only for new endpoints where the response shape is non-trivial.
- A new project, or one already using `spatie/laravel-data`: use `Data` classes for both input
  and output. The same class can be the request body and the response payload.
- The two conventions coexist — one endpoint may inject a `FormRequest`, another may inject a
  `Data` class. The generator picks the matching resolver per action.

```php
// FormRequest — request side only
final class StoreFlightRequest extends FormRequest
{
    public const string PARAM_DEPARTURE = 'departure';

    #[OpenApi\RequestField(format: 'date-time', description: 'UTC departure time.')]
    public const string PARAM_ARRIVAL = 'arrival';

    public function rules(): array
    {
        return [
            self::PARAM_DEPARTURE => ['required', 'date'],
            self::PARAM_ARRIVAL  => ['required', 'date', 'after:' . self::PARAM_DEPARTURE],
        ];
    }
}

public function store(StoreFlightRequest $request): FlightResource { … }
```

```php
// Spatie Data — request + response from one class
final class FlightData extends Data
{
    public function __construct(
        public string $departure,

        #[OpenApi\RequestField(format: 'date-time', description: 'UTC departure time.')]
        public string $arrival,

        public FlightStatus $status,            // PHP enum → schema enum
        public ?AircraftData $aircraft = null,  // nested Data → $ref
    ) {}

    public static function rules(): array
    {
        return [
            'departure' => ['required', 'date'],
            'arrival'   => ['required', 'date', 'after:departure'],
        ];
    }
}

public function store(FlightData $payload): FlightData { … }   // both sides documented
```

The worked equivalents live in `examples/form-requests/` and `examples/spatie-data/` — each ships
its generated `openapi.yaml` next to the code so the difference is inspectable.

## Attribute Catalog

All attributes live in `Radiergummi\OpenApi\Core\Attributes`. Import with
`use Radiergummi\OpenApi\Core\Attributes as OpenApi;` and reference as `#[OpenApi\Operation(...)]`.

### Operation-level attributes

| Attribute | Target | Repeatable | Purpose |
|---|---|---|---|
| `Operation` | class, method | no | Override `summary`, `description`, `operationId`, or the auto-derived tag set. `replace: true` discards auto-derived tags; default merges. `streaming: true` advertises `text/event-stream` as the response content type. |
| `Tag` | class, method | yes | Add a tag to the already-derived set (merge, not replace). |
| `QueryParam` | class, method | yes | Document an ad-hoc query string parameter. Each instance defines one parameter. |
| `RequestBody` | method | no | Override the request-body `description`, `required`, or `mediaType` (e.g. `multipart/form-data`). |
| `ResponseResource` | class, method | no | Explicit response-resource class for the 200 response. `collection: true/false` overrides envelope detection; `null` = auto-detect. |
| `Response` | method | yes | Add an extra response by status code, with optional `ref` (a resolver-resolved class), inline `schema`, and `mediaType`. |
| `Example` | method | yes | Named example payload for the request body. |
| `ResponseExample` | method | yes | Named example for a specific response status. |
| `Header` | method | yes | Document a custom response header. |
| `Security` | class, method | no | Override the auto-derived scopes. Pass an empty list for "token required, no specific scope". Optional `scheme:` parameter targets a specific scheme name from `openapi.security_schemes` (or one of the Passport-derived defaults); omit for the project default. See [Declare custom security schemes](#declare-custom-security-schemes). |
| `PublicEndpoint` | class, method | no | Mark as public (no auth advertised) even if middleware would imply otherwise. |
| `Hide` | class, method | no | Exclude from the spec. `environments: ['production']` hides only in those environments. Pass no argument to hide unconditionally. |
| `ExternalDocs` | method | no | Add an "external documentation" link to the operation. |
| `Link` | method | yes | Declare an OpenAPI Link on the primary 2xx response. `operationId` (preferred) or `operationRef` must be provided. See [Link to another operation from a response](#link-to-another-operation-from-a-response). |
| `Discriminator` | class | no | Mark a polymorphic base class (a `Data` class or a response-resource class). Schema becomes `oneOf` + `discriminator`. See [Document a polymorphic response with a discriminator](#document-a-polymorphic-response-with-a-discriminator). |
| `Webhook` | method | no | Divert the route from `paths` into the OpenAPI 3.1 top-level `webhooks` block. `name` is the map key. |
| `IgnoreLint` | class, method, property | yes | Suppress one `openapi:lint` rule for the annotated symbol. See [Suppress a lint finding](#suppress-a-lint-finding). |
| `#[\Deprecated]` (PHP native) | class, method | no | Marks the operation `deprecated: true` and appends the message to the description. |

### Field-enrichment attributes

`FieldAttribute` has four **scoped** subclasses. Pick the one that matches the target:

| Attribute | Target | Scope | Notes |
|---|---|---|---|
| `RequestField` | property, parameter, class-constant | Request-body input fields | Place on a Spatie Data class property / promoted constructor parameter, or on a `FormRequest` field constant. Supports `writeOnly`. No `readOnly` or `default`. |
| `ResponseField` | class-constant, property | Response output fields | Place on a response class field constant or property. Supports `readOnly` and `conditional`. `conditional: true` keeps the field in `properties` but removes it from `required` — use for conditionally-present fields. |
| `PathParam` | parameter | URI path parameters | Place on a controller action parameter for a route-bound model or scalar segment. Only `description`, `example`, `format`, and `pattern` apply (type is inferred from the binding). |
| `QueryParam` | class, method | Ad-hoc query parameters | See operation-level table above. |

All four subclasses share the same JSON Schema field surface inherited from `FieldAttribute`:
`title`, `description`, `example`, `type`, `format`, `nullable`, `enum`, `minimum`/`maximum`,
`exclusiveMinimum`/`exclusiveMaximum`, `multipleOf`, `minLength`/`maxLength`, `pattern`,
`minItems`/`maxItems`, `uniqueItems`, `readOnly`, `writeOnly`.

### Exception-level attribute

| Attribute | Target | Purpose |
|---|---|---|
| `ExceptionResponse` | exception class | Declare the HTTP status and description that this exception produces. Checked before `config/openapi.exception_responses`. |

## Common Recipes

### Override the summary or description

```php
class ProjectController extends Controller
{
    /**
     * Retrieve a project.
     *
     * Returns the project envelope including its phases and supplier counts.
     */
    public function show(Project $project): ProjectResource
    {
        // …
    }
}
```

Most cases don't need an attribute — the PHPDoc is the source of truth. Reach for
`#[OpenApi\Operation]` only when the PHPDoc has to say something different from the spec.

### Document an ad-hoc query parameter

```php
#[OpenApi\QueryParam('q', description: 'Free-text search query.', example: 'cnc machining')]
#[OpenApi\QueryParam('limit', type: 'integer', default: 25, maximum: 100)]
public function search(Request $request): JsonResponse { … }
```

### Enrich a request-body field

```php
class CreateProjectData extends Data
{
    public function __construct(
        #[OpenApi\RequestField(description: 'Display name.', example: 'Acme aerospace sourcing', maxLength: 250)]
        public string $name,

        #[OpenApi\RequestField(format: 'uri', description: 'Optional callback URL.')]
        public ?string $webhookUrl = null,
    ) {}
}
```

`RequestField` is layered **on top of** type-derived and rule-derived schema — use it for
documentation enrichment; let validation rules carry constraints.

### Enrich a response field

```php
class ProjectResource extends JsonResource
{
    #[OpenApi\ResponseField(description: 'ISO 8601 creation timestamp.', example: '2024-01-15T10:30:00Z')]
    public const string FIELD_CREATED_AT = 'created_at';

    #[OpenApi\ResponseField(readOnly: true, description: 'Computed supplier match count.')]
    public const string FIELD_MATCH_COUNT = 'match_count';

    // For conditionally-present fields:
    #[OpenApi\ResponseField(conditional: true, description: 'Loaded only when ?include=phases is requested.')]
    public const string RELATIONSHIP_PHASES = 'phases';
}
```

### Annotate a path parameter

```php
public function single(
    #[OpenApi\PathParam(description: 'The project to retrieve.', example: '01HFP…')]
    Project $project,
): ProjectResource { … }
```

### Add an error response that isn't in `@throws`

```php
/**
 * @throws ValidationException
 */
#[OpenApi\Response(status: 409, description: 'Project name already exists.')]
public function store(CreateProjectData $data): ProjectResource { … }
```

### Make an exception self-describing

Instead of adding an entry to `config/openapi.php`, decorate the exception class:

```php
#[OpenApi\ExceptionResponse(status: 418, description: "I'm a teapot")]
class TeapotException extends RuntimeException {}
```

Anywhere this exception appears in a controller's `@throws`, it's mapped automatically.

### Multipart / file upload

A Spatie Data class with an `UploadedFile` property (or a `file` validation rule) auto-switches
the request body to `multipart/form-data` with `format: binary` on the relevant field. For
request bodies not backed by a Data class:

```php
#[OpenApi\RequestBody(description: 'Webhook payload', mediaType: 'application/x-www-form-urlencoded')]
public function webhook(Request $request): Response { … }
```

### Document a streaming endpoint (SSE / `text/event-stream`)

Streaming content types are **not** auto-detected. Advertise a streaming response explicitly with
`#[OpenApi\Operation(streaming: true)]`:

```php
#[OpenApi\Operation(streaming: true)]
public function stream(): StreamedResponse
{
    return new StreamedResponse(static function (): void {
        // emit SSE frames
    });
}
```

To document a per-event payload schema, override the 200 response and set its media type
explicitly:

```php
use Radiergummi\OpenApi\Core\Enums\MediaType;

#[OpenApi\Operation(streaming: true)]
#[OpenApi\Response(
    status: 200,
    description: 'SSE stream — one JSON object per event',
    schema: [
        'type' => 'object',
        'properties' => [
            'type'    => ['type' => 'string', 'enum' => ['match', 'done', 'error']],
            'payload' => ['type' => 'object'],
        ],
    ],
    mediaType: MediaType::EventStream,
)]
public function stream(): StreamedResponse { … }
```

### Link to another operation from a response

```php
#[OpenApi\Link(
    name: 'GetProject',
    operationId: 'api.v0.projects.single',
    parameters: ['uuid' => '$response.body#/data/uuid'],
    description: 'Retrieve the newly created project.',
)]
public function create(CreateProjectData $data): ProjectResource { … }
```

Multiple `#[Link]` attributes may be stacked. Common runtime expressions:

| Expression | Resolves to |
|---|---|
| `$response.body#/data/uuid` | Field from the JSON response body |
| `$request.body#/name` | Field echoed from the request body |
| `$url` | The full request URL |

Use `operationId` (preferred) for intra-document links, or `operationRef` (a JSON Pointer) for
cross-document links. Exactly one must be provided. Links attach to the primary 2xx response only.

### Document a polymorphic response with a discriminator

```php
#[OpenApi\Discriminator(
    propertyName: 'type',
    mapping: [
        'circle'    => CircleData::class,
        'rectangle' => RectangleData::class,
    ],
)]
abstract class ShapeData extends Data {}
```

Each variant is registered as its own component schema. The base class becomes:

```yaml
ShapeData:
  oneOf:
    - $ref: '#/components/schemas/CircleData'
    - $ref: '#/components/schemas/RectangleData'
  discriminator:
    propertyName: type
    mapping:
      circle: '#/components/schemas/CircleData'
      rectangle: '#/components/schemas/RectangleData'
```

### Hide an endpoint from production docs

```php
#[OpenApi\Hide(environments: ['production'])]
public function dangerous(): JsonResponse { … }
```

Pass no argument (`#[OpenApi\Hide]`) to hide unconditionally.

### Declare custom security schemes

By default the package emits Laravel Passport's `oauth2` (Authorization Code) and
`oauth2ClientCredentials` schemes when Passport is installed. Apps using a different auth
shape — plain bearer JWT, API key, basic auth — declare additional schemes via the
`openapi.security_schemes` config map:

```php
// config/openapi.php
'security_schemes' => [
    'bearer' => [
        'type'         => 'http',
        'scheme'       => 'bearer',
        'bearerFormat' => 'JWT',
        'description'  => 'Bearer JWT issued by the auth service.',
    ],
],
```

Each entry passes through to swagger-php's `OA\SecurityScheme` unchanged; the map key
becomes the scheme name. Config entries are merged with the Passport-derived pair (config
wins on key collision), and operations point at a specific scheme through `#[Security]`:

```php
#[OpenApi\Security(['flights:write'], scheme: 'bearer')]
public function store(StoreFlightRequest $request): FlightData { … }
```

Omit `scheme:` to fall back to the project default (Passport's pair when available, otherwise
the first config-declared scheme). The combined-flavor example
(`examples/combined/`) demonstrates both halves end-to-end.

### Document an inbound webhook

```php
#[OpenApi\Webhook(name: 'stripe.webhook')]
#[OpenApi\PublicEndpoint]
#[OpenApi\RequestBody(description: 'Stripe event payload', mediaType: 'application/json')]
public function handleWebhook(Request $request): Response { … }
```

The route still exists — the generator extracts it normally and diverts it to `webhooks` in the spec.

### Force the response resource

```php
#[OpenApi\ResponseResource(SupplierResource::class, collection: true)]
public function index(Request $request): JsonResponse { … }
```

Use this when the resource-resolution heuristic fails (you'll see warnings during generation if so).

### Available plugins

| Plugin | Default | Requires | Documents |
|---|---|---|---|
| `SpatieDataPlugin` | enabled | `spatie/laravel-data` | Data-class request bodies |
| `ApiResourcesPlugin` | enabled | — (Laravel core) | `JsonResource` / `ResourceCollection` responses |
| `QueryBuilderPlugin` | disabled | `spatie/laravel-query-builder` | `filter[]` / `sort` / `include` query parameters |
| `FractalPlugin` | disabled | `league/fractal` | Fractal transformer responses |

### Document an Eloquent API Resource (`JsonResource`)

The `ApiResourcesPlugin` is enabled by default. Controllers that return a typed `JsonResource`
subclass are documented automatically — no attribute needed for the response envelope.

To declare the fields the resource emits, add `#[ResourceField]` attributes at the class level:

```php
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

#[ResourceField('id', type: 'integer')]
#[ResourceField('name', type: 'string', description: 'Display name.')]
#[ResourceField('created_at', type: 'string', format: 'date-time')]
class ProjectResource extends JsonResource { … }
```

For a field whose type is another resource, pass its class-string as `type`:

```php
#[ResourceField('owner', type: UserResource::class, description: 'Owning user.')]
class ProjectResource extends JsonResource { … }
```

For collection endpoints that return a `JsonResponse` or an untyped value, tell the generator
which resource and envelope to use:

```php
#[OpenApi\ResponseResource(ProjectResource::class, collection: true)]
public function index(): JsonResponse { … }
```

Single responses wrap fields in `{ data: {…} }`; collection responses wrap them in
`{ data: [{…}], links: {…}, meta: {…} }`. Omitting `#[ResourceField]` attributes triggers the
`resource.fields-undeclared` lint rule (level 1).

### Document `spatie/laravel-query-builder` parameters

The `QueryBuilderPlugin` is shipped disabled. Enable it in two steps:

1. `composer require spatie/laravel-query-builder` (the package itself).
2. Uncomment the `QueryBuilderPlugin::class` entry under `plugins` in `config/openapi.php`.

Then declare the accepted parameters on the controller method:

```php
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;

#[AllowedFilter('status', type: 'string')]
#[AllowedFilter('created_after', type: 'string', format: 'date')]
#[AllowedSort(['name', 'created_at'])]
#[AllowedInclude(['owner', 'tags'])]
public function index(QueryBuilder $query): JsonResponse { … }
```

Each `#[AllowedFilter]` becomes one `filter[name]` query parameter; `#[AllowedSort]` becomes the
`sort` parameter (comma-separated, with the listed fields as `enum`); `#[AllowedInclude]` becomes
`include` the same way. A method that injects `QueryBuilder` but declares none of the three
triggers `query-builder.params-undeclared` (level 2); an `#[AllowedFilter]` without a `type`
triggers `query-builder.filter-type-missing` (level 3).

### Document `league/fractal` transformer responses

The `FractalPlugin` is shipped disabled. Enable it in two steps:

1. `composer require league/fractal` (the package itself).
2. Uncomment the `FractalPlugin::class` entry under `plugins` in `config/openapi.php`.

Declare each transformer's output keys on the transformer class with repeatable
`#[TransformerField]` attributes; declare `availableIncludes` / `defaultIncludes` entries with
`#[TransformerInclude]`. Bind each endpoint to its transformer with a method-level
`#[FractalResponse]`:

```php
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;

#[TransformerField('id', type: 'integer')]
#[TransformerField('title', type: 'string', maxLength: 120)]
#[TransformerInclude('author', transformer: AuthorTransformer::class, default: true)]
final class BookTransformer extends TransformerAbstract { … }

#[FractalResponse(transformer: BookTransformer::class)]                    // {data}
public function show(): JsonResponse { … }

#[FractalResponse(transformer: BookTransformer::class, collection: true)]  // {data: [...]}
public function index(): JsonResponse { … }

#[FractalResponse(transformer: BookTransformer::class, paginated: true)]   // {data: [...], meta.pagination}
public function paginated(): JsonResponse { … }
```

The default envelope models Fractal's `DataArraySerializer` plus `IlluminatePaginatorAdapter`.
Set `serializer:` on `#[FractalResponse]` when the action calls `Manager::setSerializer(…)` to
switch shape:

```php
use Radiergummi\OpenApi\Plugins\Fractal\Serializer;

#[FractalResponse(transformer: BookTransformer::class, serializer: Serializer::ArraySerializer)]
public function arraySingle(): JsonResponse { … }      // bare $ref, no envelope

#[FractalResponse(transformer: BookTransformer::class, collection: true, serializer: Serializer::ArraySerializer)]
public function arrayCollection(): JsonResponse { … } // top-level array

#[FractalResponse(transformer: BookTransformer::class, serializer: Serializer::JsonApi)]
public function jsonApiShow(): JsonResponse { … }     // {data: {type, id, attributes: $ref}} as application/vnd.api+json
```

`Serializer::JsonApi` responses are emitted under `application/vnd.api+json` instead of
`application/json`. Custom serializers outside the three named cases (project-specific
subclasses, anything else) fall back to a `#[Response]` override on the action.

Lint rules report incomplete or invalid declarations: a transformer with no
`#[TransformerField]` triggers `fractal.fields-undeclared` (level 1); a `#[TransformerInclude]`
with no `transformer:` triggers `fractal.include-transformer-missing` (level 2); a transformer
that declares the same key in more than one attribute triggers `fractal.duplicate-key` (level 1);
`#[FractalResponse]` naming a non-existent transformer triggers `fractal.transformer-class-missing`
(level 1); and a method that injects `League\Fractal\Manager` but declares no `#[FractalResponse]`
triggers `fractal.response-unbound` (level 2 — opt-in, because the `fractal()` helper and the
`Spatie\Fractalistic\Fractal` facade are invoked inside method bodies and never inject a
`Manager`, and the generator does not read method bodies; see OAPI-017 / OAPI-053).

### Programmatic hook points

For cases that can't be expressed with authoring attributes, `OpenApiExtensions` exposes three
static hook points:

#### Operation transformer

Invoked once per assembled operation, after all attributes and extractors have run:

```php
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Core\Extensions\OperationContext;
use OpenApi\Annotations as OA;

OpenApiExtensions::transformOperation(
    static function (OA\Operation $operation, OperationContext $ctx): void {
        if (str_contains($ctx->routeUri, 'webhooks/stripe')) {
            $operation->tags = ['Stripe'];
        }
    },
);
```

`OperationContext` exposes `$descriptor` (full `ActionDescriptor`), `$httpMethod`,
`controllerClass()`, `methodName()`, `routeUri()`.

#### Schema transformer

Invoked once per component schema. Primary escape hatch for custom `Rule` objects the generic
extractor cannot handle:

```php
OpenApiExtensions::transformSchema(
    static function (OA\Schema $schema, SchemaContext $ctx): void {
        if ($ctx->sourceClass === null) {
            return;
        }
        foreach ($schema->properties ?? [] as $property) {
            if ($property->property === 'color') {
                $property->pattern = '^#[0-9a-fA-F]{6}$';
            }
        }
    },
);
```

`SchemaContext` exposes `$componentKey` (the `components.schemas` key) and `$sourceClass` (the PHP
class the schema was derived from; `null` for hand-built schemas).

#### Document transformer

Invoked once on the fully assembled `OA\OpenApi` document before it is returned:

```php
OpenApiExtensions::transformDocument(
    static function (OA\OpenApi $document): void {
        $document->{'x-api-version'} = config('app.version');
    },
);
```

#### Flushing (tests)

`OpenApiExtensions::flush()` removes all registered transformers. Call it in `afterEach()` when
testing code that registers transformers.

## Validation Rules → Schema Constraints

For Spatie Data classes, `SchemaFromDataClass` calls `YourData::getValidationRules($payload)` —
Spatie's own resolver — then maps the compiled rules array into JSON Schema constraints:

| Laravel rule | Schema field |
|---|---|
| `max:N` (string) | `maxLength: N` |
| `max:N` (numeric) | `maximum: N` |
| `max:N` (array) | `maxItems: N` |
| `min:N` | symmetric — `minLength` / `minimum` / `minItems` |
| `between:a,b` | both `min`/`max` of the appropriate kind |
| `size:N` | both min and max set to `N` |
| `regex:/…/` | `pattern: …` (delimiter stripped) |
| `in:a,b,c` (or `Rule::in([...])`) | `enum: ['a', 'b', 'c']` |
| `email`/`url`/`uuid`/`ip`/`ipv4`/`ipv6` | `format: …` |
| `date` / `date_format:Y-m-d` | `format: date` |
| `date_format:H:i:s` (time-only tokens) | `format: time` |
| `date_format` with date+time tokens | `format: date-time` |
| `file`/`image` (string rule) | `type: string, format: binary` + switches body to multipart |
| `digits:N` | `pattern: '^\d{N}$'` |
| `nullable` | `nullable: true` |
| `Password::min(N)` | `type: string, format: password, minLength: N` |
| `Password::…->letters()->numbers()` | `description` listing active character-class requirements |
| `File::types([…])` | `type: string, format: binary`; `description` with allowed types and size bounds |
| `File::image()` / `ImageFile` | same as `File` + image dimensions in `description` when `.dimensions(…)` is chained |
| `Dimensions` (standalone) | `type: string, format: binary`; `description` with all dimension constraints |

**PATCH semantics:** properties typed as `Optional|string|null` (Spatie's `Optional`) are stripped
from the schema's `required` list even if `rules()` says `required`. The PHP-type pass is
authoritative for "is this field required?".

**FormRequest** support is symmetric — `SchemaFromFormRequest` runs the same
`ValidationRulesToSchema` mapper against `rules()` output.

**Dotted-key rules (one level):** `foo.*` rules are applied to the parent field's `items`
schema — `'tags.*' => ['string', 'max:10']` yields
`tags: { type: array, items: { type: string, maxLength: 10 } }`. Deeper paths (`foo.*.bar`) are
not yet supported and are silently dropped.

**Custom Rule objects:** `ValidationRulesToSchema` handles the built-in Laravel rule classes
(`Password`, `File`, `ImageFile`, `Dimensions`, `In`, `Enum`). Any other rule object — including
project-local `Rule` implementations — is silently ignored. Use a schema transformer to inject
constraints for these (see [Schema transformer](#schema-transformer) above).

## Lint System (`openapi:lint`)

`php artisan openapi:lint` generates the spec then walks a domain tree to check convention and
completeness. It is fully independent of the generation pipeline from the consumer's perspective.

### How it works

1. The command generates the spec (optionally restricted by `--path` or `--diff`).
2. `SpecTreeBuilder` converts the raw `OA\OpenApi` graph into a typed node tree (`ApiNode`,
   `OperationNode`, `ResponseNode`, `FieldNode`, etc.) in `Core/Lint/Tree/`.
3. `SpecTreeWalker` dispatches each node to rules that implement the matching visitor interface
   (`OperationRule`, `ResponseRule`, `FieldRule`, `ParameterRule`, `HeaderRule`, `LinkRule`,
   `WebhookRule`, `ComponentSchemaRule`, `ApiRule`, `QueryParameterRule`, `RequestBodyRule`,
   `ExampleRule`). Rules may also implement `Finalizable` (called after each operation's sub-tree)
   or `Resettable` (reset between operations).
4. `MetaSuppressionStale` runs as a `PostWalkRule` after the tree walk, because it needs the
   complete findings set.
5. Findings are filtered to the active severity level, suppressed by `#[IgnoreLint]` directives,
   and formatted for output.

### Severity levels

Rules carry a numeric severity level. The scale is an **open-ended gradient of decreasing
severity** (lower = more severe), modelled on PHPStan — levels are not fixed categories, and finer
levels may be added over time. The `--level` flag (or `config/openapi.lint.level`) sets the
threshold: only rules at or below it run.

| Level | Name | Meaning |
|---|---|---|
| 0 | Broken | A conformant OpenAPI validator rejects the document, or a major consumer (Scalar, codegen) fails outright. |
| 1 | Degraded | The document parses, but a violation makes part of it wrong — it lies about the API, drops information, or misbehaves in tooling. |
| 2 | Underspecified | Correct but incomplete — a component that should carry detail is missing it. |
| 3 | Inconsistent | Complete and correct, but violates a naming/structure convention or a hygiene meta-rule. |
| 4 | Improvable | Optional polish whose absence costs nothing concrete. |

Pass `--level=max` to run every rule regardless of level. The default threshold is **level 1**
(broken + degraded).

### Lint commands

```bash
# Default: level 1
php artisan openapi:lint

# Run all rules
php artisan openapi:lint --level=max

# Target specific rules
php artisan openapi:lint --only=summary.missing,operation.description-missing

# Exclude rules
php artisan openapi:lint --skip=tags.no-description

# Restrict to routes matching a URI glob
php artisan openapi:lint --path='api/v0/projects*'

# Restrict to routes touched since the merge-base with develop
php artisan openapi:lint --diff

# Restrict to routes touched since a specific git ref
php artisan openapi:lint --diff=main

# Ignore all #[IgnoreLint] suppressions
php artisan openapi:lint --no-suppress

# Output formats (auto-detected: cli in terminal, github in CI, json otherwise)
php artisan openapi:lint --format=json
php artisan openapi:lint --format=github

# Print the rule catalog instead of linting
php artisan openapi:lint --list
```

### Suppress a lint finding

Use the `#[OpenApi\IgnoreLint]` attribute. Each instance suppresses exactly one rule; stack the
attribute for several. Always pass a `reason`:

```php
use Radiergummi\OpenApi\Core\Attributes as OpenApi;

#[OpenApi\IgnoreLint('response.no-error', reason: 'Internal-only endpoint, errors are handled by the framework')]
public function internal(): JsonResponse { … }
```

Scope follows the annotated symbol:

- **Class** — silences the rule for every operation in the controller.
- **Method** — silences it for that action only.
- **Property** — silences `field.*` findings for that property; place it on the Data-class property.

`spec.invalid` can never be suppressed. Run with `--no-suppress` to ignore all directives.

Meta-rules enforce directive hygiene:

- `meta.no-suppression-reason` — directive has no `reason` parameter.
- `meta.suppression-stale` — directive did not suppress any finding.
- `meta.too-many-suppressions` — a symbol carries an excessive number of directives.

### Lint rule catalog

All built-in rule IDs (run `php artisan openapi:lint --list` for the live catalog):

<!-- BEGIN: lint-rule-catalog -->
| Rule ID | Level | Description |
|---|---|---|
| `discriminator.invalid-mapping` | 0 | Discriminator mapping references a missing component schema. |
| `field.enum-mismatch` | 0 | Enum value type doesn't match the field's declared type. |
| `link.both-operation-id-and-ref` | 0 | Link declares both operationId and operationRef (mutually exclusive). |
| `link.duplicate-name` | 0 | Two links on the same response share the same name. |
| `link.invalid-parameter` | 0 | Link references a parameter that the target operation doesn't declare. |
| `link.neither-operation-id-nor-ref` | 0 | Link has neither operationId nor operationRef. |
| `link.parameter-required-missing` | 0 | Link omits a parameter that the target operation requires. |
| `operation.id-duplicate` | 0 | Two operations share the same operationId. |
| `parameter.duplicate-name` | 0 | Two parameters in the same operation share the same name and location. |
| `parameter.path-must-be-required` | 0 | Path parameter is not marked required: true. |
| `parameter.query-no-schema` | 0 | Query parameter has no schema. |
| `path.parameter-undeclared` | 0 | Path template uses a variable not declared as a parameter. |
| `path.parameter-undefined` | 0 | A declared path parameter doesn't appear in the path template. |
| `queryparam.duplicate` | 0 | Two #[QueryParam] attributes on the same controller/method share the same name. |
| `ref.broken` | 0 | A $ref points to a component that doesn't exist in the spec. |
| `response.description-missing` | 0 | Response has no description. OAS 3.1 requires description on every Response Object. |
| `response.duplicate-status` | 0 | Two responses on the same operation share the same status code. |
| `schema.enum-type-mismatch` | 0 | Schema enum contains values that don't match the declared type. |
| `schema.required-without-property` | 0 | required names a field not in properties. |
| `security.scheme-undefined` | 0 | Operation references a security scheme not declared at the document level. |
| `server.invalid-url` | 0 | A servers[].url is malformed. |
| `server.variable-undeclared` | 0 | A server URL template uses a {var} with no matching variables entry. |
| `spec.invalid` | 0 | Spec fails swagger-php validation. Cannot be suppressed or remapped. |
| `tag.duplicate` | 0 | Two top-level tag definitions share the same name. |
| `webhook.name-duplicate` | 0 | Two webhooks share the same name. |
| `externaldocs.invalid-url` | 1 | externalDocs.url is not a valid URL. |
| `field.attribute-wrong-scope` | 1 | #[RequestField] on a URI parameter, or #[PathParam] on a Data-class property. |
| `field.conflicting-type` | 1 | Field declares conflicting type and format values. |
| `header.invalid-name` | 1 | Header name contains invalid characters. |
| `link.invalid-operation` | 1 | Link references an operationId that doesn't exist in the document. |
| `multipart.file-without-multipart` | 1 | Data class has a file property but the request body isn't multipart/form-data — produces an incorrect spec. |
| `operation.id-invalid-chars` | 1 | operationId is not a codegen-safe identifier. |
| `operation.id-missing` | 1 | Operation has no operationId. |
| `operation.security-missing` | 1 | Route enforces auth middleware but the operation declares no security, implying the endpoint is public. |
| `operation.tag-missing` | 1 | Operation has no tags. |
| `parameter.example-conflict` | 1 | A parameter sets both example and examples (mutually exclusive). |
| `parameter.query-array-no-explode` | 1 | Array query parameter is missing explode: true. |
| `publicendpoint.contradicts-middleware` | 1 | #[PublicEndpoint] is present but the route has auth/scope middleware. |
| `request-body.no-content` | 1 | A requestBody object has no media-type entries. |
| `request-body.on-get-or-delete` | 1 | GET or DELETE operation has a request body. |
| `resource.fields-undeclared` | 1 | An API Resource used as a response declares no #[ResourceField] attributes. |
| `resource.response-ambiguous` | 1 | A resource collection response has no #[ResponseResource] naming its item class. |
| `response.no-error` | 1 | Operation has no error responses (4xx/5xx). |
| `response.resource.indeterminate` | 1 | Controller return type cannot be resolved to a concrete response resource. |
| `responseresource.unresolvable` | 1 | #[ResponseResource] references a class that is not a resolvable response resource. |
| `schema.allof-type-conflict` | 1 | allOf members declare conflicting type values. |
| `schema.enum-empty` | 1 | A schema declares an empty enum (enum: []) and is unsatisfiable. |
| `schema.nullable-via-deprecated-keyword` | 1 | Schema uses the deprecated OpenAPI 3.0 nullable: true keyword instead of a type array. |
| `security.invalid-scope` | 1 | Operation requires a scope not declared in securitySchemes. |
| `streaming.no-content-type` | 1 | Streaming operation has no content-type: text/event-stream response. |
| `throws.transitive-missing` | 1 | An action's handler declares @throws exceptions not redeclared on the controller method. |
| `enum.values-undocumented` | 2 | Enum field has no description explaining the allowed values. |
| `field.description-missing` | 2 | Schema property has no description. |
| `header.description-missing` | 2 | Response header has no description. |
| `info.description-missing` | 2 | The document info.description is empty. |
| `operation.description-missing` | 2 | Operation has no description (beyond the summary). |
| `parameter.description-missing` | 2 | Parameter has no description. |
| `request-body.description-missing` | 2 | requestBody has no description. |
| `request.empty` | 2 | POST/PUT/PATCH action has no resolvable request-body schema. Add a Data class or FormRequest. |
| `resource.field-type-missing` | 2 | A #[ResourceField] is declared without a resolvable type. |
| `response.empty` | 2 | Non-DELETE action has no resolvable response schema. Return a typed resource or add #[Response]. |
| `response.no-success` | 2 | Operation has no 2xx response. |
| `response.redirect-without-location` | 2 | 3xx response has no Location header. |
| `rule.unknown` | 2 | A Laravel validation Rule object cannot be mapped to a JSON Schema constraint and was dropped. |
| `schema.description-missing` | 2 | Named component schema has no description. |
| `summary.missing` | 2 | Operation has no summary. |
| `tags.no-description` | 2 | Document-level tag has no description. |
| `throws.unmapped` | 2 | A @throws FQCN has no entry in the exception map or #[ExceptionResponse] attribute. |
| `webhook.description-missing` | 2 | Webhook operation has no description. |
| `component.name-naming-inconsistent` | 3 | Component schema name does not follow the configured component_name_case convention. |
| `component.orphaned` | 3 | Component schema is registered but never referenced. |
| `deprecated.attribute` | 3 | A deprecated authoring attribute (#[Deprecated] or @deprecated) is still used on a controller. |
| `field.invalid-format` | 3 | format value is not a recognised OAS 3.1 format (custom formats are advisory but non-standard). |
| `field.name-naming-inconsistent` | 3 | Field name doesn't follow the configured property_name_case convention. |
| `field.no-effect` | 3 | A field attribute was applied but has no visible effect on the schema. |
| `header.name-naming-inconsistent` | 3 | Header name doesn't follow the configured header_case convention. |
| `meta.no-suppression-reason` | 3 | #[IgnoreLint] has no reason parameter. |
| `meta.too-many-suppressions` | 3 | Symbol carries an excessive number of suppression directives. |
| `meta.unknown-rule` | 3 | #[IgnoreLint] references a rule ID not in the registry. |
| `operation.id-naming-inconsistent` | 3 | operationId doesn't follow the configured operation_id_case convention. |
| `operation.summary-equals-description` | 3 | Operation summary and description are identical (redundant). |
| `parameter.name-naming-inconsistent` | 3 | Parameter name doesn't follow the configured parameter_name_case convention. |
| `path.segment-naming-inconsistent` | 3 | URL path segment doesn't follow the configured path_segment_case convention. |
| `path.trailing-slash-inconsistent` | 3 | Trailing-slash usage is inconsistent across paths. |
| `response.status-unconventional` | 3 | Response uses a status code that is unusual for the HTTP method. |
| `scope.overly-broad` | 3 | Operation requires a scope that is broader than the resource warrants. |
| `tag.name-naming-inconsistent` | 3 | Tag name doesn't follow the configured tag_case convention. |
| `tag.undeclared-at-root` | 3 | Operation uses a tag not declared in the document-level tags array. |
| `deprecated.no-replacement` | 4 | Deprecated operation/field has no x-replacement or suggested alternative. |
| `deprecated.no-sunset-date` | 4 | Deprecated operation has no x-sunset date. |
| `info.metadata-incomplete` | 4 | The document info is missing contact and/or license. |
| `parameter.example-missing` | 4 | Parameter has no example. |
| `request-body.example-missing` | 4 | requestBody has no example. |
| `response.example-missing` | 4 | Response media type has no example. |
| `schema.constraints-missing` | 4 | A string has no maxLength, an array no maxItems, or a number no bounds. |
| `schema.example-missing` | 4 | Schema property has no example value. |
<!-- END: lint-rule-catalog -->

### Style conventions (naming rules)

Naming rules read their expected case convention from `config/openapi.lint.style`:

| Config key | Default | Affected rule |
|---|---|---|
| `operation_id_case` | `dot` | `operation.id-naming-inconsistent` |
| `property_name_case` | `camel` | `field.name-naming-inconsistent` |
| `path_segment_case` | `kebab` | `path.segment-naming-inconsistent` |
| `parameter_name_case` | `snake` | `parameter.name-naming-inconsistent` |
| `tag_case` | `pascal` | `tag.name-naming-inconsistent` |
| `header_case` | `train` | `header.name-naming-inconsistent` |

Supported case values: `dot`, `kebab`, `snake`, `camel`, `pascal`, `train`, `screaming_snake`.

### Adding a custom lint rule

1. Implement `Radiergummi\OpenApi\Core\Lint\Rules\Rule` and one or more visitor interfaces from
   `Core/Lint/Rules/Visitors/`.
2. Add the class to `config/openapi.lint.rules` (or register it in a plugin via
   `$registry->addRule(YourRule::class)`).

## Config Reference (`config/openapi.php`)

| Key | Purpose |
|---|---|
| `info` | Populates the top-level `info` object (`title`, `version`, `description`, etc.). |
| `servers` | List of `OA\Server` entries. Default uses `APP_URL`. |
| `tags` | Document-level tag descriptions keyed by tag name. |
| `exception_responses` | Maps exception FQCNs to `['status', 'description']`. Checked after `#[ExceptionResponse]` attributes. |
| `middleware_responses` | Toggles for 401/403/429 responses derived from `auth`/`scope`/`throttle` middleware. |
| `plugins` | Ordered list of `Plugin` class-strings. Ships with `SpatieDataPlugin`. |
| `request_payload_indirection` | Base classes whose constructors are also scanned for Data-class parameters. |
| `routes` | Spec/playground route registration: `enabled`, `prefix`, `middleware`, `spec` (`enabled`, `uri`), `playground` (`enabled`, `uri`). The playground defaults to enabled only when `APP_ENV` is `local`. |
| `filters` | Route-exclusion filters. Ships with filters that exclude Nova, Telescope, and Ignition routes. |
| `lint.level` | Default severity level when `--level` is not passed to `openapi:lint`. |
| `lint.enabled_rules` | `null` = all rules at or below the level. A non-null array is an explicit allowlist. |
| `lint.disabled_rules` | Always-off rules regardless of level. `spec.invalid` cannot be disabled. |
| `lint.severity_overrides` | Per-rule level remap: `'rule.id' => level`. `spec.invalid` is exempt. |
| `lint.style` | Per-convention case expectations for naming rules (see table above). |
| `lint.baseline` | Path to a baseline file; `null` disables the baseline feature. |
| `lint.rules` | Extra custom rule class-strings appended to the registry. |

## Troubleshooting

Indexed by symptom. Each entry names the cause and the smallest change that fixes it.

### First step: read the generation log

`php artisan openapi:generate` writes warnings to the Laravel log for anything an extractor
couldn't introspect. Skim them before anything else:

- `[OpenAPI] Response schema introspection failed for …` — the primary-response resolver could
  not resolve a return type. Add `#[ResponseResource(YourResource::class)]` or type the return.
- `[OpenAPI] Schema introspection failed for FormRequest …` — the FormRequest's `rules()` threw
  (often because it calls `auth()->user()` or another container-only helper during boot). The
  body schema falls back to a bare object.
- `[OpenAPI] Skipping validation rule extraction for …Data: …` — same story for a Data class.
  The endpoint still appears, but the schema is type-only with no rule-derived constraints.
- `[OpenAPI] … reflection failure …` — a plugin response resolver hit a missing class between
  attribute resolution and schema build. Check the FQCN names a real class.

### My endpoint doesn't appear in the generated spec

Check, in order:

1. `php artisan route:list` shows the route. If not, it isn't registered — the generator only
   sees what Laravel sees.
2. The route's controller is a real class with a real method. Closure routes are supported, but
   only carry whatever an inline closure can declare — no `@throws` PHPDoc, often no return type.
3. The route isn't excluded by a configured `RouteFilter`. The shipped filters skip Nova,
   Telescope, Ignition, and (when present) Laravel Passport routes. Inspect
   `config/openapi.filters` and remove any filter you don't want.
4. The action does not carry `#[OpenApi\Hide]` (unconditionally or in the current `APP_ENV`).
5. `php artisan openapi:clear` then regenerate — a stale cached spec masks new routes.

### Request body is empty (`request.empty` lint finding)

The action does not type-hint a request DTO the generator recognises. Either:

- Type-hint a Spatie `Data` subclass (or a `FormRequest`) directly on the action signature, or
- Type-hint an indirection object (e.g. a Domain Action) and list its base class in
  `config/openapi.request_payload_indirection` so `PayloadParameterScanner` descends into it.

### Why doesn't my inline `$request->validate(...)` produce a request body?

This is the OAPI-017 method-body inference gap. The generator reads *signatures* only — it never
parses controller method bodies, so inline `validate()`, `request()->validate()`, and ad-hoc
`response()->json([…])` calls are invisible. The fix is one of:

- Move the inline rules into a `FormRequest` and type-hint it on the action.
- Move them into a `Data` class and type-hint that.
- Or declare the body with `#[OpenApi\RequestBody]` + an explicit schema for the case the
  validation lives only at the call site.

See `docs/known-gaps.md#oapi-017--no-method-body-inference` for the full rationale.

### Response is a bare `200 OK` with no schema (`response.empty` lint finding)

Causes, in order of likelihood:

- **No return type on the action.** Add one. A typed `JsonResource`, `ResourceCollection`,
  `Data`, `DataCollection<…>`, or a paginator return is documented automatically.
- **Paginator with no item type.** `LengthAwarePaginator`, `Paginator`, and `CursorPaginator`
  do not carry the item generic in PHP types. Add a `@return LengthAwarePaginator<FlightData>`
  PHPDoc tag — the generator reads that single PHPDoc generic, no other body inference happens.
- **`#[ResponseResource]` names a class that isn't a resolvable response resource.** Lint rule
  `responseresource.unresolvable` (level 1) catches this. The named class must be a `JsonResource`
  subclass, a `Data` subclass, or another resource recognised by an enabled plugin.

### I changed a Data/FormRequest and the spec still shows the old shape

`php artisan openapi:clear` drops the cached spec. The generator writes a YAML file on the
configured path; until you regenerate (or clear), the playground serves the cached document.

### Lint reports a finding I don't understand

Every rule has a description. Print the live catalog with descriptions:

```bash
php artisan openapi:lint --list
```

Filter to a single rule:

```bash
php artisan openapi:lint --only=field.attribute-wrong-scope
```

To silence a rule on one symbol after deciding the finding is acceptable, use
`#[OpenApi\IgnoreLint('rule.id', reason: '...')]` — see [Suppress a lint finding](#suppress-a-lint-finding).
The meta-rule `meta.no-suppression-reason` enforces the `reason:` argument.

### A custom `Rule` object yields no constraint (`rule.unknown` finding)

`ValidationRulesToSchema` handles the built-in Laravel rule classes (`Password`, `File`,
`ImageFile`, `Dimensions`, `In`, `Enum`). Any other `Rule` object — including project-local
implementations — is silently dropped and the field falls back to type-only. Use the
[schema transformer hook](#schema-transformer) to inject the missing constraint.

### `#[RequestField]` is on a URI param (or `#[PathParam]` on a Data property)

`field.attribute-wrong-scope` (SpatieData plugin, level 1) catches this. The four `FieldAttribute`
subclasses are scoped — pick the one whose target matches:

- `#[RequestField]` — request-body fields (Data property / `FormRequest` `PARAM_*` constant).
- `#[ResponseField]` — response fields (response class constant / property).
- `#[PathParam]` — controller action parameter for a URI segment.
- `#[QueryParam]` — class or method, ad-hoc query parameter.

### Two component schemas with the same basename collide

`ComponentSchemaRegistry` disambiguates automatically by prepending parent namespace segments
(skipping generic ones like `Http`, `Data`, `V0`). A compound name like `Projects.CreateProjectData`
in `components.schemas` means a collision was resolved — that's expected. The lint rule
`component.name-naming-inconsistent` (level 3) reports the resulting name if it violates the
configured `component_name_case` convention. There is no authoring attribute to force a specific
component name; rename the class (or move it to a less ambiguous namespace) if the auto-derived
key is wrong.

### `#[Security(['scope'])]` isn't using my custom scheme

By default, scope-only `#[Security]` requirements target the Passport-derived `oauth2` /
`oauth2ClientCredentials` pair when Passport is installed. For apps with a different scheme:

- Declare it under `openapi.security_schemes` (see [Declare custom security schemes](#declare-custom-security-schemes)).
- Either pass `scheme: 'name'` on every `#[Security]` instance, or set
  `openapi.security_default_scheme` once to apply it project-wide. Resolution order is:
  attribute `scheme:` → `security_default_scheme` → Passport pair → first declared scheme → empty.

### A scope is rejected by `security.invalid-scope`

The scope appears on `#[Security]` (or is derived from `scope:*` middleware) but is not listed
under the targeted scheme's `flows.*.scopes` map in `openapi.security_schemes`. Add the scope
there, or change the requirement to a scheme that declares it.

### Octane / concurrent generation runs produce mixed output

The pipeline classes are bound as **scoped** singletons in `OpenApiServiceProvider`. Octane
resets scoped bindings between requests, so concurrent generation runs each get fresh
`ComponentSchemaRegistry` and `ExampleFileLoader` instances. If you see mixed output, confirm
you haven't downgraded any of the package's bindings to a regular `singleton()` in a host
service provider — that would share mutable per-run state across requests.

### Generation succeeds but the playground (Scalar) shows nothing

- `GET /api/openapi.yaml` returns the raw spec — open it directly. If it's empty or stale,
  regenerate (`php artisan openapi:generate`).
- The playground route is registered only when `openapi.routes.playground.enabled` is true.
  The default is `APP_ENV === 'local'`; in other environments the spec route stays but the
  playground does not.

### The spec is valid but `php artisan openapi:lint` reports findings I want to defer

Three options:

1. Pass `--level=N` to raise the threshold (`--level=0` = broken only).
2. Add the rule ID to `openapi.lint.disabled_rules` to switch it off project-wide.
3. Use `#[OpenApi\IgnoreLint]` per-symbol with a `reason`. Stale suppressions are flagged by
   `meta.suppression-stale`.

## Conventions for New Endpoints

When adding a new endpoint, the checklist is short:

1. Write a one-paragraph PHPDoc summary above the controller action.
2. Type-hint the request body as a `Data` class (declare `rules()` for constraints).
3. Type-hint the route-bound model and use `#[Where*]` for regex constraints.
4. Use `auth:api` + `scope:foo` middleware as usual.
5. Return a typed resource — or add `#[ResponseResource]`.
6. Add `@throws` for every exception the action can raise that maps to an HTTP error.

Everything else is opt-in.

## Adding a New Plugin

A plugin teaches Core about a specific package or convention. Implement the `Plugin` interface and
register the class in `config/openapi.plugins`. A plugin's sole job is to register contributions
into the `OpenApiRegistry` passed to `register()`.

### The `Plugin` interface

```php
namespace Radiergummi\OpenApi\Core\Registry;

interface Plugin
{
    public function register(OpenApiRegistry $registry): void;
}
```

`register()` is called once at boot, after `CoreRegistration` and in `config/openapi.plugins`
declaration order. The plugin instance itself is resolved from the Laravel container, so it may
take constructor dependencies.

### The `OpenApiRegistry` API

`OpenApiRegistry` is the assembled inventory the generator and linter read from. A plugin
contributes by calling its `add*` methods — each takes a **class-string**; instances are resolved
from the container when the pipeline runs.

| Method | Contributes | Interface the class must implement |
|---|---|---|
| `addRequestSchemaResolver(string $class)` | A request-body schema builder | `RequestSchemaResolver` |
| `addRefSchemaResolver(string $class)` | A `$ref` resolver for a class shape (e.g. a DTO or resource) | `RefSchemaResolver` |
| `addQueryParameterResolver(string $class)` | A query-parameter extractor | `QueryParameterResolver` |
| `addPrimaryResponseResolver(string $class)` | A 200/204 response resolver | `PrimaryResponseResolver` |
| `addErrorResponseFactory(string $class)` | An error-response schema factory | `ErrorResponseFactory` |
| `addPayloadClass(string $class)` | Marks a base class as a request-payload DTO so `PayloadParameterScanner` recognises it | (a base class, not an interface) |
| `addRule(string $class)` | A lint rule | `Core\Lint\Rules\Rule` + one or more visitor interfaces |

The corresponding getters (`requestSchemaResolvers()`, `refSchemaResolvers()`,
`queryParameterResolvers()`, `primaryResponseResolvers()`, `errorResponseFactories()`,
`payloadClasses()`, `rules()`) are what the generator and linter consume — a plugin author does
not call them.

The resolver interfaces live in `src/Core/Registry/`:

- **`RequestSchemaResolver`** — given an `ActionDescriptor`, decide whether it can resolve a
  request body for that action, and if so produce the body schema. Used to support a new kind of
  request DTO. The bundled `DataClassRequestSchemaResolver` handles Spatie Data classes; Core's
  `FormRequestRequestSchemaResolver` handles `FormRequest` subclasses.
- **`RefSchemaResolver`** — given a class-string, decide whether it owns that class's shape and
  produce a component schema for it. Used to teach the shared `$ref` pool how to serialise a class
  (a DTO base, a resource base, an entity). The bundled `DataRefSchemaResolver` handles Spatie
  Data classes.
- **`QueryParameterResolver`** — given an `ActionDescriptor`, produce query-string parameters.
  Used to derive `?filter[…]`, `?include`, `?sort`, paging, etc. from a request-class convention.
- **`PrimaryResponseResolver`** — given an `ActionDescriptor`, resolve the primary 2xx response
  schema from the controller's return type or attributes.
- **`ErrorResponseFactory`** — build the error-response schema (e.g. an RFC 7807 or JSON:API error
  envelope) used for the 4xx/5xx responses contributed by `StandardResponsesExtractor`.

Each resolver follows the same shape: a "can I handle this?" predicate plus a "produce the result"
method. The generator iterates registered resolvers in registration order and uses the first that
claims the input. Read the interface source for the exact method signatures — they are small and
self-documenting.

### Worked example: the bundled SpatieData plugin

The shipped `SpatieDataPlugin` (`src/Plugins/SpatieData/SpatieDataPlugin.php`) is the reference
implementation. It teaches Core to read request schemas from Spatie Data classes:

```php
namespace Radiergummi\OpenApi\Plugins\SpatieData;

use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\MultipartFileWithoutMultipart;
use Spatie\LaravelData\Data;

final class SpatieDataPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRequestSchemaResolver(DataClassRequestSchemaResolver::class);
        $registry->addRefSchemaResolver(DataRefSchemaResolver::class);
        $registry->addPayloadClass(Data::class);
        $registry->addRule(MultipartFileWithoutMultipart::class);
        $registry->addRule(FieldAttributeWrongScope::class);
    }
}
```

What each line does:

- `addRequestSchemaResolver(DataClassRequestSchemaResolver::class)` — when a controller action
  type-hints a Spatie Data class (directly or via a configured payload-indirection object), this
  resolver builds the request body from the Data class's PHP types, validation rules, and field
  attributes.
- `addRefSchemaResolver(DataRefSchemaResolver::class)` — when a Data class is referenced from
  another schema (a nested DTO, a `#[Response(ref: …)]`), this resolver emits it as a
  `$ref` into `components.schemas`.
- `addPayloadClass(Data::class)` — marks `Spatie\LaravelData\Data` as a payload base so
  `PayloadParameterScanner` treats any subclass found on an action signature as a request body.
- `addRule(...)` — registers the two plugin-specific lint rules
  (`field.attribute-wrong-scope`, `multipart.file-without-multipart`), so they only run when the
  plugin is installed.

### Building your own plugin

To support a different request-class convention or resource library, follow the same shape:

1. Write the resolver classes implementing the relevant interfaces from `src/Core/Registry/`.
   Use `src/Plugins/SpatieData/` as a template — for instance, mirror
   `DataClassRequestSchemaResolver` for a `RequestSchemaResolver`, or
   `DataRefSchemaResolver` for a `RefSchemaResolver`.
2. Optionally write lint rules in a `Lint/Rules/` sub-namespace, each implementing
   `Core\Lint\Rules\Rule` plus the visitor interfaces it needs.
3. Write a `Plugin` class whose `register()` calls the matching `OpenApiRegistry` `add*` methods.
4. Bind any services your resolvers need in a service provider (the bundled plugin's bindings live
   in `OpenApiServiceProvider`; an external plugin would supply its own provider).
5. Add the plugin class to `config/openapi.plugins`.

A plugin that adds a query-parameter convention plus a resource/response shape — i.e. a plugin for
a JSON:API-style library — would register a `QueryParameterResolver` (to derive `?filter`,
`?include`, `?sort` parameters from its request class), a `PrimaryResponseResolver` and
`RefSchemaResolver` (to serialise its resource envelope), an `ErrorResponseFactory` (to emit its
error envelope), and whatever lint rules enforce its conventions. All of those hooks exist on the
registry; the SpatieData plugin exercises three of the seven, and the remaining four follow the
identical class-string-registration pattern.
