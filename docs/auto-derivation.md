# Auto-derivation

Every operation is built from the controller's signature, PHPDoc, and route
middleware. The table below maps each part of the OpenAPI operation to its
source.

| Aspect | Source |
|---|---|
| Tag | Last meaningful segment of the controller's namespace. Skips generic segments (`Controllers`, `Http`, `App`, `Internal`, `External`, `Global`, `V0`, …) and the controller class itself. |
| Summary | First paragraph of the method's PHPDoc, or `#[Summary]` / `#[Operation(summary: …)]`. |
| Description | Remaining paragraphs of the method's PHPDoc (markdown permitted), or `#[Description]` / `#[Operation(description: …)]`. |
| `operationId` | Route name (sanitised to a codegen-safe identifier — `:`/`{}` and other disallowed characters become `_`, while `.`/`-`/`_` are kept), or `{method}_{sanitized_path}`. |
| Path parameters | Action signature. Type hints, `Route::whereUuid()` / `whereNumber()` / `where(...)` constraints, and route-model-binding heuristics drive type and format. |
| Request body | Spatie Data class on the action (or on a configured payload-indirection object); `FormRequest` is supported natively. Schema is built from PHP types and validation rules. |
| Response body | Spatie Data class or `DataCollection<…>` return type → component `$ref`. `JsonResource` subclass → component schema (fields declared via `#[ResourceField]`). Eloquent `Model` subclass → component schema built from `$casts`, `@property`/`@property-read` annotations, typed `$appends` accessors, and `$hidden`/`$visible`. See [Eloquent model response schemas](#eloquent-model-response-schemas). |
| Security | `auth:*` / `scope:*` middleware → a per-operation `security` requirement against the derived scheme(s): Passport's OAuth2 flows, a `sanctum` http/bearer scheme when any route uses `auth:sanctum`, or `openapi.security_schemes`. When the route is authed but no scheme is derivable, `security` is omitted (not `[]`, which means *public*) and `operation.security-missing` flags it. |
| Error responses | `@throws ExceptionClass` → status codes; `auth`/`scope`/`throttle` middleware → 401 / 403 / 429. |
| Validation constraints | `Data::rules()` and Spatie validation attributes → `maxLength`, `minLength`, `pattern`, `enum`, `format`, `minimum`/`maximum`, `minItems`/`maxItems`. |

Use an authoring attribute when convention can't produce what you need. See
the [escape-hatch table](#what-if-convention-isnt-enough) below.

## A worked endpoint

This route definition:

```php
Route::middleware(['auth:api', 'scope:flights:read'])
    ->get('/flights/{flight}', [FlightController::class, 'show'])
    ->name('flights.show');
```

```php
namespace App\Http\Controllers\Api\V0\Flights;

#[Tag('Flights')]
final class FlightController
{
    /**
     * Show a single flight.
     *
     * Returns the full flight envelope including aircraft and crew.
     *
     * @throws ModelNotFoundException
     */
    public function show(Flight $flight): FlightData
    {
        return FlightData::from($flight);
    }
}
```

produces an operation with:

- `tags: [Flights]` from `#[Tag]` (or, without it, the `Flights` namespace segment).
- `summary: Show a single flight.` from the first docblock paragraph.
- `description: Returns the full flight envelope including aircraft and crew.` from the remaining paragraphs.
- `operationId: flights.show` from the route name.
- A `flight` path parameter with type and format inferred from the `Flight` model binding.
- A 200 response with `$ref: '#/components/schemas/FlightData'`.
- A 404 response from `@throws ModelNotFoundException`.
- A 401 response from `auth:api`.
- A 403 response from `scope:flights:read`.
- A security requirement for the `flights:read` scope.

`#[Tag]` is optional if the namespace-derived tag is acceptable; no other
attributes are required here.

## Eloquent model response schemas

When a controller action's return type is an Eloquent `Model` subclass, the
generator builds a component schema from the model's class-level metadata and
emits a `200 application/json` response pointing to it. The same applies when
the return type is `Illuminate\Support\Collection` or
`Illuminate\Database\Eloquent\Collection` annotated with a `@return
Collection<MyModel>` generic — the response schema becomes
`{type: array, items: {$ref: '#/components/schemas/MyModel'}}`.

Everything is derived from the class by reflection; no database connection or
query is required.

### Property sources and precedence

The property set is the union of `$casts` keys, `$fillable`, `$appends`, and
`@property`/`@property-read` names in the class docblock, filtered through
`$hidden` (excluded) and `$visible` (when non-empty, acts as an allow-list).

For each property, the type is resolved in this order:

1. **`$casts`** — the cast value drives the schema type. `datetime` / `date` → `string` (format `date-time` / `date`); `decimal:N` → `string`; `array` / `json` / `object` / `collection` → object; a backed-enum class-string → inline `enum` schema listing the enum's cases.
2. **`@property` / `@property-read` docblock** — scalar types (`int`, `bool`, `float`, `string`) are mapped directly; `?T` marks the property as nullable. A class type that is itself a `Model` subclass becomes a `$ref` to that model's component schema (built recursively; cycles are guarded).
3. **`$appends` accessor return type** — a legacy-style accessor (`getReadingTimeAttribute(): int`) contributes its return type for an appended attribute.
4. **Unknown** — if none of the above supply a type, an unconstrained schema with no `type` is emitted.

### `required`

A property appears in `required` only when it has a non-nullable
`@property` or `@property-read` annotation. Properties known only from
`$casts`, `$fillable`, or `$appends` are never required — they may be absent
at runtime and the generator has no way to prove otherwise.

### Example

```php
/**
 * @property-read int          $id
 * @property      string       $name
 * @property      string|null  $bio
 * @property-read Carbon       $created_at
 * @property-read Category     $category   Eager-loaded relation.
 */
class Article extends Model
{
    protected $casts = [
        'name'       => 'string',
        'published'  => 'boolean',
    ];

    protected $hidden = ['password'];

    public function getReadingTimeAttribute(): int
    {
        return (int) ceil(str_word_count($this->body) / 200);
    }

    protected $appends = ['reading_time'];
}
```

```php
class ArticleController extends Controller
{
    public function show(Article $article): Article
    {
        return $article;
    }
}
```

The generator emits a `200 application/json` response with
`$ref: '#/components/schemas/Article'` and a component schema along these lines:

```yaml
Article:
  type: object
  required: [id, name, created_at, category]   # every non-nullable @property / @property-read
  properties:
    id:           { type: integer }
    name:         { type: string }
    bio:          { type: string, nullable: true }
    published:    { type: boolean }            # from $casts
    created_at:   { type: string, format: date-time }
    category:     { $ref: '#/components/schemas/Category' }  # Model relation, built recursively
    reading_time: { type: integer }            # from typed legacy accessor
```

`password` is absent because it is in `$hidden`.

### Documenting computed fields

A field in `$appends` without a typed legacy accessor falls back to an
unconstrained schema. Document it with `@property-read` or use a typed
legacy accessor:

```php
/** @property-read string $slug  URL-safe slug derived from the title. */
class Post extends Model
{
    protected $appends = ['slug'];

    // typed legacy accessor — return type also works without the docblock:
    public function getSlugAttribute(): string
    {
        return Str::slug($this->title);
    }
}
```

> [!NOTE]
> New-style `Attribute::get()` accessors (`public function slug(): Attribute`)
> do not expose their value type via reflection. An `$appends` entry backed only
> by a new-style accessor falls back to an unconstrained schema — use a typed
> legacy accessor or `@property-read` to include the type.

### Known limitations

- **Method-body inference is not done.** `Model::find()` / `findOrFail()` return
  statements are not read (Tier-1, tracked as [#97](https://github.com/radiergummi/laravel-openapi/issues/97)).
  Type your action's return type explicitly to get a response schema.
- **`JsonResource` wrapping a model** is documented by the ApiResources plugin
  separately (tracked as [#98](https://github.com/radiergummi/laravel-openapi/issues/98));
  the model schema and the resource schema are currently independent.

## What if convention isn't enough?

Authoring attributes live in `Radiergummi\OpenApi\Attributes`. Pick the
one matching the override you need:

| Goal | Attribute |
|---|---|
| Override summary, description, `operationId`, or tags | [`#[Operation]`](attributes.md#operation-level-attributes) |
| Document an ad-hoc query parameter | [`#[QueryParam]`](recipes.md#document-an-ad-hoc-query-parameter) |
| Add an error response not in `@throws` | [`#[Response]`](recipes.md#add-an-error-response-that-isnt-in-throws) |
| Enrich a request- or response-body field | [`#[RequestField]` / `#[ResponseField]`](recipes.md#enrich-a-request-body-field) |
| Document a computed / virtual Eloquent model field | `@property-read` docblock annotation or a typed legacy accessor (`getXAttribute(): T`) |
| Force a response resource the generator can't infer | [`#[ResponseResource]`](recipes.md#force-the-response-resource) |
| Hide an endpoint from production docs | [`#[Hide]`](recipes.md#hide-an-endpoint-from-production-docs) |
| Document a polymorphic response | [`#[Discriminator]`](recipes.md#document-a-polymorphic-response-with-a-discriminator) |
| Document an inbound webhook | [`#[Webhook]`](recipes.md#document-an-inbound-webhook) |
| Suppress a lint finding | [`#[IgnoreLint]`](linting.md#suppress-a-finding) |

Full reference: [Attributes](attributes.md). Runnable snippets: [Recipes](recipes.md).

## Conventions for new endpoints

1. Write a one-paragraph PHPDoc summary above the action.
2. Type-hint the request body as a `Data` class or `FormRequest`; declare
   `rules()` for constraints.
3. Type-hint the route-bound model parameter. Chain `->whereUuid(...)` or
   `->whereNumber(...)` on the route for regex constraints.
4. Apply `auth:api` and `scope:foo` middleware as usual.
5. Return a typed resource, or annotate with `#[ResponseResource]`.
6. Add `@throws` for every exception that maps to an HTTP error.
