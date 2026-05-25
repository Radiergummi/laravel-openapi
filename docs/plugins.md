# Plugins

A plugin registers resolvers, extractors, and lint rules for a specific
package or convention. Four plugins ship with the package:

| Plugin | Default | Requires | Documents |
|---|---|---|---|
| [`SpatieData`](#spatiedata) | enabled (no-ops without the package) | `spatie/laravel-data` | Data-class request bodies and responses |
| [`ApiResources`](#apiresources) | enabled | Laravel core | `JsonResource` / `ResourceCollection` responses |
| [`QueryBuilder`](#querybuilder) | disabled | `spatie/laravel-query-builder` | `filter[]` / `sort` / `include` query parameters |
| [`Fractal`](#fractal) | disabled | `league/fractal` | Fractal transformer responses |

`FormRequest` request bodies are supported natively. No plugin required.

Plugins are listed in `config/openapi.plugins` and resolved from the
container, in declaration order, after Core registers.

To write your own, see [Plugin authoring](plugin-authoring.md).

## SpatieData

Reads request and response schemas from Spatie Data classes, including
`DataCollection<…>` and `PaginatedDataCollection<…>`.

`spatie/laravel-data` is an optional runtime dependency. The plugin entry
stays in `config/openapi.plugins` either way; without the package installed
it no-ops. Install `spatie/laravel-data` to activate.

See [Request bodies](request-bodies.md) for the full surface, and
[Request bodies → PATCH semantics](request-bodies.md#patch-semantics) for
`Optional|…|null` handling.

Lint rules:

| Rule | Level |
|---|---|
| `field.attribute-wrong-scope` | 1 |
| `multipart.file-without-multipart` | 1 |

Worked endpoint: [`examples/spatie-data/`](../examples/spatie-data/).

## ApiResources

Controllers returning a typed `JsonResource` subclass are documented
automatically. Declare the resource's fields with class-level
`#[ResourceField]` attributes:

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

### Collection endpoints

For collection endpoints returning `JsonResponse` or an untyped value, name
the resource and envelope explicitly:

```php
#[OpenApi\ResponseResource(ProjectResource::class, collection: true)]
public function index(): JsonResponse { … }
```

Single responses wrap fields in `{ data: {…} }`; collection responses in
`{ data: [{…}], links: {…}, meta: {…} }`.

> [!NOTE]
> Omitting `#[ResourceField]` triggers the `resource.fields-undeclared`
> lint rule (level 1).

Lint rules:

| Rule | Level |
|---|---|
| `resource.fields-undeclared` | 1 |
| `resource.response-ambiguous` | 1 |
| `resource.field-type-missing` | 2 |

Worked endpoint: [`examples/form-requests/`](../examples/form-requests/).

## QueryBuilder

Documents `spatie/laravel-query-builder` parameters as OpenAPI query
parameters.

### Enable

1. `composer require spatie/laravel-query-builder`
2. Uncomment `QueryBuilderPlugin::class` under `plugins` in
   `config/openapi.php`.

### Usage

Declare accepted parameters on the controller method:

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

Each `#[AllowedFilter]` becomes one `filter[name]` query parameter.
`#[AllowedSort]` becomes the `sort` parameter (comma-separated, with the
listed fields as `enum`); `#[AllowedInclude]` becomes `include` the same way.

Lint rules:

| Rule | Level |
|---|---|
| `query-builder.params-undeclared` | 2 |
| `query-builder.filter-type-missing` | 3 |

`query-builder.params-undeclared` fires when a method injects `QueryBuilder`
but declares none of the three attributes. `query-builder.filter-type-missing`
fires for an `#[AllowedFilter]` without a `type`.

Worked endpoint: [`examples/query-builder/`](../examples/query-builder/).

## Fractal

Documents `league/fractal` transformer responses with three serializer
envelopes: `DataArraySerializer` (default), `ArraySerializer`, and
`JsonApiSerializer`.

### Enable

1. `composer require league/fractal` (or `spatie/laravel-fractal`, which
   depends on it).
2. Uncomment `FractalPlugin::class` under `plugins` in `config/openapi.php`.

### Usage

Declare each transformer's output keys with repeatable `#[TransformerField]`
attributes on the transformer class. Declare `availableIncludes` /
`defaultIncludes` entries with `#[TransformerInclude]`. Bind each endpoint to
its transformer with `#[FractalResponse]`:

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

### Serializers

The default envelope models `DataArraySerializer` plus
`IlluminatePaginatorAdapter`. Set `serializer:` on `#[FractalResponse]` when
the action calls `Manager::setSerializer(…)`:

```php
use Radiergummi\OpenApi\Plugins\Fractal\Serializer;

#[FractalResponse(transformer: BookTransformer::class, serializer: Serializer::ArraySerializer)]
public function arraySingle(): JsonResponse { … }      // bare $ref, no envelope

#[FractalResponse(transformer: BookTransformer::class, collection: true, serializer: Serializer::ArraySerializer)]
public function arrayCollection(): JsonResponse { … } // top-level array

#[FractalResponse(transformer: BookTransformer::class, serializer: Serializer::JsonApi)]
public function jsonApiShow(): JsonResponse { … }     // {data: {type, id, attributes: $ref}} as application/vnd.api+json
```

`Serializer::JsonApi` responses are emitted under `application/vnd.api+json`.
For custom serializers outside the three named cases, override the response
with `#[Response]` on the action.

Lint rules:

| Rule | Level |
|---|---|
| `fractal.fields-undeclared` | 1 |
| `fractal.duplicate-key` | 1 |
| `fractal.transformer-class-missing` | 1 |
| `fractal.response-unbound` | 2 |
| `fractal.include-transformer-missing` | 2 |

> [!NOTE]
> `fractal.response-unbound` is opt-in. The `fractal()` helper and
> `Spatie\Fractalistic\Fractal` facade are invoked inside method bodies, and
> the generator does not read method bodies. See
> [OAPI-017](internal/known-gaps.md#oapi-017--no-method-body-inference) and
> [OAPI-053](internal/known-gaps.md).
