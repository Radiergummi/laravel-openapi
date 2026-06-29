# Recipes

Snippets for cases convention doesn't cover. Each recipe assumes:

```php
use Radiergummi\OpenApi\Attributes as OpenApi;
```

Full attribute reference: [Attributes](attributes.md).

## Set the summary or description

The PHPDoc is the default source. The first paragraph becomes the summary;
remaining paragraphs become the description.

```php
class ProjectController extends Controller
{
    /**
     * Retrieve a project.
     *
     * Returns the project envelope including its phases and supplier counts.
     */
    public function show(Project $project): ProjectResource { … }
}
```

When the spec needs to say something different from the docblock, use the
standalone `#[Summary]` and `#[Description]` attributes:

```php
#[OpenApi\Summary('Retrieve a project')]
#[OpenApi\Description('Returns the project envelope including phases and supplier counts.')]
public function show(Project $project): ProjectResource { … }
```

Both win over the docblock. Use `#[Operation(summary: …, description: …)]`
instead when overriding several operation fields at once.

Precedence: action attribute → action `#[Operation(...)]` → action docblock →
class attribute → class `#[Operation(...)]`. For a `__invoke` (single-action)
controller the "action" is its `__invoke` method, so the method's docblock and
attributes take precedence as usual; class-level placement is a convenient
fallback when `__invoke` carries none.

## Document an ad-hoc query parameter

```php
#[OpenApi\QueryParam('q', description: 'Free-text search query.', example: 'cnc machining')]
#[OpenApi\QueryParam('limit', type: 'integer', default: 25, maximum: 100)]
public function search(Request $request): JsonResponse { … }
```

## Enrich a request-body field

`RequestField` layers on top of type-derived and rule-derived schema. Use it
for documentation; let validation rules carry constraints.

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

## Enrich a response field

A `JsonResource`'s output keys are arbitrary `toArray()` entries, not typed
properties, so each key is declared with a class-level, repeatable
`#[ResourceField]` (from the ApiResources plugin) naming the key:

```php
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

#[ResourceField('created_at', type: 'string', format: 'date-time', description: 'ISO 8601 creation timestamp.')]
#[ResourceField('match_count', type: 'integer', readOnly: true, description: 'Computed supplier match count.')]
// For conditionally-present fields (kept in `properties`, dropped from `required`):
#[ResourceField('phases', conditional: true, description: 'Loaded only when ?include=phases is requested.')]
class ProjectResource extends JsonResource { /* … */ }
```

On a Spatie Data class, where keys *are* typed properties, use
`#[ResponseField]` on the property instead:

```php
class ProjectData extends Data
{
    public function __construct(
        #[OpenApi\ResponseField(readOnly: true, description: 'Server-assigned identifier.')]
        public string $id,
    ) {}
}
```

## Annotate a path parameter

```php
public function single(
    #[OpenApi\PathParam(description: 'The project to retrieve.', example: '01HFP…')]
    Project $project,
): ProjectResource { … }
```

## Add an error response that isn't in `@throws`

```php
/**
 * @throws ValidationException
 */
#[OpenApi\Response(status: 409, description: 'Project name already exists.')]
public function store(CreateProjectData $data): ProjectResource { … }
```

## Make an exception self-describing

Decorate the exception class instead of adding an entry to `config/openapi.php`:

```php
#[OpenApi\ExceptionResponse(status: 418, description: "I'm a teapot")]
class TeapotException extends RuntimeException {}
```

Anywhere this exception appears in a `@throws`, it maps automatically.

