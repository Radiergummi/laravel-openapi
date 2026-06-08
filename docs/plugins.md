# Plugins

A plugin registers resolvers, extractors, and lint rules for a specific
package or convention. Five plugins ship with the package:

| Plugin | Default | Requires | Documents |
|---|---|---|---|
| [`SpatieData`](#spatiedata) | enabled (no-ops without the package) | `spatie/laravel-data` | Data-class request bodies and responses |
| [`ApiResources`](#apiresources) | enabled | Laravel core | `JsonResource` / `ResourceCollection` responses |
| [`QueryBuilder`](#querybuilder) | disabled | `spatie/laravel-query-builder` | `filter[]` / `sort` / `include` query parameters |
| [`Fractal`](#fractal) | disabled | `league/fractal` | Fractal transformer responses |
| [`SwaggerPhp`](#swaggerphp) | disabled | swagger-php (bundled); `doctrine/annotations` for PHPDoc | Hand-authored `#[OA\*]` / `@OA` annotations |

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

For a field that is a **collection** of another resource, keep `type: 'array'`
and pass the item resource's class-string as `items`. It resolves to
`items: { $ref: … }` (and registers the item resource as a component), exactly
like a single nested `type:`:

```php
#[ResourceField('members', type: 'array', items: UserResource::class, description: 'Project members.')]
class ProjectResource extends JsonResource { … }
```

A scalar `items` (e.g. `items: 'string'`) is unchanged — it stays
`items: { type: string }`. An `items` class-string that no resolver recognises
degrades to a permissive `items: { type: object }`.

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
use Radiergummi\OpenApi\Plugins\Fractal\Support\Serializer;

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
> the generator does not read method bodies.

## SwaggerPhp

Harvests the swagger-php annotations an app already wrote — `#[OA\Schema]` /
`@OA\Schema` definitions on models and operation-level `@OA` annotations on
controllers — and merges the resulting schemas and response bodies into the
generated document. For an app already documented for L5-Swagger / swagger-php,
this recovers response schemas the library would otherwise have no way to infer.
There is no inference risk: the schemas are authored by the developer.

### Enable

1. The swagger-php library is already a dependency. To harvest `@OA` **PHPDoc**
   annotations (not just `#[OA\*]` attributes), also
   `composer require doctrine/annotations`.
2. Uncomment `SwaggerPhpPlugin::class` under `plugins` in `config/openapi.php`.

The plugin scans `app_path()` for authored annotations once per generation.

### What it does

The library still owns the operation skeleton it infers from your routes
(path, HTTP method, parameters, security). The harvester contributes only
schemas and response bodies on top:

- A model carrying `#[OA\Schema]` / `@OA\Schema` is registered as a component
  under its **authored schema name**. When a controller action returns that
  class and has no response body yet, the schema becomes its `200` body.
- An operation-level `@OA\Get` / `@OA\Post` / … on a controller method
  contributes its `@OA\Response`s to the matching operation — authored wins per
  status code, and the inferred responses for other statuses are kept. The
  authored `summary` / `description` / `operationId` / `tags` are adopted too
  (the annotation is the source of truth for the operation it describes).

Referenced schemas are pulled in transitively under their authored names. A
response that references a schema the scan cannot find is skipped and logged,
rather than emitted as a dangling `$ref`.

```php
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'Aircraft', required: ['id'])]
final class Aircraft
{
    #[OA\Property(property: 'id', type: 'integer')]
    public int $id = 0;
}
```

```php
// returns Aircraft -> 200 body becomes $ref: '#/components/schemas/Aircraft'
public function show(string $id): Aircraft { … }
```

> [!NOTE]
> `@OA` **PHPDoc** annotations are parsed only when `doctrine/annotations` is
> installed (an optional swagger-php dependency). `#[OA\*]` **attributes** are
> harvested without it. Scanning the application directory adds to generation
> time, which is why the plugin is off by default.

### Migrating off the annotations

Harvesting keeps your annotations working, but the goal is usually to delete the
ones inference now reproduces on its own. The plugin registers a migration lint
rule, `migration.oa-redundant-with-inference`, for exactly that — run
`php artisan openapi:lint --migrate` to find redundant `#[OA\Schema]` / `@OA\Schema`
blocks and `--migrate --fix` to remove them. See
[Linting → Migration mode](linting.md#migration-mode---migrate).

Worked endpoint: [`examples/swagger-php/`](../examples/swagger-php/).
