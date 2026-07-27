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

### Installed-but-disabled hints

When an integration package (`league/fractal` / `spatie/laravel-fractal`,
`spatie/laravel-query-builder`) is installed but its plugin is not enabled,
`openapi:generate` prints a one-line advisory on stderr pointing you at the
config line that would let it infer schemas and parameters from that package.
The hint is advisory only: nothing is ever auto-enabled, and the document
written to stdout under `--output=-` stays untouched.

To write your own, see [Plugin authoring](plugin-authoring.md).

## SpatieData

Reads request and response schemas from Spatie Data classes, including
`DataCollection<…>` and `PaginatedDataCollection<…>`.

When an action types its return as a **generic container** that carries no item
type — `Illuminate\Support\Collection`, Eloquent `Collection`, `LazyCollection`,
or builtin `array` — and names the Data class only in the body, the response is
resolved from the return expression (a bounded scan of the first 10 statements):
a literal `DataClass::collect(...)` yields an array of `$ref`s, and `new
DataClass(...)` a single `$ref`. The Data class must be a real `Data` subclass;
any other shape (a service call, a conditional or multiple returns, a non-Data
class) degrades silently to no schema.

A return typed as several Data classes (`FooData|BarData`) yields a `oneOf` of
their `$ref`s (a single member collapses to a bare `$ref`). A nullable Data
return (`?FooData`, or a union with a `null` member) is modelled nullable via the
OAS 3.1 `oneOf … {type: null}` idiom.

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
any annotation. A body that builds the array in a variable and returns it
(`$data = [...]; return $data;`) is read the same way, as long as the variable
is assigned that literal exactly once, unconditionally. A *conditional* later
addition (`if (…) { $data += [...]; }`) is fine — its keys stay unread and the
base literal remains a never-wrong subset — but an *unconditional* extra write
(`$data['k'] = …` or `$data += [...]`) would drop always-present fields, so the
reader refuses it and falls back to the wrapped model's schema instead. Each
value resolves best-effort:

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
- When the wrapped `@mixin` / `@extends` class is a **non-Model value object**
  with statically-typed public properties (promoted constructor parameters or
  declared `public` typed properties), `$this->field` types from that
  property's type instead. A `$this->wrapped->field` read — where `$wrapped` is
  a typed property on the resource — likewise types from the value object
  declared as `$wrapped`'s type. An `array` / `list<T>` / `array<string, T>` /
  `array{…}` property, or one refined by a `@var` tag, types as the matching
  array / map / object schema. Properties with no declared type, a
  union/intersection type, or a type that would only map to a placeholder stay
  **unconstrained** (never-wrong); the model path is always tried first.
  Formatting such a property is typed too — see the `->format(…)` bullet below.
- `$this->field->format(…)` / `$this->wrapped->field->format(…)` on a receiver
  already typed as a date (`format: date-time` / `date`) documents a
  **`string`**, refined to
  `format: date-time` when the format argument is an RFC3339 one (`DATE_ATOM`,
  `DATE_RFC3339`, `DATE_W3C`, `DateTimeInterface::ATOM`,
  `DATE_RFC3339_EXTENDED`, or `'c'`) and to `format: date` for `'Y-m-d'`. Any
  other format string keeps the plain `string` — including the legacy
  `DATE_ISO8601` and `'Y-m-d H:i:s'`, which are not RFC3339, and an argument
  that is not a compile-time literal. A plain `->format(…)` also drops the
  `null` a nullable timestamp carries, since reaching the call proves the value
  was there; `?->format(…)` keeps it nullable. The date evidence may come from
  the wrapped model or from a value object's statically-typed public property —
  both the `@mixin` / `@extends` value object and a `$this->wrapped->field`
  read. Receivers neither source types as a date, a model relation hop
  (`$this->parent->published_at->format(…)`) among them, stay
  **unconstrained**.
- Literal scalars and arrays type themselves; nested literal arrays become
  nested object/array schemas.
- `new OtherResource(...)`, `OtherResource::make(...)`, and
  `OtherResource::collection(...)` values become a `$ref` to the nested
  resource's schema (an array of `$ref`s for `::collection`), cycle-guarded
  for self-referencing resources.
- `$this->when(...)`, `$this->unless(...)` (its inverse), and
  `$this->whenLoaded(...)` mark the key **optional** (kept in `properties`,
  omitted from `required`) and resolve their inner value; a bare
  `whenLoaded('relation')` resolves the relation against the model.
  `$this->whenCounted(...)` documents an optional `integer`; any other `when*`
  wrapper keeps the key as an unconstrained optional property.