The `#[ExceptionResponse]` attribute takes precedence over `exception_responses` in
`config/openapi.php` — use the attribute to override a config entry on a per-exception basis. See
[Config → Exception-response precedence](config.md#exception-response-precedence) for the full
resolution order.

## Multipart / file upload

A Data class with an `UploadedFile` property, or any `file` / `image`
validation rule, switches the request body to `multipart/form-data` with
`format: binary` on the relevant field. For non-Data bodies:

```php
use Radiergummi\OpenApi\Enums\MediaType;

#[OpenApi\RequestBody(description: 'Webhook payload', mediaType: MediaType::FormUrlEncoded)]
public function webhook(Request $request): Response { … }
```

## Document a streaming endpoint (SSE)

Streaming content types are not auto-detected. Advertise them with
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

To document a per-event payload schema, override the 200 response and set the
media type explicitly:

```php
use Radiergummi\OpenApi\Enums\MediaType;

#[OpenApi\Operation(streaming: true)]
#[OpenApi\Response(
    status: 200,
    description: 'SSE stream. One JSON object per event.',
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

## Link to another operation from a response

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

Use `operationId` for intra-document links, or `operationRef` (a JSON Pointer)
for cross-document links. Exactly one must be provided. Links attach to the
primary 2xx response only.

## Document a polymorphic response with a discriminator

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

## Hide an endpoint from production docs

```php
#[OpenApi\Hide(only: ['production'])]
public function dangerous(): JsonResponse { … }
```

Pass no argument (`#[OpenApi\Hide]`) to hide unconditionally.

## Switch between public-default and hidden-default visibility

Every discovered route appears in the document by default; opt out with
`#[OpenApi\Hide]`. To flip the default (useful for internal/admin APIs):

```php
// config/openapi.php
'visibility' => [
    'default' => 'hidden',
],
```

In hidden-default mode every route is excluded unless it carries an applicable
`#[OpenApi\Expose]`. Both attributes accept mutually-exclusive `only` and
`except` arguments scoping them to environments:

```php
#[OpenApi\Expose(only: ['staging'])]      // staging only
#[OpenApi\Expose(except: ['production'])] // every env except production
```

> [!IMPORTANT]
> When both `#[Hide]` and `#[Expose]` apply in the current environment,
> `#[Hide]` wins.

`visibility.hide-expose-conflict` flags overlapping declarations.
`visibility.attribute-no-op` flags unconditional attributes with no effect
under the active default (e.g., `#[Expose]` while `visibility.default = 'public'`).

## Declare custom security schemes

Laravel Passport's `oauth2` (Authorization Code) and `oauth2ClientCredentials`
schemes are emitted automatically when Passport is installed. For different
auth shapes (bearer JWT, API key, basic auth), declare schemes under
`openapi.security_schemes`:

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

Each entry passes through to swagger-php's `OA\SecurityScheme` unchanged; the
map key becomes the scheme name. Config entries are merged with the
Passport-derived pair (config wins on key collision). Target a specific scheme
with `#[Security]`:

```php
#[OpenApi\Security(['flights:write'], scheme: 'bearer')]
public function store(StoreFlightRequest $request): FlightData { … }
```

Omit `scheme:` to use the project default (Passport's pair if available,
otherwise the first declared scheme). See
[`examples/combined/`](../examples/combined/) for an end-to-end demo.

## Document an inbound webhook

The route still exists. The generator extracts it normally and diverts it to
`webhooks` in the spec.

```php
use Radiergummi\OpenApi\Enums\MediaType;

#[OpenApi\Webhook(name: 'stripe.webhook')]
#[OpenApi\PublicEndpoint]
#[OpenApi\RequestBody(description: 'Stripe event payload', mediaType: MediaType::Json)]
public function handleWebhook(Request $request): Response { … }
```

## Force the response resource

Use when resource resolution can't infer the resource (a warning is logged
during generation):

```php
#[OpenApi\ResponseResource(SupplierResource::class, collection: true)]
public function index(Request $request): JsonResponse { … }
```

## Add a vendor extension (`x-*`) to an operation

There is no dedicated attribute. Register an operation transformer at boot:

```php
// AppServiceProvider::boot()
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Extensions\OperationContext;

OpenApiExtensions::transformOperation(
    static function (OA\Operation $operation, OperationContext $context): void {
        if ($context->controllerClass === FlightController::class && $context->methodName === 'store') {
            $operation->{'x-internal'} = true;
            $operation->{'x-rate-limit'} = ['burst' => 10, 'sustained' => 60];
        }
    },
);
```

