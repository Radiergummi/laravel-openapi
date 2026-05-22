# Plugins

Core is convention-agnostic. **Plugins** teach Core about specific packages —
how to read their request DTOs, how to document their response envelopes, what
lint rules enforce their conventions. The package ships four:

| Plugin | Default | Requires | Documents |
|---|---|---|---|
| [`SpatieData`](#spatiedata) | enabled (auto-skips without the package) | `spatie/laravel-data` | Data-class request bodies and responses |
| [`ApiResources`](#apiresources) | enabled | — (Laravel core) | `JsonResource` / `ResourceCollection` responses |
| [`QueryBuilder`](#querybuilder) | disabled | `spatie/laravel-query-builder` | `filter[]` / `sort` / `include` query parameters |
| [`Fractal`](#fractal) | disabled | `league/fractal` | Fractal transformer responses |

`FormRequest` request bodies are handled by Core directly — **no plugin
required**.

Plugins are listed in `config/openapi.plugins` and resolved from the container.
Core registers first, then each plugin in declaration order, then any
`config/openapi.lint.rules` extras.

To write your own plugin, see [Plugin authoring](plugin-authoring.md).

## SpatieData

Default-enabled. Reads request and response schemas from Spatie Data classes —
including `DataCollection<…>` and `PaginatedDataCollection<…>`.

`spatie/laravel-data` is an **optional runtime dependency**. The plugin ships in
the default `config/openapi.plugins` list, but `SpatieDataPlugin::register()`
is guarded by `class_exists(\Spatie\LaravelData\Data::class)` — without the
package installed it silently no-ops and imposes no autoload cost. Install
`spatie/laravel-data` to activate.

Covered in detail under [Request bodies](request-bodies.md). For PATCH semantics
with `Optional|…|null` typing, see
[Request bodies → PATCH semantics](request-bodies.md#patch-semantics).

**Ships these lint rules:**

| Rule | Level |
|---|---|
| `field.attribute-wrong-scope` | 1 |
| `multipart.file-without-multipart` | 1 |

See [`examples/spatie-data/`](../examples/spatie-data/) for a worked endpoint.

## ApiResources

Default-enabled. Controllers that return a typed `JsonResource` subclass are
documented automatically — no attribute needed for the response envelope.

### Declaring fields

To declare the fields the resource emits, add `#[ResourceField]` attributes at
the class level:

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

For collection endpoints that return a `JsonResponse` or an untyped value, tell
the generator which resource and envelope to use:

```php
#[OpenApi\ResponseResource(ProjectResource::class, collection: true)]
public function index(): JsonResponse { … }
```

Single responses wrap fields in `{ data: {…} }`; collection responses wrap them
in `{ data: [{…}], links: {…}, meta: {…} }`.

> [!NOTE]
> Omitting `#[ResourceField]` attributes triggers the `resource.fields-undeclared`
> lint rule (level 1).

**Ships these lint rules:**

| Rule | Level |
|---|---|
| `resource.fields-undeclared` | 1 |
| `resource.response-ambiguous` | 1 |
| `resource.field-type-missing` | 2 |

See [`examples/form-requests/`](../examples/form-requests/) for a worked
endpoint using ApiResources alongside FormRequests.

## QueryBuilder

Shipped **disabled**. Documents `spatie/laravel-query-builder` parameters as
OpenAPI query parameters.

### Enable

1. Install the runtime dependency:
   ```bash
   composer require spatie/laravel-query-builder
   ```
2. Uncomment the `QueryBuilderPlugin::class` entry under `plugins` in
   `config/openapi.php`.

### Usage

Declare the accepted parameters on the controller method:

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

Each `#[AllowedFilter]` becomes one `filter[name]` query parameter;
`#[AllowedSort]` becomes the `sort` parameter (comma-separated, with the listed
fields as `enum`); `#[AllowedInclude]` becomes `include` the same way.

**Ships these lint rules:**

| Rule | Level |
|---|---|
| `query-builder.params-undeclared` | 2 |
| `query-builder.filter-type-missing` | 3 |

A method that injects `QueryBuilder` but declares none of the three triggers
`query-builder.params-undeclared`; an `#[AllowedFilter]` without a `type`
triggers `query-builder.filter-type-missing`.

See [`examples/query-builder/`](../examples/query-builder/) for a worked
endpoint.

## Fractal

Shipped **disabled**. Documents `league/fractal` transformer responses with
three serializer envelopes: `DataArraySerializer` (default), `ArraySerializer`,
and `JsonApiSerializer`.

### Enable

1. Install the runtime dependency:
   ```bash
   composer require league/fractal
   ```
   (or `spatie/laravel-fractal`, which depends on it.)
2. Uncomment the `FractalPlugin::class` entry under `plugins` in
   `config/openapi.php`.

### Usage

Declare each transformer's output keys on the transformer class with repeatable
`#[TransformerField]` attributes; declare `availableIncludes` / `defaultIncludes`
entries with `#[TransformerInclude]`. Bind each endpoint to its transformer with
a method-level `#[FractalResponse]`:

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

The default envelope models Fractal's `DataArraySerializer` plus
`IlluminatePaginatorAdapter`. Set `serializer:` on `#[FractalResponse]` when the
action calls `Manager::setSerializer(…)` to switch shape:

```php
use Radiergummi\OpenApi\Plugins\Fractal\Serializer;

#[FractalResponse(transformer: BookTransformer::class, serializer: Serializer::ArraySerializer)]
public function arraySingle(): JsonResponse { … }      // bare $ref, no envelope

#[FractalResponse(transformer: BookTransformer::class, collection: true, serializer: Serializer::ArraySerializer)]
public function arrayCollection(): JsonResponse { … } // top-level array

#[FractalResponse(transformer: BookTransformer::class, serializer: Serializer::JsonApi)]
public function jsonApiShow(): JsonResponse { … }     // {data: {type, id, attributes: $ref}} as application/vnd.api+json
```

`Serializer::JsonApi` responses are emitted under `application/vnd.api+json`
instead of `application/json`. Custom serializers outside the three named cases
fall back to a `#[Response]` override on the action.

**Ships these lint rules:**

| Rule | Level |
|---|---|
| `fractal.fields-undeclared` | 1 |
| `fractal.duplicate-key` | 1 |
| `fractal.transformer-class-missing` | 1 |
| `fractal.response-unbound` | 2 |
| `fractal.include-transformer-missing` | 2 |

> [!NOTE]
> `fractal.response-unbound` is opt-in because the `fractal()` helper and the
> `Spatie\Fractalistic\Fractal` facade are invoked inside method bodies and
> never inject a `Manager`. The generator does not read method bodies — see
> [OAPI-017](known-gaps.md#oapi-017--no-method-body-inference) and
> [OAPI-053](known-gaps.md).
