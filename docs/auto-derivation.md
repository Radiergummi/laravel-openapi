# Auto-derivation

Every operation is built from the controller's signature, PHPDoc, and route
middleware. The table below maps each part of the OpenAPI operation to its
source.

| Aspect | Source |
|---|---|
| Tag | The controller's short class name with a trailing `Controller` stripped and the remainder pluralised (`PostController` → `Posts`). For closure/controllerless routes, the StudlyCased last segment of the route-group prefix (`prefix('webhooks')` → `Webhooks`); failing that, `General`. |
| Summary | First paragraph of the method's PHPDoc, or `#[Summary]` / `#[Operation(summary: …)]`. |
| Description | Remaining paragraphs of the method's PHPDoc (markdown permitted), or `#[Description]` / `#[Operation(description: …)]`. |
| `operationId` | Route name (sanitised to a codegen-safe identifier — `:`/`{}` and other disallowed characters become `_`, while `.`/`-`/`_` are kept), or `{method}_{sanitized_path}`. |
| Path parameters | Action signature. Type hints, `Route::whereUuid()` / `whereNumber()` / `where(...)` constraints, and route-model-binding heuristics drive type and format. A custom-key binding (`/posts/{post:slug}`, including scoped-nested `{parent}/{child:field}`) emits the standard `{post}` template segment and notes the bound field in the description (`Bound by slug of Post.`). |
| Request body | Spatie Data class on the action (or on a configured payload-indirection object); `FormRequest` is supported natively. Schema is built from PHP types and validation rules. Without a typed payload parameter, inline `validate()` calls and a controller-declared `$rules` property / `rules()` method are read from the method body (bounded scan; see [Request bodies → Inline validation in the controller](request-bodies.md#inline-validation-in-the-controller)). |
| Response body | Spatie Data class or `DataCollection<…>` return type → component `$ref`. `JsonResource` subclass → component schema (fields declared via `#[ResourceField]`). Eloquent `Model` subclass → component schema built from `$casts`, `@property`/`@property-read` annotations, typed `$appends` accessors, and `$hidden`/`$visible`. See [Eloquent model response schemas](#eloquent-model-response-schemas). |
| Security | `auth:*` / `scope:*` / `scopes:*` (and Sanctum's `abilities:*` / `ability:*`) middleware → a per-operation `security` requirement against the derived scheme(s): Passport's OAuth2 flows, a `sanctum` http/bearer scheme when any route uses `auth:sanctum`, or `openapi.security_schemes`. Sanctum's all-of `abilities:a,b` lists both as scopes on one requirement; its any-of `ability:a,b` emits one OR-alternative requirement per ability. Map project-specific guard middleware to a declared scheme via `openapi.security_middleware_map`. When the route is authed but no scheme is derivable, `security` is omitted (not `[]`, which means *public*) and `operation.security-missing` flags it. |
| Error responses | `@throws ExceptionClass` → status codes; a route-model-bound parameter (`show(Post $post)`) → 404; a `FormRequest` parameter → 422; `auth`/`scope`/`can`/`throttle` middleware → 401 / 403 / 403 / 429. |
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

- `tags: [Flights]` from the `FlightController` class name (the explicit `#[Tag('Flights')]` here is redundant with the derived tag and dedupes away).
- `summary: Show a single flight.` from the first docblock paragraph.
- `description: Returns the full flight envelope including aircraft and crew.` from the remaining paragraphs.
- `operationId: flights.show` from the route name.
- A `flight` path parameter with type and format inferred from the `Flight` model binding.
- A 200 response with `$ref: '#/components/schemas/FlightData'`.
- A 404 response from `@throws ModelNotFoundException`.
- A 401 response from `auth:api`.
- A 403 response from `scope:flights:read`.
- A security requirement for the `flights:read` scope.

`#[Tag]` is optional if the controller-derived tag is acceptable; no other
attributes are required here.

## Path parameter types

A route-model-bound segment is typed from the key Laravel resolves it against,
so `/flights/{flight}` on `show(Flight $flight)` emits a typed parameter rather
than a bare `string`. The type and format are resolved in this order:

1. **An explicit route constraint wins.** `->whereNumber('flight')` →
   `type: integer`; `->whereUuid('flight')` → `string` + `format: uuid`;
   `->whereIn(...)` → an `enum`; any other `->where(...)` regex → `pattern`.
   These are the author's stated intent.
2. **Otherwise the bound model's key.** With no route constraint, the key type
   is read by reflection from the model: an integer key (`getKeyType()`) →
   `type: integer`; a `HasUuids` model → `string` + `format: uuid`; a `HasUlids`
   model → `string` (ULID has no standard OpenAPI format); any other string key →
   `string`.
3. **Otherwise a bare `string`** — the default for an unbound `{segment}` or a
   non-Eloquent `UrlRoutable`.

The model-key step applies only when the route binds via that model's primary
key. A custom-key binding (`/posts/{post:slug}`) or an overridden
`getRouteKeyName()` resolves against a different column whose type the model's
key metadata does not describe, so those stay `string` (and the bound field is
still named in the description — `Bound by slug of Post.`). A `#[PathParam]`
attribute is the escape hatch for anything reflection cannot reach.

A segment type-hinted as a backed enum (implicit enum binding — `show(Status
$status)`) references a shared enum component — `schema: {$ref:
'#/components/schemas/Status'}` — whose definition carries the enum's cases as
its allowed values, with the backing type following the enum: a string-backed
enum → `type: string` with the case strings, an int-backed enum → `type:
integer` with the case integers. No route constraint is needed; the cases are
the segment's complete valid set. The component is shared — every reference to
the same backed enum (cast, Data property, parameter, validation rule) points at
one definition rather than re-inlining it. See [Shared enum
components](#shared-enum-components).

## Shared enum components

A PHP `BackedEnum` is documented **once** as a reusable component under
`#/components/schemas/{EnumName}` and referenced with a `$ref` everywhere it
appears, rather than inlined per occurrence. The component carries `type`
(`string` or `integer`, from the enum's backing type), the `enum` case list, and
a Markdown description synthesised from per-case PHPDoc when present. The
component name is the enum's class basename (subject to the usual
disambiguation / `#[SchemaName]` rules).

Every Tier-0 site that resolves a backed enum participates: an Eloquent `$casts`
entry, a Spatie `Data` property, a typed path/query parameter, and a
`Rule::enum(Status::class)` validation rule. A nullable enum is wrapped as
`oneOf: [{$ref}, {type: 'null'}]` (OAS 3.1), since keywords alongside a `$ref`
are ignored.

The validation-rule form references the component only when it constrains the
field to the enum's **full** case set. A `->only(...)` / `->except(...)` subset,
or a unit (non-backed) enum, is not the canonical component, so it keeps an
inline value list instead.

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

1. **`$casts`** — the cast value drives the schema type. `datetime` / `date` → `string` (format `date-time` / `date`); `decimal:N` → `string`; `array` / `json` / `object` / `collection` → object; a backed-enum class-string → a `$ref` to the [shared enum component](#shared-enum-components).
2. **`@property` / `@property-read` docblock** — scalar types (`int`, `bool`, `float`, `string`) are mapped directly; `?T` marks the property as nullable. A class type that is itself a `Model` subclass becomes a `$ref` to that model's component schema (built recursively; cycles are guarded). An **array-shape** type — `array{lat: float, lng: float}`, a nested `array{meta: array{…}}`, an optional key (`array{unit?: string}`, omitted from `required`), the list forms `list<array{…}>` / `array{…}[]`, or a string-keyed map `array<string, T>` (→ `additionalProperties`) — is resolved into the corresponding object/array schema rather than dropped to a bare `array`.
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
    bio:          { type: [string, 'null'] }   # OAS 3.1 nullable idiom (the `nullable` keyword is gone)
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

## Resource action conventions

Resourceful controller actions have entirely predictable semantics, so the
generator derives their success status code and a default summary from the
action name and route verb alone — no body parsing.

An action method named `index`, `show`, `store`, `update`, or `destroy`, reached
by its conventional verb, maps to its idiomatic success code and summary:

| Action | Verb | Status | Summary |
|---|---|---|---|
| `index` | `GET` | `200` | `List {Plural}` |
| `show` | `GET` | `200` | `Show {Singular}` |
| `store` | `POST` | `201 Created` | `Create {Singular}` |
| `update` | `PUT`/`PATCH` | `200` | `Update {Singular}` |
| `destroy` | `DELETE` | `204 No Content` (body-less) | `Delete {Singular}` |

The resource noun is taken from the controller's short name (`PostController` →
`Post`), the same source as the derived tag, and pluralised for `index`. Detection is action-name-plus-verb, so it covers
hand-written resourceful routes — `Route::post('/flights', [FlightController::class,
'store'])` — not only `Route::apiResource(...)`.

The status **layers on top of** whatever body a response resolver produced: a
`store` returning a model keeps its schema, just at `201`. The verb gate means a
method named `store` reached by `GET` is left untouched.

This sits at the lowest precedence — an explicit `#[Response]` (2xx),
`#[Summary]` / `#[Operation]` attribute, or a DocComment summary always wins over
the convention.

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