Drop the scoping check to apply the extension to every operation. For
document-level or schema-level extensions, use `transformDocument()` and
`transformSchema()`. See [Extensions](extensions.md).

## Reshape the operationId

operationIds default to the route name (`api.v1.projects.index`); the
`operation_id_strategy` config key switches between `route-name` and
`method-path`. To reshape them beyond those two presets — e.g., strip a
versioned `api.v{N}.` route-name prefix so a generated client reads
`projects.index` rather than `api.v1.projects.index` — register an operation
transformer at boot. `OperationContext` exposes the route, so any
route-derived rule works:

```php
// AppServiceProvider::boot()
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Extensions\OperationContext;

OpenApiExtensions::transformOperation(
    static function (OA\Operation $operation, OperationContext $context): void {
        $routeName = $context->descriptor->route->getName();

        if ($routeName !== null && preg_match('/^api\.v\d+\./', $routeName)) {
            $operation->operationId = preg_replace('/^api\.v\d+\./', '', $routeName);
        }
    },
);
```

Keep the result within the codegen-safe identifier shape — the
`operation.id-invalid-chars` lint rule flags violations, and
`lint.style.operation_id_case` enforces the casing convention.

## Suppress a lint finding

```php
#[OpenApi\IgnoreLint('response.no-error', reason: 'Internal-only endpoint, errors handled by framework')]
public function internal(): JsonResponse { … }
```

