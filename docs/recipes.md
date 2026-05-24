# Recipes

Short cookbook entries for the cases where convention doesn't quite reach. Each
recipe assumes:

```php
use Radiergummi\OpenApi\Core\Attributes as OpenApi;
```

For the full attribute reference, see [Attributes](attributes.md).

## Override the summary or description

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

Most cases don't need an attribute — the PHPDoc is the source of truth. Reach
for `#[OpenApi\Operation]` only when the PHPDoc has to say something different
from the spec.

## Document an ad-hoc query parameter

```php
#[OpenApi\QueryParam('q', description: 'Free-text search query.', example: 'cnc machining')]
#[OpenApi\QueryParam('limit', type: 'integer', default: 25, maximum: 100)]
public function search(Request $request): JsonResponse { … }
```

## Enrich a request-body field

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

> [!NOTE]
> `RequestField` is layered **on top of** type-derived and rule-derived schema
> — use it for documentation enrichment; let validation rules carry constraints.

## Enrich a response field

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

Instead of adding an entry to `config/openapi.php`, decorate the exception class:

```php
#[OpenApi\ExceptionResponse(status: 418, description: "I'm a teapot")]
class TeapotException extends RuntimeException {}
```

Anywhere this exception appears in a controller's `@throws`, it's mapped
automatically.

## Multipart / file upload

A Spatie Data class with an `UploadedFile` property (or a `file` validation
rule) auto-switches the request body to `multipart/form-data` with
`format: binary` on the relevant field. For request bodies not backed by a
Data class:

```php
#[OpenApi\RequestBody(description: 'Webhook payload', mediaType: 'application/x-www-form-urlencoded')]
public function webhook(Request $request): Response { … }
```

## Document a streaming endpoint (SSE / `text/event-stream`)

Streaming content types are **not** auto-detected. Advertise a streaming
response explicitly with `#[OpenApi\Operation(streaming: true)]`:

```php
#[OpenApi\Operation(streaming: true)]
public function stream(): StreamedResponse
{
    return new StreamedResponse(static function (): void {
        // emit SSE frames
    });
}
```

To document a per-event payload schema, override the 200 response and set its
media type explicitly:

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

Use `operationId` (preferred) for intra-document links, or `operationRef` (a
JSON Pointer) for cross-document links. Exactly one must be provided. Links
attach to the primary 2xx response only.

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

By default every discovered route appears in the generated document; mark
individual routes with `#[OpenApi\Hide]` to opt them out. Flip the default for
internal/admin APIs by setting:

```php
// config/openapi.php
'visibility' => [
    'default' => 'hidden',
],
```

In hidden-default mode every route is excluded unless it carries an applicable
`#[OpenApi\Expose]` attribute. Both attributes support mutually-exclusive `only`
and `except` arguments scoping them to specific application environments:

```php
#[OpenApi\Expose(only: ['staging'])]      // staging only
#[OpenApi\Expose(except: ['production'])] // every env except production
```

> [!IMPORTANT]
> When both `#[Hide]` and `#[Expose]` apply to the same route in the current
> environment, `#[Hide]` wins — the route stays hidden.

The `visibility.hide-expose-conflict` lint rule flags overlapping declarations
so authors can disambiguate intent; `visibility.attribute-no-op` reports
unconditional attributes that have no effect under the active default
(e.g. `#[Expose]` while `visibility.default = 'public'`).

## Declare custom security schemes

By default the package emits Laravel Passport's `oauth2` (Authorization Code)
and `oauth2ClientCredentials` schemes when Passport is installed. Apps using a
different auth shape — plain bearer JWT, API key, basic auth — declare
additional schemes via the `openapi.security_schemes` config map:

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
Passport-derived pair (config wins on key collision), and operations point at
a specific scheme through `#[Security]`:

```php
#[OpenApi\Security(['flights:write'], scheme: 'bearer')]
public function store(StoreFlightRequest $request): FlightData { … }
```

Omit `scheme:` to fall back to the project default (Passport's pair when
available, otherwise the first config-declared scheme). The combined-flavor
example ([`examples/combined/`](../examples/combined/)) demonstrates both
halves end-to-end.

## Document an inbound webhook

```php
#[OpenApi\Webhook(name: 'stripe.webhook')]
#[OpenApi\PublicEndpoint]
#[OpenApi\RequestBody(description: 'Stripe event payload', mediaType: 'application/json')]
public function handleWebhook(Request $request): Response { … }
```

The route still exists — the generator extracts it normally and diverts it to
`webhooks` in the spec.

## Force the response resource

```php
#[OpenApi\ResponseResource(SupplierResource::class, collection: true)]
public function index(Request $request): JsonResponse { … }
```

Use this when the resource-resolution heuristic fails (you'll see warnings
during generation if so).

## Add a vendor extension (`x-*`) to an operation

Vendor extensions don't have a dedicated attribute — register an operation
transformer at boot and scope it by controller, method, or route:

```php
// AppServiceProvider::boot()
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Core\Extensions\OperationContext;
use OpenApi\Annotations as OA;

OpenApiExtensions::transformOperation(
    static function (OA\Operation $operation, OperationContext $context): void {
        if ($context->controllerClass === FlightController::class && $context->methodName === 'store') {
            $operation->{'x-internal'} = true;
            $operation->{'x-rate-limit'} = ['burst' => 10, 'sustained' => 60];
        }
    },
);
```

For an extension that applies to **every** operation, drop the scoping check.
For document-level or schema-level extensions, use `transformDocument()` and
`transformSchema()` — see [Extensions](extensions.md) for the full reference.

## Suppress a lint finding

```php
#[OpenApi\IgnoreLint('response.no-error', reason: 'Internal-only endpoint, errors handled by framework')]
public function internal(): JsonResponse { … }
```

Always pass a `reason`. See [Linting → Suppress a finding](linting.md#suppress-a-finding)
for scope rules and meta-rules that enforce directive hygiene.
