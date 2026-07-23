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
| Path parameters | Action signature. Type hints, `Route::whereUuid()` / `whereNumber()` / `where(...)` constraints, and route-model-binding heuristics drive type and format. A custom-key binding (`/posts/{post:slug}`, including scoped-nested `{parent}/{child:field}`) emits the standard `{post}` template segment and notes the bound field in the description (`Bound by slug of Post.`). An action `@param $name <description>` supplies the parameter description as a lowest-precedence fallback. |
| Query parameters | Request-accessor reads in the method body — `$request->query('sort')` / `input('q')` → `string`, `string('name')` / `integer('page')` / `boolean('active')` → their named type (bounded scan; see [Request parameters from the method body](#request-parameters-from-the-method-body)). On GET/HEAD routes, inline `validate()` keys — and the `rules()` of a `FormRequest` type-hinted on the action — become query parameters (name, schema, `required` from the rules) instead of a request body. With the QueryBuilder plugin enabled, a literal `QueryBuilder::for(...)` chain's `allowedFilters` / `allowedSorts` / `allowedIncludes` become `filter[…]` / `sort` / `include` parameters (see [Plugins → QueryBuilder](plugins.md#querybuilder)). `#[QueryParam]` attributes win for their name; other names compose. An action `@param $name <description>` supplies the parameter description as a lowest-precedence fallback (attribute and inline-`validate()` comment descriptions win). |
| Header & cookie parameters | Request-accessor reads in the method body — `$request->cookie('session')` → a `cookie` parameter, `$request->header('X-Api-Key')` → a `header` parameter (both `string`, optional; bounded scan on every verb; see [Request parameters from the method body](#request-parameters-from-the-method-body)). Names are kept as literal tokens (never bracketed). `#[CookieParam]` / `#[Header]` attributes win for their name; other names compose. |
| Request body | Spatie Data class on the action (or on a configured payload-indirection object); `FormRequest` is supported natively. Schema is built from PHP types and validation rules. Without a typed payload parameter, inline `validate()` calls and a controller-declared `$rules` property / `rules()` method are read from the method body (bounded scan; see [Request bodies → Inline validation in the controller](request-bodies.md#inline-validation-in-the-controller)). |
| Response body | Spatie Data class or `DataCollection<…>` return type → component `$ref`. `JsonResource` subclass → component schema: fields are inferred from a single-`return [...]` `toArray()` literal (or a variable assigned one such literal once, unconditionally) (`$this->field` typed from the wrapped `@mixin`/`@extends` model, nested resources as `$ref`s, `when*`/`unless` wrappers optional) composed with declared `#[ResourceField]` attributes, which win per field; a passthrough or dynamic `toArray()` falls back to the wrapped model's schema (see [Plugins → ApiResources](plugins.md#apiresources)). A **base** resource return type (`JsonResource`, bare `ResourceCollection`, `AnonymousResourceCollection`) — or a **loose response wrapper** too generic to name a payload (`JsonResponse`, `Response`, and their Symfony parents) — resolves the concrete resource from the method's return expression — `X::collection(…)` / `X::make(…)` / `new X(…)`, `->toResource(…)`, a resource wrapped in `response()->json(<resource>, …)`, or a `@return …Collection<X>` generic, or multiple returns that all resolve to the same resource (bare `return;` / `return null;` guards ignored); a collection only claims the paginated envelope when its source visibly ends in a `paginate()`-family call, looking through paginator-preserving links like `withQueryString()` (bounded scan; see [Plugins → ApiResources → Resolving the resource from the return expression](plugins.md#resolving-the-resource-from-the-return-expression)). Eloquent `Model` subclass → component schema built from `$casts`, `@property`/`@property-read` annotations, typed `$appends` accessors, and `$hidden`/`$visible`; the same schema is recovered from a directly-returned `Model::find()`/`findOrFail()`/`firstOrFail()` call on an untyped action (bounded scan). See [Eloquent model response schemas](#eloquent-model-response-schemas). Without a schema-bearing return type, a literal `response()->json([...])` or `new JsonResponse([...], status)` construction in the method body is read instead (bounded scan; see [Inline JSON responses](#inline-json-responses)). A non-paginator return type whose body unconditionally calls `paginate()`/`simplePaginate()`/`cursorPaginate()` gets the matching paginated envelope when an item class is declared (`#[ResponseResource(Model::class)]` or a `@return Paginator<Item>` generic); this defers to API Resources / Spatie Data whenever the return type or a resource-naming `#[ResponseResource]` is one of theirs. With the Fractal plugin enabled, a transformer's schema is inferred from its single-`return [...]` `transform()` literal (or a variable assigned one such literal once, unconditionally) (`$model->field` typed from the typed parameter, casts by their JSON type) composed with `#[TransformerField]` attributes, which win per field; the `$entity_transformer` + `itemResponse()`/`listResponse()` base-controller convention binds it without an attribute (see [Plugins → Fractal](plugins.md#fractal)). |
| Response headers | A `201` response → a `Location` header (`string`, `uri-reference`), the URL of the created resource. `throttle` middleware (route-declared or controller-applied, see [Controller middleware](#controller-middleware)) → `X-RateLimit-Limit` and `X-RateLimit-Remaining` (`integer`) on the success response. An authored `#[ResponseHeader]` of the same name on the same status always wins. |
| Security | `auth:*` / `scope:*` / `scopes:*` (and Sanctum's `abilities:*` / `ability:*`) middleware — route-declared or controller-applied, including constructor `$this->middleware(...)` and the static `HasMiddleware` form (see [Controller middleware](#controller-middleware)) — → a per-operation `security` requirement against the derived scheme(s): Passport's OAuth2 flows, a `sanctum` http/bearer scheme when any route uses `auth:sanctum`, or `openapi.security_schemes`. Sanctum's all-of `abilities:a,b` lists both as scopes on one requirement; its any-of `ability:a,b` emits one OR-alternative requirement per ability. Map project-specific guard middleware to a declared scheme via `openapi.security_middleware_map`. When the route is authed but no scheme is derivable, `security` is omitted (not `[]`, which means *public*) and `operation.security-missing` flags it. |
| Error responses | `@throws ExceptionClass` → status codes; `abort(403)` / `abort_if(…, 404, 'msg')` / `abort_unless(…, 403, 'msg')` in the method body → that status, with a literal message as the response description (bounded scan of the first 10 statements; class-constant statuses such as `abort(Response::HTTP_FORBIDDEN, …)` resolve too, named `code:` / `message:` arguments resolve like positional ones, a genuinely non-literal status is skipped with a generation-log note); a route-model-bound parameter (`show(Post $post)`) or a `findOrFail()` / `firstOrFail()` lookup in the method body → 404 (deduped to one response); a `FormRequest` parameter → 422; a non-2xx `response()->json([...], <4xx/5xx>)` literal in the method body → that status, carrying the literal body as the response schema (inlined per operation, winning over the configured error envelope); a non-literal body degrades to a status-only response the envelope then fills, a non-literal status is skipped with a note, and a 3xx redirect literal is silently out of scope; `auth`/`scope`/`can`/`throttle` middleware (route-declared or controller-applied, see [Controller middleware](#controller-middleware)) → 401 / 403 / 403 / 429. An explicit `#[Response]` for the same status always wins. |
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
   `->whereIn(...)` → an `enum` (only on a string-typed parameter, since the
   alternatives are strings; an int-typed param keeps `type: integer` with no
   enum); any other `->where(...)` regex → `pattern`. These are the author's
   stated intent.
2. **Otherwise the bound model's key.** With no route constraint, the key type
   is read by reflection from the model: an integer key (`getKeyType()`) →
   `type: integer`; a `HasUuids` model → `string` + `format: uuid`; a `HasUlids`
   model → `string` (ULID has no standard OpenAPI format); any other string key →
   `string`.
3. **Otherwise a bare `string`** — the default for an unbound `{segment}` or a
   non-Eloquent `UrlRoutable`.

Every URI placeholder emits a path parameter (`in: path, required: true`), even
when it is not a typed action argument — invokable controllers, `Request`-only
actions, and the parent of a scoped/nested binding (`{team}` in
`/teams/{team}/members/{member}` where only `$member` is type-hinted). Such a
placeholder defaults to `type: string` and is still enriched from any `where*`
constraint on the route (uuid/integer/enum/pattern). Recovering the bound model's
key type, however, requires a type-hinted signature parameter: an unsignatured
bind stays a bare `string`.

The model-key step applies only when the route binds via that model's primary
key. A custom-key binding (`/posts/{post:slug}`) or an overridden
`getRouteKeyName()` resolves against a different column whose type the model's
key metadata does not describe, so those stay `string` (and the bound field is
still named in the description — `Bound by slug of Post.`). A `#[PathParam]`
attribute is the escape hatch for anything reflection cannot reach.

The action method's `@param $name <description>` PHPDoc text supplies the
**description** for the matching path or query parameter, as the lowest-precedence
fallback: a `#[PathParam]` / `#[QueryParam]` description and an inline-`validate()`
trailing comment still win, and the synthetic model-binding text (`Bound by slug of
Post.`) is used only when no `@param` is present. The `@param` *type* is never read —
the signature, route constraint, and binding already determine type and format.

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

## Typed return response schemas

Type your returns to get response schemas. When an action's return type (or its
`@return` PHPDoc) describes a concrete shape, the generator emits a `200
application/json` response for it at the language level, without any convention
package:

- a **plain DTO** — a class whose public and constructor-promoted properties are
  typed — becomes a component schema built from those properties (nested DTOs
  become their own pooled `$ref`, and self- or mutually-referential classes are
  cycle-safe);
- a documented **`@return array{id: int, name: string}`** shape becomes an object
  schema;
- a **scalar**, a **backed enum** (a reusable `$ref`), a **string-keyed map**
  (`@return array<string, int>` → `additionalProperties`), and a **typed
  collection** (`@return list<Dto>` → an array of the element schema) each map
  directly.

A property is `required` unless its type is nullable. Nullability and unions on
the return itself are honoured (`?Dto` wraps the schema in the OpenAPI 3.1
nullable idiom; `Foo|Bar` becomes `oneOf`).

Nothing is invented. A return that cannot be typed without guessing degrades to a
bare `200 OK` with no body: an untyped / `mixed` / `void` return, a service object
with no usable public properties, or a collection/resource wrapper whose element
type is undeclared. A class carrying an authored `#[OA\Schema]` is left to that
authored schema (surfaced by the SwaggerPhp plugin), never re-derived.

This baseline runs **after** every convention plugin, so Spatie Data, Eloquent
models, API Resources, Fractal transformers, and paginators keep the richer
schemas documented below; the typed-return baseline fires only when none of them
claims the action (including when the Core plugin is disabled).

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

The property set is the union of `$casts` keys, `$fillable`, `$appends`,
`@property`/`@property-read` names in the class docblock, and — when the model
uses timestamps — the `created_at`/`updated_at` columns (respecting `CREATED_AT`
/ `UPDATED_AT` renames; a `null` constant disables that column), filtered
through `$hidden` (excluded) and `$visible` (when non-empty, acts as an
allow-list).

For each property, the type is resolved in this order:

1. **`$casts`** — the cast value drives the schema type. `datetime` / `date` → `string` (format `date-time` / `date`); `decimal:N` → `string`; a backed-enum class-string → a `$ref` to the [shared enum component](#shared-enum-components). The JSON casts `array` / `json` / `collection` document as a list (`type: array`, with typed `items` when the element is a scalar) when the column's `@property` tag is list-shaped — `list<T>`, `non-empty-list<T>`, `array<T>`, `non-empty-array<T>`, `array<int, T>`, or `T[]`, one level deep — and as an object otherwise (map-shaped generics like `array<string, T>`, no tag); the `object` cast is always an object. The class-form casts of the modern `casts()` method are recognised equivalently — `AsCollection::class` / `AsEncryptedCollection::class` / `AsArrayObject::class` / `AsEncryptedArrayObject::class` behave like the JSON casts above (same `@property` disambiguation), and `AsStringable::class` → `string`. A custom `CastsAttributes` cast is unknowable here and defers to the `@property` tag below rather than forcing the column untyped.
2. **`@property` / `@property-read` docblock** — scalar types (`int`, `bool`, `float`, `string`) are mapped directly; `?T` marks the property as nullable. A class type that is itself a `Model` subclass becomes a `$ref` to that model's component schema (built recursively; cycles are guarded). An **array-shape** type — `array{lat: float, lng: float}`, a nested `array{meta: array{…}}`, an optional key (`array{unit?: string}`, omitted from `required`), the list forms `list<array{…}>` / `array{…}[]`, or a string-keyed map `array<string, T>` (→ `additionalProperties`) — is resolved into the corresponding object/array schema rather than dropped to a bare `array`. Collection generics (`Collection<string, T>`, and likewise `EloquentCollection` / `LazyCollection` / `Enumerable`) are treated identically to `array<…>`: a string key yields a map (`additionalProperties`), an int key (or a single type argument) yields a list. A `mixed` map value yields a permissive `additionalProperties: true`. The tag's **trailing text** (any prose after the property name) becomes the property `description`; it is skipped when empty or when a description is already present (so an authored attribute or a documented backed-enum case list wins). OpenAPI 3.1 permits a `description` sibling to `$ref`, so a relation property (`@property-read Author $author The post's author.`) keeps its prose.
3. **`$appends` accessor return type** — a legacy-style accessor (`getReadingTimeAttribute(): int`) contributes its return type for an appended attribute.
4. **Timestamp default** — a framework-managed timestamp column with no explicit cast or tag is typed `string` / format `date-time`, nullable (matching runtime: unsaved models and `NULL` columns carry no value).
5. **Migration columns** — the model's `database/migrations/*.php` are read statically (Tier-1 bounded AST over `Schema::create()` / `Schema::table()` `Blueprint` chains, keyed by `getTable()`) to enrich the property with signals the cast/tag did not supply: a column `format` (`uuid`/`foreignUuid` → `uuid`, `ipAddress` → `ip`, `date` → `date`, `dateTime`/`dateTimeTz`/`timestamp`/`timestampTz` → `date-time`; a `ulid` column stays a bare string, matching the `HasUlids` convention — there is no standard OpenAPI ULID format), `macAddress` → a `pattern`, `string($n)`/`char($n)` → `maxLength`, the `unsigned*`/`increments` families → `minimum: 0`, `decimal($p,$s)` → `type: number` + `multipleOf` from the scale, `json`/`jsonb` → object, `year` → integer, `enum`/`set` → `enum` members, `->nullable()` widens the type to include `null`, `->default(<literal>)` → `default`, and `->comment('…')` → `description`. Every field is filled **only when the cast / `@property` / attribute left it undefined**, so those richer sources always win. The reader degrades silently (a debug log) on a dynamic table or column name, a `->change()` alter chain (Tier-2), an off-whitelist macro, a non-literal `enum` member, a `DB::raw(...)` default, or an unparseable file, and contributes nothing when no migration declares the table. Disable it with `openapi.read_migration_columns`.
6. **`$attributes` default** — the model's static `$attributes` array (read via `getAttributes()` on a fresh instance — Tier-0 reflection, no body parsing) supplies a property `default`. It is the **lowest-precedence** default source: it fills `default` only when the cast / `@property` / attribute **and** a migration `->default()` all left it undefined, so a migration default always wins. An explicit `'column' => null` entry is honoured as `default: null` (distinct from an absent entry), and a `$ref` property (relation or enum cast) never takes a `default` sibling.
7. **Unknown** — if none of the above supply a type, an unconstrained schema with no `type` is emitted.

### `required`

A property appears in `required` only when it has a non-nullable
`@property` or `@property-read` annotation. Properties known only from
`$casts`, `$fillable`, or `$appends` are never required — they may be absent
at runtime and the generator has no way to prove otherwise.

### `readOnly`

Server-managed columns are marked `readOnly: true`: the primary key
(`getKeyName()`, so a custom `$primaryKey` is honoured, not a hard-coded `id`),
the timestamp columns (`created_at` / `updated_at`, respecting renames), and the
soft-delete column (`deleted_at`, when the model uses `SoftDeletes`). A client
never sets these, so the response schema marks them read-only. Nothing else gains
the keyword, and an authored `readOnly` (e.g. via `#[ResponseField]`) is never
overwritten. Request-side `writeOnly` remains attribute-driven
(`#[RequestField(writeOnly: true)]`).

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
    updated_at:   { type: [string, 'null'], format: date-time }  # timestamp default — no tag or cast needed
    category:     { $ref: '#/components/schemas/Category' }  # Model relation, built recursively
    reading_time: { type: integer }            # from typed legacy accessor
```

`password` is absent because it is in `$hidden`.

### Examples from model factories

When a model exposes a Laravel factory (the `HasFactory` trait), the generator
invokes the factory's `definition()` and uses its **scalar** values as the
per-property `example` in the model's schema. A factory definition is a
hand-curated, app-maintained realistic payload, so reusing it raises example
quality at no extra authoring cost. Only scalar (and `null`) values are taken;
nested arrays, relationship closures, and factory references are skipped.

Factory `definition()` typically calls `fake()`, so the values are reseeded
deterministically from `openapi.examples.faker_seed` (mixed with the model
class) before each read — reads are order-independent and stable across runs.
This path follows the same switches as the rest of example synthesis: it is
disabled when `openapi.examples.synthesise` is `false` or `faker_seed` is `null`
(see [Configuration](config.md)).

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

### From a returned `find()` / `findOrFail()` / `firstOrFail()`

An action that doesn't type its return still gets the model schema when it
**directly returns** a `Model::find()` / `findOrFail()` / `firstOrFail()` static
call (Tier-1 bounded scan of the first 10 statements):

```php
public function show(string $id)             // untyped return
{
    return Flight::findOrFail($id);          // → 200 with a Flight $ref
}
```

The class must be statically resolvable (`Flight::find()`, not `$class::find()`)
and the lookup must be the returned expression itself. A wrapped result
(`return new FlightResource(Flight::find($id))`,
`return response()->json(Flight::find($id))`), a lookup assigned to a variable
and returned indirectly, or one only inside an `if`/ternary is **not** read —
those degrade with a generation-log note (`#[Response]` /
`#[ResponseField]` are the escape hatch). A `findOrFail()`/`firstOrFail()`
return also contributes a `404` response, composed onto the same operation. A
typed `Model` return is handled by the reflection path above, not this scan.

### Known limitations

- **A `JsonResource` whose `toArray()` cannot be read** falls back to the wrapped
  model's schema (the passthrough base case); `$this->field` references inside a
  readable `toArray()` resolve against the model's metadata. See
  [Plugins → ApiResources](plugins.md#apiresources).

## Inline JSON responses

Controllers that build their response by hand — `return response()->json([...])`
with a return type of `JsonResponse` (or none at all) — still get a response
schema. The generator scans the **first 10 top-level statements** of the action
for a `response()->json(...)` call on the global helper and reads its literal
arguments. The object-construction form, `new JsonResponse([...], <status>)`
(imported or fully-qualified `\Illuminate\Http\JsonResponse`, including app
subclasses), is read with the **same rules** — its `data` / `status` positions
and named arguments line up exactly with the helper:

```php
public function show(): JsonResponse
{
    return response()->json([
        'status' => 'operational',   // → { type: string }
        'read_only' => false,        // → { type: boolean }
        'incidents' => 0,            // → { type: integer }
    ]);
}
```

Nested literal arrays recurse into nested object schemas, a literal list
becomes an array schema with its item type taken from the first element
(explicit sequential integer keys — `[0 => 'a', 1 => 'b']` — count as a list,
exactly as `json_encode` treats them), and a literal (or class-constant)
`status` argument — positional or named, `response()->json($data, 201)` /
`response()->json(data: [...], status: 201)` — becomes the response status.
A status you wrote in the call wins over the resource-action
[convention](#resource-action-conventions): a `store` returning
`response()->json([...], 200)` documents `200`, not the convention's `201`.
With no status argument the response documents as `200` and the convention
still applies, so a conventional `store` with no explicit status is promoted to
`201`. A literal `204` documents as `204 No Content` without a body schema — the
runtime strips the body — as does a bare `response()->noContent()`. A
`response()->noContent($status)` is read at its status argument
(`noContent(202)` / `noContent(status: 202)` documents `202`, still body-less);
a non-literal or non-2xx status degrades. A chained
`->setStatusCode(<literal>)` — `response()->json([...])->setStatusCode(201)`,
class constants such as `Response::HTTP_CREATED` included — overrides the status
and likewise wins over the convention. When several calls match, a **returned**
`json()` beats one only assigned to a variable; among returned calls, the first
wins.

Boundaries, by design (no dataflow analysis):

- The zero-argument `response()` **helper** and a `new JsonResponse(...)`
  construction are matched; the `Response` facade is not. A direct
  `new \Symfony\Component\HttpFoundation\JsonResponse(...)` (the framework base
  class, not Illuminate's) is also out of scope.
- A **dynamic value under a literal key** keeps its property with an
  unconstrained schema — the key is a fact worth documenting even when the
  value's type isn't statically known. A **dynamic key** (or a spread entry)
  degrades the whole call: the operation keeps its bare `200` and the
  generation log notes the action.
- A **non-literal first argument** — a `$data` variable, a model expression, a
  `compact()` call — is never guessed at; same degradation. Document the shape
  with `#[Response]` instead.
- A **non-literal status argument** also degrades the whole call: the body must
  not be documented under a guessed status.
- Only a **2xx literal status** may claim the success response. A straight-line
  non-2xx literal — the pervasive *guarded success + terminal error fallback*
  idiom, `return response()->json(['message' => 'Unauthorized'], 403)` after a
  conditional success — degrades with a log note instead of evicting the
  operation's success response. (Routing such literals into the error-response
  machinery, like `abort()` calls, is a tracked follow-up.)
- A chained **literal** `->setStatusCode(...)` is read as a status override (see
  above); a non-literal `->setStatusCode(...)` or a body-mutating
  `->setData(...)` degrades the call rather than documenting the body under the
  wrong status. Header and cookie chains (`->header(...)`, `->withHeaders(...)`,
  `->cookie(...)`) are harmless and stay matched.
- A `response()->json()` call that only runs **conditionally** — inside an `if`
  branch, a ternary or `match` arm, a short-circuit operand, or a closure
  body — is not treated as the canonical success response, nor is one past the
  first 10 statements.
- A **schema-bearing return type** (a Model, Data class, Resource, or
  paginator) always wins over the scan, and so does an explicit
  `#[Response]` attribute with a 2xx status. An action carrying a
  primary-response **authoring attribute** — `#[ResponseResource]`,
  `#[FractalResponse]` — is never scanned, even though the resolver consuming
  the attribute runs later: explicit authoring always wins.

## Request parameters from the method body

Filter, sort, and search parameters — and the cookies and headers an action
reads — are rarely declared in a typed request; they are pulled straight off the
request inside the action. The generator scans the **first 10 top-level
statements** of the method for the accessor shapes below and documents each read
as a parameter typed by the accessor. `query`/`input`/`string`/`integer`/`boolean`
become **query** parameters; `cookie` and `header` become **cookie** / **header**
parameters:

```php
public function index(Request $request): JsonResponse
{
    $sort = $request->query('sort');          // → sort: string  (query)
    $term = $request->input('q');             // → q: string     (query)
    $name = $request->string('name');         // → name: string  (query)
    $page = $request->integer('page');        // → page: integer (query)
    $active = $request->boolean('active');    // → active: boolean (query)
    $session = $request->cookie('session');   // → session: string   (cookie)
    $apiKey = $request->header('X-Api-Key');  // → X-Api-Key: string (header)
    // …
}
```

The receiver must be the method's `Illuminate\Http\Request`(-subclass)-typed
parameter or a zero-argument `request()` helper call — any other object with a
same-named method (an Eloquent builder's `query()`, say) never matches. The
parameter name is the first string-literal argument, positional or named
(`key:`); a dotted **query** key is documented in wire notation
(`input('filter.name')` → `filter[name]`), while a cookie/header name is kept as
its literal token (`header('X-Api-Key')` → `X-Api-Key`, never bracketed). A
literal default (`integer('per_page', 25)`) becomes the
schema `default` when its type matches the accessor's. Unlike the body and
response scans, a read inside an `if` branch or a `->when(…)` closure still
counts — a read claims nothing beyond "this parameter is consumed". A closure
or arrow function whose own parameter list re-declares the receiver name
(`->each(function (Request $request) { … })`) shadows it: reads inside that
subtree are on a different request and never match.

On **GET/HEAD** routes, inline `validate()` keys (see [Request bodies → Inline
validation in the controller](request-bodies.md#inline-validation-in-the-controller))
describe query parameters rather than a request body: each key becomes a
parameter with its rule-derived schema, `required` from the rules, and a
trailing `//` comment as its description. Nested keys map to the query-string
wire format — `filter.name` → `filter[name]`, a scalar list (`ids` + `ids.*`)
→ a repeatable `ids[]` with an array schema, emitted with `style: form` and
`explode: true` (PHP's `name[]` repeated-pair wire format). An array of *objects*
(`rows.*.price`) or a bare `*` rule has no honest parameter-name
representation; those keys are dropped with a generation-log note.

A `FormRequest` type-hinted on a **GET/HEAD** action is treated the same way: its
`rules()` describe query parameters (in the same wire notation) rather than a
request body, since a GET request body is discouraged by the OpenAPI spec. The
same `FormRequest` on a `POST`/`PUT`/`PATCH` action keeps its request body. When
`rules()` cannot be read at spec-time (it throws), the parameters degrade to none
with a generation-log note, consistent with the inline `validate()` path. A
`FormRequest` on a `DELETE` action is left unchanged (no body, no query params).

Boundaries, by design (no dataflow analysis):

- Only the seven accessors above are matched. `get()`, `has()`, `filled()`,
  `date()`, `enum()`, `float()` and friends are not — use `#[QueryParam]`,
  `#[CookieParam]`, or `#[Header]` where the idiom isn't covered.
- `query()` is matched on **every verb** — it can only read the query string.
  `input()` / `string()` / `integer()` / `boolean()` read the merged
  body-plus-query input, so they count as query parameters only on GET/HEAD
  routes; on body-carrying verbs they overwhelmingly mean body fields (which
  the inline-validation scan already documents). `cookie()` and `header()` are
  verb-independent, so they are matched on **every verb**.
- A `header()` read of a **reserved / protocol** name is not surfaced as a
  parameter — `Authorization`, the `Content-*` representation headers, `Accept*`
  negotiation, the conditionals (`If-*`), `Host`, `Cookie`, `User-Agent`, and the
  rest of the RFC 9110 control/transport set. These are protocol plumbing, not an
  API contract (`Authorization` is documented via the security requirement). The
  match is case-insensitive; **no `X-*` header is reserved**, so every app-custom
  header (`X-Api-Key`, `Stripe-Signature`, `X-Forwarded-For`) still surfaces. Only
  the inferred read is filtered: an explicit `#[Header('Authorization')]` is
  authoritative and always documented. The denylist is header-only — a `cookie()`
  of the same name is untouched.
- A non-literal parameter name (`$request->query($key)`, `$request->cookie($key)`)
  is never guessed at; the read is skipped and the generation log notes the
  action, naming the matching authoring attribute for that location
  (`#[QueryParam]` / `#[CookieParam]` / `#[Header]`). The query note obeys the
  same verb discipline as the read itself — a non-literal `integer($key)` on a
  POST route is a body read, not an undocumented query parameter — while cookie
  and header notes fire on every verb.
- Inline `validate()` on a **DELETE** route produces neither a request body
  nor query parameters — DELETE may legitimately carry either, and the
  generator refuses to guess. The generation log notes the action;
  `#[QueryParam]` / `#[RequestBody]` are the explicit hatches.
- When the same name is read twice, a typed accessor (`integer('page')`) beats
  an untyped one (`query('page')`). On a GET route, `validate()` rules beat an
  accessor read of the same name — they know `required` and the constraints.
- An explicit `#[QueryParam]` wins **entirely** for its name (no merging);
  parameters from different sources with different names compose. An inferred
  cookie/header read yields the same way to a `#[CookieParam]` / `#[Header]` of
  the same name. A query `x`, a cookie `x`, and a header `x` are distinct
  parameters and all coexist.

### Pagination parameters

The same bounded scan recognises a `paginate()`-family call in the first 10
top-level statements and documents the pagination knob it implies:

```php
public function index(): LengthAwarePaginator    // → page, per_page
{
    return Article::query()->paginate();
}

public function feed(): CursorPaginator          // → cursor
{
    return Article::query()->cursorPaginate();
}
```

- `paginate()` and `simplePaginate()` (offset pagination) emit `page` and
  `per_page` — both optional `integer`, `minimum: 1`. `per_page` is the common
  `?per_page=` idiom rather than a framework default; it is documented for the
  offset case.
- `cursorPaginate()` emits an optional `string` `cursor` parameter.
- The first unconditional `paginate()`-family call is matched, looking through
  chains like `->paginate()->withQueryString()`. A call behind an `if`/ternary
  is not treated as the operation's shape.
- These compose with the accessor/validation parameters above, and an explicit
  `#[QueryParam('page')]` wins entirely for its name — annotate it when the page
  knob needs a description or different constraints.

## Controller middleware

Everywhere the generator reads a route's middleware — security requirements,
the implicit 401/403/429 responses, multi-spec `match.middleware`, and
`openapi:why` — it sees **controller-applied middleware too**, not just what
the route declares. Both controller idioms count:

```php
// Laravel 11+ static form — resolved without instantiating the controller.
class ReportController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('auth:sanctum', only: ['index'])];
    }
}

// Classic constructor form, common in long-lived apps.
class ExportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('verified')->only(['index']);
        $this->middleware('throttle:exports', ['except' => ['index']]);
    }
}
```

For instantiable controllers Laravel resolves both forms itself, `only` /
`except` included. The interesting case is a controller the container *cannot*
build at generation time — an unbound constructor dependency, a constructor
that throws outside a real request. Previously that crashed the run; now the
generator logs a notice and falls back to a **bounded static scan** of the
constructor (the first 10 top-level statements): literal
`$this->middleware(...)` names with literal `->only(...)` / `->except(...)` or
options-array scoping are merged into the route's middleware list,
deduplicated, and matched against the action method. Inherited constructors
work — the scan reads the declaring (base) class.

Boundaries, by design (no dataflow analysis):

- Only literal strings (and class constants) are read. A dynamic name
  (`$this->middleware($this->guard())`) or non-literal scoping is skipped with
  a generation-log note.
- A registration inside an `if` is **not** documented — conditional middleware
  presented as unconditional would overstate the contract. The generation log
  notes it; annotate the affected actions with `#[Security]` to document the
  requirement explicitly.
- `#[Security]` and `#[PublicEndpoint]` always win over middleware-derived
  security, exactly as for route-declared middleware. `#[PublicEndpoint]` clears
  `security` *and* suppresses the responses that mirror it — the auth-derived 401
  and the scope-derived 403 — so a declared-public operation never documents an
  Unauthenticated response. The `can`-derived 403 and the `throttle`-derived 429
  are independent of authentication and stay.
- `throttle` middleware also adds `X-RateLimit-Limit` and `X-RateLimit-Remaining`
  (`integer`) to the operation's **success response** — where Laravel emits them
  on a passing request — not to the 429, which carries `Retry-After` instead. An
  authored `#[ResponseHeader]` of the same name wins.

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
the convention. The convention's **status** likewise defers to a status read
from an inline [`response()->json([...], <status>)`](#inline-json-responses)
call — a status the author actually wrote is honoured over the conventional one.

The `destroy` convention's `204 No Content` additionally defers to a
**content-bearing** body: a `destroy` returning
`response()->json(['message' => '...'])` (a body with no explicit status) keeps
its `200` and the body's schema rather than being relabelled to a bodyless `204`.
The conventional `204` applies only when the action is genuinely body-less
(`noContent()`, a bare `new JsonResponse()`, or an empty `response()->json([])`).

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