Always pass a `reason`. For scope rules and hygiene meta-rules, see
[Linting → Suppress a finding](linting.md#suppress-a-finding).

## Choosing an error envelope

Standard error responses (4xx/5xx derived from `@throws` and auth/scope/throttle middleware) ship with no body by default. Select an envelope preset via `config/openapi.php`:

```php
'error_envelope' => 'laravel',  // or 'rfc7807' | 'json-api' | 'none'
```

### Presets

| Preset    | Media type                    | Generic shape                    | 422 shape                                                  |
|-----------|-------------------------------|----------------------------------|------------------------------------------------------------|
| `none`    | — (no body)                   | description only                 | description only                                           |
| `laravel` | `application/json`            | `{ message: string }`            | `{ message: string, errors: { <field>: string[] } }`       |
| `rfc7807` | `application/problem+json`    | Problem (`type`, `title`, ...)   | ValidationProblem (`+ errors`)                             |
| `json-api`| `application/vnd.api+json`    | `{ errors: [...] }` (uniform)    | same shape                                                 |

### How responses appear in the document

- **`none`**: a single shared `components.responses.<Name>` (e.g., `Unauthorized`, `NotFound`, `ValidationFailed`) is emitted per known status, and every operation that returns that status `$ref`s the shared entry.
- **`laravel` / `rfc7807` / `json-api` / any custom envelope that returns content**: the response is **inlined per operation**. Two operations at the same status can carry different bodies (e.g., `ValidationException` → `ValidationError` vs `UnprocessableEntityHttpException` → generic `Error`, both at 422), so the response wrapper is not shared via `components.responses`. The body schemas referenced from inside the inlined response (e.g., `#/components/schemas/Error`) are still reused via `$ref`.

### Schema name collisions

The body-bearing presets register short component-schema names: `Error`, `ValidationError` (Laravel); `Problem`, `ValidationProblem` (RFC 7807); `ErrorDocument` (JSON:API). If your application has a Spatie Data class with the same basename (e.g., `App\Errors\Error`), the registry disambiguates the user class via the normal namespace-prefixing rule (`App.Errors.Error`); the preset always keeps the short name. The user class's `$ref` moves with the disambiguated key, so existing usage stays consistent — but downstream consumers that hard-coded `#/components/schemas/Error` for the user class will see the envelope schema there once the envelope is enabled. Rename the conflicting Data class if you need the short name back.

### Custom envelopes

Implement `ErrorResponseResolver` and point `error_envelope` at your class:

```php
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Errors\{ErrorDescriptor, ErrorResponse};

final class MyEnvelope implements ErrorResponseResolver
{
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
    {
        // $descriptor->exceptionClass is null for middleware-detected responses whose
        // middleware_responses entry has no 'exception' key — always guard the is_a check.
        // Use is_a — never strict equality on framework exceptions.
        if ($descriptor->exceptionClass !== null
            && is_a($descriptor->exceptionClass, MyDomainException::class, true)
        ) {
            return new ErrorResponse(content: [/* OA\MediaType */]);
        }
        return null;  // defer to the next resolver in the chain
    }
}
```

Contract notes for custom resolvers:

- **Catch internally, return null.** A throwing resolver no longer aborts the generation run — the extractor catches and emits a `errors.resolver-failed` lint finding so the misbehaviour is visible — but you should still catch internally and return `null` to defer cleanly.
- **`description` overrides.** `ErrorResponse::description` overrides the curated default only when it is a **non-empty** string. Pass `null` (the default) when you don't want to override; OpenAPI 3.1 requires `response.description` to be non-empty.
- **Registering shared schemas idempotently.** If your resolver registers component schemas via `ComponentSchemaRegistry::registerNamed()` / `register()`, guard with `hasKey()` / `isRegisteredOrReserved()` — `resolveErrorResponse()` is invoked **per status × per operation**.

```php
'error_envelope' => App\OpenApi\MyEnvelope::class,
```

> [!NOTE]
> This package documents your error shapes; it does not install a Laravel exception handler that emits them. The recipes cookbook for matching runtime handlers is separate.

## Serving docs behind a reverse proxy

When the playground is accessed through a reverse proxy, load balancer, or CDN, the spec URL
embedded in the playground page must reflect the public host and path, not the internal origin
address. There are two paths, depending on your infrastructure.

### Path A: configure TrustProxies (recommended)

`DocsController::playground()` derives the spec URL via Laravel's `route()` helper, which goes
through `UrlGenerator`. That generator respects `X-Forwarded-Host`, `X-Forwarded-Proto`,
`X-Forwarded-Port`, and `X-Forwarded-Prefix` **only when the request has passed through Laravel's
`TrustProxies` middleware**. Without it, those headers are ignored — a deliberate security boundary.

Enable `TrustProxies` and configure which proxies and headers to trust. On Laravel 11+ this
middleware is registered in `bootstrap/app.php`:

```php
// bootstrap/app.php
$app->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(
        at: '*',  // or a specific CIDR range / IP list
        headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PREFIX
            | Request::HEADER_X_FORWARDED_PROTO,
    );
});
```

On Laravel 10 and below, edit `app/Http/Middleware/TrustProxies.php` and add the middleware to
the `$middleware` stack in `app/Http/Kernel.php`. Once active, the playground's spec URL is
derived correctly from the forwarded headers and no library config is needed.

> [!IMPORTANT]
> Trusting `'*'` accepts forwarded headers from any source. In production, prefer a specific IP
> or CIDR range matching your load balancer so internal traffic cannot spoof the host.

### Path B: set an explicit spec URL

When `TrustProxies` cannot be used — the proxy strips forwarded headers, the spec is hosted on a
CDN at a fixed URL, or you need the playground to point at a different environment's spec — set the
`routes.playground.spec_url` config key to the canonical absolute URL:

```php
// config/openapi.php
'routes' => [
    // ...
    'playground' => [
        'enabled'  => env('APP_ENV') === 'local',
        'uri'      => 'docs',
        'renderer' => 'scalar',
        'spec_url' => env('OPENAPI_PLAYGROUND_SPEC_URL'),
    ],
],
```

Then set the environment variable in your deploy pipeline:

```dotenv
OPENAPI_PLAYGROUND_SPEC_URL=https://api.example.com/api/openapi.yaml
```

When `spec_url` is set to a non-blank value, the playground passes it verbatim to the renderer
as the `data-url` / `url` attribute, bypassing route derivation. When null or blank (the default),
the existing route-derived URL is used.