- `$this->merge([...])` / `$this->mergeWhen(..., [...])` inline their literal
  payload's keys at the top level (optional for `mergeWhen`); a non-literal
  payload is skipped with a generation-log note.
- Anything else (ternaries, other method calls, fields the model does not know)
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
concrete resource only in the method body. Others declare **no return type at
all** (relying on convention or a third-party doc attribute), a **generic
container** that carries no item type (`Illuminate\Support\Collection`, Eloquent
`Collection`, `LazyCollection`, builtin `array`), or a **loose response wrapper**
too generic to name a payload (`Illuminate\Http\JsonResponse`,
`Illuminate\Http\Response`, and their Symfony parents). In each case the signature
yields nothing concrete, so the generator reads the method's **return
expression**. What makes the read sound is that the method has exactly one
unconditional return (or several that agree), not how far the scan looked — it
reads the whole method body, bounded only by a 100-statement backstop against
pathological input, and does no dataflow:

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
  class argument is decisive. A bare `$model->toResource()` (no class argument)
  resolves through Laravel's own convention (the model's `#[UseResource]`
  attribute, then the `App\Http\Resources\{Model}Resource` guess) whenever the
  receiver's Model type is statically declared. That covers a Model-typed
  parameter, a Model-typed property, a method whose declared return is a concrete
  Model, a base-`Model`/`self` passthrough call typed from its Model argument
  (`$request->resolve($model)->toResource()`), a local assigned from
  `new Model()` or a model-returning static factory
  (`Model::create()`/`findOrFail()`/`firstOrFail()`/…), and a local narrowed by
  `assert($model instanceof Model)`. The narrowing counts only when the `assert`
  **precedes** the return being resolved, the name is **not rebound** between the
  two (a reassignment, destructuring, reference, `foreach` target, and so on),
  and it is the **only** such assert — otherwise the asserted type would be a
  guess. A receiver whose type is not statically known (a query-builder chain, an
  untyped local) still refuses.
- `new JsonResource($model)` (the base class itself) wrapping a Model-typed
  parameter documents the **wrapped model's schema** directly.
- A `@return AnonymousResourceCollection<ProjectResource>` docblock generic is
  honoured and **wins over the body** when both are present.
- A `->additional(...)` chained onto a matched expression is ignored — the
  resource class stays certain; the extra envelope keys are not modelled.
