# Plugins

A plugin registers resolvers, extractors, and lint rules for a specific
package or convention. Six plugins ship with the package:

| Plugin | Default | Requires | Documents |
|---|---|---|---|
| [`SpatieData`](#spatiedata) | enabled (no-ops without the package) | `spatie/laravel-data` | Data-class request bodies and responses |
| [`ApiResources`](#apiresources) | enabled | Laravel core | `JsonResource` / `ResourceCollection` responses |
| [`QueryBuilder`](#querybuilder) | disabled | `spatie/laravel-query-builder` | `filter[]` / `sort` / `include` query parameters |
| [`Fractal`](#fractal) | disabled | `league/fractal` | Fractal transformer responses |
| [`SwaggerPhp`](#swaggerphp) | disabled | swagger-php (bundled); `doctrine/annotations` for PHPDoc | Hand-authored `#[OA\*]` / `@OA` annotations |
| [`Fortify`](#fortify) | disabled | `laravel/fortify` | Fortify headless core-auth endpoints |

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
automatically.

### Inferred fields from `toArray()`

When a resource's `toArray()` is a **single `return [...]` array literal** —
the dominant shape in real apps — its keys become response properties without
any annotation. Each value resolves best-effort:

```php
/** @mixin Booking */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,             // model @property → string
            'passenger_name' => $this->passenger_name, // model @property → string
            'created_at'     => $this->created_at,     // model @property → date-time
            'flight'         => new FlightResource($this->whenLoaded('flight')),
            //                  ↳ $ref to FlightResource, optional (whenLoaded)
        ];
    }
}
```

- `$this->field` / `$this->resource->field` resolve against the **wrapped
  model's** metadata (`$casts`, `@property` / `@property-read` tags, typed
  `$appends` accessors, framework-managed timestamp columns). The model is
  discovered from the resource's class docblock: an
  `@mixin \App\Models\Booking` tag first, then a generic
  `@extends BaseResource<Booking>`.
- Literal scalars and arrays type themselves; nested literal arrays become
  nested object/array schemas.
- `new OtherResource(...)`, `OtherResource::make(...)`, and
  `OtherResource::collection(...)` values become a `$ref` to the nested
  resource's schema (an array of `$ref`s for `::collection`), cycle-guarded
  for self-referencing resources.
- `$this->when(...)` and `$this->whenLoaded(...)` mark the key **optional**
  (kept in `properties`, omitted from `required`) and resolve their inner
  value; a bare `whenLoaded('relation')` resolves the relation against the
  model. `$this->whenCounted(...)` documents an optional `integer`; any other
  `when*` wrapper keeps the key as an unconstrained optional property.
- `$this->merge([...])` / `$this->mergeWhen(..., [...])` inline their literal
  payload's keys at the top level (optional for `mergeWhen`); a non-literal
  payload is skipped with a generation-log note.
- Anything else (method calls, ternaries, fields the model does not know)
  keeps its key with an **unconstrained schema** — a response property is
  never silently dropped — and one summarising generation-log note per
  resource lists the affected keys.

A `toArray()` that is *not* a single straight-line array literal (early
returns, `array_merge(...)`, a returned variable) degrades gracefully: with no
declared fields the response falls back to the **wrapped model's schema** when
the docblock names one, plus a generation-log note; otherwise the
attribute-driven behaviour below applies. A resource that does not override
`toArray()` at all (the passthrough case) documents as the wrapped model's
schema directly — no empty `*Resource` component is created.

### Declared fields with `#[ResourceField]`

`#[ResourceField]` remains the escape hatch for anything the literal cannot
express (a runtime-computed value, a human description) — and it **wins per
field**: a declared field replaces the inferred one of the same name, while
inferred fields it does not cover compose alongside. Declare fields with
class-level attributes:

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

A scalar `items` (e.g., `items: 'string'`) is unchanged — it stays
`items: { type: string }`. An `items` class-string that no resolver recognises
degrades to a permissive `items: { type: object }`.

### Resolving the resource from the return expression

Many actions type their return as a **base** resource class — `JsonResource`, a
bare `ResourceCollection`, or `AnonymousResourceCollection` — and name the
concrete resource only in the method body. When the signature yields nothing
concrete, the generator reads the method's **return expression** (a bounded scan
of the first 10 statements; no dataflow):

```php
public function index(): AnonymousResourceCollection
{
    return ProjectResource::collection(Project::query()->paginate());
}
```

resolves the item resource to `ProjectResource` — no annotation needed.
Recognised shapes:

- `X::collection(...)` / `X::collect(...)` → collection of `X`; `X::make(...)` /
  `new X(...)` → a single `X`. `X` must be a concrete resource — a call on the
  base `JsonResource` itself or on an abstract subclass refuses (there is no
  field shape to document).
- The two-statement form `$projects = X::collection(...); return $projects;`,
  as long as the variable is assigned exactly once on the unconditional path.
- `->toResource(X::class)` / `->toResourceCollection(X::class)` — the literal
  class argument is decisive. A bare `$model->toResource()` works when `$model`
  is a Model-typed parameter: the resource resolves through Laravel's own
  convention (the model's `#[UseResource]` attribute, then the
  `App\Http\Resources\{Model}Resource` guess).
- `new JsonResource($model)` (the base class itself) wrapping a Model-typed
  parameter documents the **wrapped model's schema** directly.
- A `@return AnonymousResourceCollection<ProjectResource>` docblock generic is
  honoured and **wins over the body** when both are present.
- A `->additional(...)` chained onto a matched expression is ignored — the
  resource class stays certain; the extra envelope keys are not modelled.

The envelope follows the expression: a collection whose source visibly ends in a
`paginate()` / `simplePaginate()` / `cursorPaginate()` call documents the
`{data, links, meta}` envelope — looking through the paginator-preserving chain
links `withQueryString()`, `appends()`, `withPath()`, and `fragment()`, which
only tweak the generated URLs (`paginate(...)->withQueryString()` stays
paginated); any other collection source documents a plain `{data: [...]}`
envelope — pagination meta is never guessed. A `@return` docblock generic resolves
the item class but still derives the envelope from the body: `{data, links, meta}`
only when the body's `::collection($source)` argument visibly ends in a
`paginate()`-family call; plain `{data}` otherwise (including when no inspectable
body is available). Attribute-resolved collections always use `{data, links, meta}`
since attributes carry no body context.

Anything else — a conditional return, a variable of unknown origin, an
unrecognised chained call, a receiver that would need dataflow — degrades to the
previous behaviour with a generation-log note; `#[ResponseResource]` is the
escape hatch and always wins.

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
> A resource whose schema would stay empty — no `#[ResourceField]`, no readable
> `toArray()` literal, and no wrapped model to fall back to — triggers the
> `resource.fields-undeclared` lint rule (level 1).

Lint rules:

| Rule | Level |
|---|---|
| `resource.fields-undeclared` | 1 |
| `resource.response-ambiguous` | 1 |
| `resource.field-type-missing` | 2 |

Worked endpoint: [`examples/api-resources/`](../examples/api-resources/).

## QueryBuilder

Documents `spatie/laravel-query-builder` parameters as OpenAPI query
parameters.

### Enable

1. `composer require spatie/laravel-query-builder`
2. Uncomment `QueryBuilderPlugin::class` under `plugins` in
   `config/openapi.php`.

### Usage

A literal `QueryBuilder::for(...)` fluent chain in the controller method
documents itself — the allow-lists become query parameters with no attributes
(bounded scan of the first 10 top-level statements; no dataflow analysis):

```php
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

public function index(): JsonResponse
{
    $flights = QueryBuilder::for(Flight::class)
        ->allowedFilters(['status', AllowedFilter::exact('origin')])
        ->allowedSorts(['departs_at', '-number'])
        ->allowedIncludes(['bookings'])
        ->paginate();
    // → filter[status], filter[origin], sort, include
}
```

The chain must root at the real `Spatie\QueryBuilder\QueryBuilder` (a
same-named impostor class never matches); other chain links (`->where()`,
`->defaultSort()`, `->paginate()`, …) are walked through. Allow-list elements
are read from string literals (class-constant strings included) and from
Spatie's value-object static constructors (`AllowedFilter::exact(…)`,
`AllowedSort::field(…)`, `AllowedInclude::relationship(…)`, …) — the first
argument is the public wire name; internal names and `->defaultSort(…)` are
server-side detail and not modelled. Fluent modifiers on a value-object element
(`->nullable()`, `->default()`, `->ignore()`, `->delimiter()`,
`->defaultDirection()`) are walked through — they change server-side behaviour,
not the wire name, so `AllowedFilter::exact('healthy')->nullable()` still reads
as `filter[healthy]`. Both the array and the variadic form
(`allowedSorts('name', 'created_at')`) work, and so does a chain assigned to
a variable in one expression. A chain-derived filter gets a `string` schema —
use `#[AllowedFilter]` where a filter needs a type, format, or description.

Anything more indirect degrades gracefully: a builder assigned to a variable
and mutated across statements, a dynamically computed allow-list, or a chain
inside a conditional yields no chain parameters, attributes are still
honoured, and the generation log notes the action. A single non-literal
element inside an otherwise literal allow-list is dropped and the rest kept.

To declare parameters explicitly, use the plugin's attributes:

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

Attributes win over the chain **per kind**: any `#[AllowedFilter]` present
means all filters come from attributes, and likewise for `#[AllowedSort]` /
`#[AllowedInclude]` — the chain fills only the attribute-less kinds.

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

### Inferred fields from `transform()`

When a transformer's `transform()` is a **single `return [...]` array
literal** — the canonical transformer shape — its keys become response
properties without any annotation. Each value resolves best-effort:

```php
final class BookingTransformer extends TransformerAbstract
{
    public function transform(Booking $booking): array
    {
        return [
            'id'             => $booking->id,             // model @property → string
            'passenger_name' => $booking->passenger_name, // model @property → string
            'seat_row'       => (int) $booking->seat,     // cast → integer
            'kind'           => 'booking',                // literal → string
            'reference'      => $this->reference($booking), // unresolvable → {}
        ];
    }
}
```

- `$booking->field` resolves against the metadata of the **typed `transform()`
  parameter** (`$casts`, `@property` tags, typed `$appends` accessors) when
  that parameter's declared type is an Eloquent model.
- `(int)` / `(float)` / `(string)` / `(bool)` / `(array)` casts type the key by
  the cast — the cast states the runtime JSON type regardless of what it wraps.
  An `(array)` cast documents an array of unconstrained items (`items: {}`) —
  array of anything is the honest claim.
- Literal scalars and arrays type themselves; nested literal arrays become
  nested object/array schemas.
- Anything else (method calls, ternaries, fields the model does not know)
  keeps its key with an **unconstrained schema** — a response property is
  never silently dropped — and one summarising generation-log note per
  transformer lists the affected key paths, values inside nested literals
  included (`flags.rating`).

A `transform()` that is *not* a single straight-line array literal (early
returns, a returned variable, a dynamic key, a spread) degrades gracefully to
the attribute-declared shape below, plus a generation-log note when that
leaves the schema empty. All inferred fields are required — `transform()` has
no conditional-field idiom; model availability is Fractal *includes*
territory, declared with `#[TransformerInclude]`.

### Declared fields

`#[TransformerField]` remains the escape hatch for anything the literal cannot
express (a description, a format, an enum) — and it **wins per field**: a
declared field replaces the inferred one of the same name, while inferred
fields it does not cover compose alongside. Declare output keys with
repeatable `#[TransformerField]` attributes on the transformer class. Declare
`availableIncludes` / `defaultIncludes` entries with `#[TransformerInclude]`.
Bind each endpoint to its transformer with `#[FractalResponse]`:

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

### The `$entity_transformer` convention

Apps following the InvoiceNinja `BaseController` convention — a
`$entity_transformer` property defaulted per controller, returned through
inherited `itemResponse()` / `listResponse()` helpers — are documented without
any `#[FractalResponse]`:

```php
class InvoiceController extends BaseController
{
    protected $entity_transformer = InvoiceTransformer::class;

    public function show(ShowInvoiceRequest $request, Invoice $invoice): Response
    {
        return $this->itemResponse($invoice);   // single {data: $ref}
    }

    public function index(): Response
    {
        return $this->listResponse(Invoice::query()); // collection {data: [$ref]}
    }
}
```

The binding requires a top-level `return $this->itemResponse(…)` or
`$this->listResponse(…)` in the first 10 statements **and** a concrete
`TransformerAbstract` class-string as the property's declared *default* —
never a runtime value. The transformer's fields come from the same
attribute + `transform()` composition as above; the envelope is the
`DataArraySerializer` shape. A method that **reassigns**
`$entity_transformer` in its body degrades with a generation-log note (the
default is no longer the honest answer), as does a matched call without a
usable default; `#[FractalResponse]` always wins where declared. A
reassignment hidden inside a *called* helper is invisible to the bounded
scan — following calls is Tier-2 dataflow — so the property default is
documented; annotate such actions with `#[FractalResponse]`. The two
method names are a fixed whitelist — there is no configurable convention
knob.

### `fractal()` helper / facade / Manager call shapes

The two dominant Fractal invocation styles — the `fractal()` helper and the
`Spatie\Fractalistic\Fractal` facade — are documented without any
`#[FractalResponse]`, as is the injected-`Manager` resource-construction style:

```php
public function show(): JsonResponse
{
    return fractal()->item(new Booking(), new BookingTransformer())->respond();   // {data: $ref}
}

public function index(): JsonResponse
{
    return Fractal::create()
        ->collection(Booking::all(), new BookingTransformer())
        ->respond();                                                              // {data: [$ref]}
}

public function managed(Manager $fractal): JsonResponse
{
    $resource = new Item(new Booking(), new BookingTransformer());

    return new JsonResponse($fractal->createData($resource)->toArray());          // {data: $ref}
}
```

The binding reads the first `return` expression (and, for the `Manager` style, a
`new Item(…)` / `new Collection(…)` resource) within the first 10 statements,
and requires:

- an `->item(…)` / `->collection(…)` chain link whose root is literally the
  `fractal()` helper or the `Fractal` facade (so the same method names on an
  unrelated service or query builder never match), or a single `new Item(…)` /
  `new Collection(…)` resource — `item` / `Item` binds the single envelope,
  `collection` / `Collection` the collection envelope;
- a transformer named by a literal `new T()` or `T::class` argument that extends
  `TransformerAbstract` and yields documentable fields.

A trailing `->serializeWith(new ArraySerializer())` / `JsonApiSerializer` maps
onto the matching serializer envelope. All Fractal classes are matched by name,
so neither package need be installed for the scan to run.

The **bare two-argument helper** `fractal($data, new T())` is **refused**: item
vs collection is not statically knowable from the first argument, so the
generator emits a generation-log note rather than guessing an envelope — annotate
those actions with `#[FractalResponse]`. A variable/dynamic transformer, an
unrecognised serializer, or a transformer with no documentable fields likewise
degrade with a note; `#[FractalResponse]` always wins where declared.

Lint rules:

| Rule | Level |
|---|---|
| `fractal.fields-undeclared` | 1 |
| `fractal.duplicate-key` | 1 |
| `fractal.transformer-class-missing` | 1 |
| `fractal.response-unbound` | 2 |
| `fractal.include-transformer-missing` | 2 |

`fractal.fields-undeclared` stays quiet when the transformer's `transform()`
literal is readable and yields fields — the schema is not empty then.

> [!NOTE]
> `fractal.response-unbound` is opt-in and keys off an **injected `Manager`
> parameter** only. The `fractal()` helper and `Fractal` facade shapes never
> inject a `Manager`, so they never trigger this rule — even now that the
> generator reads them (see the call-shape binding above). The rule remains a
> conservative backstop for the one Fractal style whose response the generator
> cannot bind without an attribute when it carries no literal transformer.

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
ones inference now reproduces on its own. The plugin registers the
`migration.*` lint rules — `migration.oa-redundant-with-inference` (schemas) and
`migration.oa-redundant-operation-with-inference` (operations) — for exactly
that: run `php artisan openapi:lint --only 'migration.*'` to find the redundant
`#[OA\*]` / `@OA` annotations and add `--fix` to remove them. See
[Linting → Migration rules](linting.md#migration-rules-migration), and
[Migrating from L5-Swagger](migrating-from-l5-swagger.md) for the end-to-end path.

Worked endpoint: [`examples/swagger-php/`](../examples/swagger-php/).

## Fortify

Documents Laravel [Fortify](https://laravel.com/docs/fortify)'s headless core-auth
endpoints — login, logout, register, password reset/update, password confirmation,
and profile-information update — from a hand-maintained stock-contract table. Fortify
exposes no typed request or response DTOs the generator could read, so the table
encodes the framework's documented request rules and JSON responses directly.

**Off by default.** It is the one bundled plugin scoped to a third-party auth package,
so it ships disabled.

### Enable

1. `composer require laravel/fortify`.
2. Uncomment `FortifyPlugin::class` under `plugins` in `config/openapi.php`.

The plugin no-ops if Fortify is not installed.

### What it documents

Matched purely by **route name** (the body-bearing actions — `login.store`,
`register.store`, `password.confirm.store` — plus `logout`, `password.email`,
`password.update`, `password.confirmation`, `user-password.update`,
`user-profile-information.update`). For each matched route it emits:

- **The stock request body** — always. The documented validation rules become a JSON
  object schema (e.g., login → `email` + `password` + optional `remember`).
- **The stock success response** — *only when the route's Fortify response contract is
  unmodified*. The plugin inspects the container binding for the governing contract
  (e.g., `LoginResponse`): if it still maps to a `Laravel\Fortify\…` class, the stock
  body and status are emitted; if the app has rebound it to a custom response, the body
  is unknowable, so the plugin emits the **status code only** — never a possibly-wrong
  body. A binding it cannot read statically (a closure that constructs the response
  inline) is treated as customized, conservatively.

Error responses fall through to the configured [`error_envelope`](config.md). An
authoring attribute on a route always overrides what the plugin would emit.

### Scope

v1 covers the **core auth** surface above. Two-factor authentication and email
verification are deferred to a follow-up. Response-body fidelity tracks the documented
stock contract of the supported Fortify line; an app that customizes a response contract
gets the honest status-only fallback for that endpoint.