- A resource wrapped in a **direct, unchained** `response()->json(<resource>, <status>)`
  is unwrapped to the resource, which is then resolved by the shapes above
  (`response()->json(X::make($m), 201)` documents the `X` schema under `201`). A
  statically-readable **2xx status other than 204** — an integer literal or a class
  constant such as `Response::HTTP_ACCEPTED`, positional or named
  (`status: 202`) — is honoured and **wins over the resourceful-route convention**,
  so a `store()` authoring `202` documents `202`, not the convention's `201`. A
  `self::`/`static::` constant is not resolved (the same limitation the literal-body
  reader has), and neither is a variable; both keep the conventional status. A
  `204` never reaches this path at all: the inline-JSON resolver claims it first and
  documents a body-less `204`, since a resource envelope must not ride on a
  contentless status.
  Chaining anything but `->additional(...)` after `json()` — `->header(...)`,
  `->setStatusCode(...)` — makes the scan refuse the expression outright, so no
  resource is resolved and the operation falls back to a bare `200`. (Distinct from
  the literal-body `->setStatusCode()` rule in
  [Auto-derivation → Inline JSON responses](auto-derivation.md#inline-json-responses),
  which reads a literal body rather than a resource.)

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

When a method has several top-level `return` statements, each is resolved
through the same whitelist and the resource is emitted only when they **all
agree** on class, cardinality, and pagination (a bare `return;` or `return null;`
guard clause is ignored). Multiple returns that diverge — or where any branch is
unresolvable — keep degrading.

Anything else — a conditional return, a variable of unknown origin, an
unrecognised chained call, a receiver that would need dataflow — degrades to the
previous behaviour with a generation-log note; `#[ResponseResource]` is the
escape hatch and always wins. On the **untyped** and **loose-response-wrapper**
paths that note is suppressed: the scan runs on every such action and most are
not resources, so a notice per non-resource would be pure noise. The
base-resource paths keep their notes.

### Collection endpoints

An action typed `JsonResponse` (or `Response`, or untyped) that returns a
standard resource construction is now inferred from the body — no attribute
needed:

```php
public function index(): JsonResponse
{
    return ProjectResource::collection(Project::query()->paginate());
}
```

Reach for `#[ResponseResource]` only when the resource is not statically
readable from the body (a genuinely loose runtime shape, a conditional return,
or a receiver that would need dataflow); it names the resource and envelope
explicitly and always wins:

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

### First-party JSON:API resources

Laravel 13's `Illuminate\Http\Resources\JsonApi\JsonApiResource` replaces
`toArray()` with a set of document-member methods. Each is read with the same
bounded single-`return [...]` literal rule as `toArray()`, and the result is
assembled into a JSON:API **resource object** rather than a flat field bag:

```php
/** @template-extends ApiResource<Article> */
class ArticleResource extends JsonApiResource
{
    public const string FIELD_TITLE = 'title';

    public function toAttributes(Request $request): array
    {
        return [
            self::FIELD_TITLE => $this->resource->title,
            'publishedAt' => $this->resource->published_at,
        ];
    }

    public function toRelationships(Request $request): array
    {
        return ['author' => $this->whenLoaded('author')];
    }
}
```

```yaml
type: object
required: [type, id]
properties:
  type: { type: string }
  id: { type: string }
  attributes:
    type: object
    required: [title, publishedAt]
    properties:
      title: { type: string }          # typed from the wrapped model
      publishedAt: { type: string, format: date-time }
  relationships:
    type: object
    properties:
      author: { type: object }
```

Notes:

- `type` and `id` are always emitted; Laravel derives them even when the
  subclass leaves the framework defaults alone.
- `attributes`, `relationships`, `links` and `meta` appear only when the
  subclass overrides the corresponding method with a readable literal, so a
  resource that emits no relationships gets no empty `relationships` object.
- Relationship values are documented as objects. Only the relationship *names*
  are statically known — the member is really `{data, links, meta}` and the
  cardinality of `data` is not visible to the reader, so a permissive object is
  emitted rather than a guessed shape.
- `#[ResourceField]` declarations describe `attributes` members, and win per
  field as they do for `toArray()`.
- Constant keys (`self::FIELD_TITLE`) resolve, as do `static::` ones.

Resources whose bodies are not single literals degrade to `type` + `id` only;
`#[ResourceField]` is the escape hatch.

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
properties without any annotation. A body that builds the array in a variable
and returns it (`$data = [...]; return $data;`) is read the same way, as long
as the variable is assigned that literal exactly once, unconditionally; a
*conditional* later `$data += [...]` is fine, but an *unconditional* extra write
makes the reader refuse and degrade. Each value resolves best-effort:

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

The dominant Fractal invocation styles — the `fractal()` helper, the
`Spatie\Fractalistic\Fractal` facade, and the `spatie/laravel-fractal`
`$this->fractal` builder — are documented without any `#[FractalResponse]`, as is
the injected-`Manager` resource-construction style:

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

public function listing(): JsonResponse
{
    return $this->fractal
        ->collection(Booking::all())
        ->transformWith(new BookingTransformer())                                 // {data: [$ref]}
        ->respond();
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
  `fractal()` helper, the `Fractal` facade, or the `$this->fractal` property (so
  the same method names on an unrelated service or query builder never match), or
  a single `new Item(…)` / `new Collection(…)` resource — `item` / `Item` binds
  the single envelope, `collection` / `Collection` the collection envelope;
- a transformer named by a literal `new T()` or `T::class` argument that extends
  `TransformerAbstract` and yields documentable fields. The transformer is taken
  from the `item()` / `collection()` second argument, or — when that is absent —
  from a separate `->transformWith(new T())` / `->transformWith(T::class)` link in
  the same chain.

A trailing `->serializeWith(new ArraySerializer())` / `JsonApiSerializer` maps
onto the matching serializer envelope. All Fractal classes are matched by name,
so neither package need be installed for the scan to run.

The **bare two-argument helper** `fractal($data, new T())` is **refused**: item
vs collection is not statically knowable from the first argument, so the
generator emits a generation-log note rather than guessing an envelope — annotate
those actions with `#[FractalResponse]`. A variable/dynamic transformer (including
a `->transformWith($this->getTransformer(…))` wrap), an unrecognised serializer, or
a transformer with no documentable fields likewise degrade with a note;
`#[FractalResponse]` always wins where declared.

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
- A reusable `@OA\Response(response: 'X')` / `@OA\Parameter(parameter: 'Y')`
  **component** definition is harvested under its authored name into
  `components.responses` / `components.parameters`. An operation pointing at one
  by `$ref` (`@OA\Response(ref: '#/components/responses/X')`) keeps its `$ref`,
  now that the target component is emitted; a `$ref` to a component name the scan
  does not find is dropped and logged, never emitted as a dangling `$ref`.

Referenced schemas are pulled in transitively under their authored names (a
harvested response/parameter component drags in the schemas it references too). A
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
