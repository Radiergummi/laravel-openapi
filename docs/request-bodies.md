# Request bodies

Request bodies are derived from one of two conventions:

- **`FormRequest`**, supported natively.
- **Spatie `Data` class**, via the bundled
  [SpatieData plugin](plugins.md#spatiedata) (default-enabled).

Validation rules become schema constraints either way; the lint surface is
identical. The conventions differ in what each offers beyond input
validation.

A request body is only attached to a **write verb** (`POST`/`PUT`/`PATCH`). A
`FormRequest` type-hinted on a **GET/HEAD** action surfaces its `rules()` as
**query parameters** instead of a request body (GET request bodies are
discouraged by the OpenAPI spec) — see [Auto-derivation → Request parameters from
the method body](auto-derivation.md#request-parameters-from-the-method-body). A
`FormRequest` on a `DELETE` action is left unchanged (no body, no query params).

## FormRequest vs Spatie Data

| Aspect | `FormRequest` (Core) | Spatie `Data` class (plugin) |
|---|---|---|
| Validation rules live in | `rules()` (Laravel core) | `rules()` and/or `#[Validation*]` attributes |
| Request-side support | Yes, always on | Yes, via the SpatieData plugin |
| Response-side support | No. Return a typed resource (`JsonResource`) or use `#[ResponseResource]`. | Yes. Return a `Data`, `DataCollection<…>`, or paginated variant. |
| Nested objects | Yes, via dotted/wildcard rule keys (`address.city`, `items.*.name`) → inline nested object/array schemas | Yes. Nested `Data` classes become nested component schemas via `$ref`. |
| Maps / dictionaries | Inline rules describe lists, not string-keyed maps | A string-keyed property (`@var array<string, T>`) → `{type: object, additionalProperties: …}`; an int-keyed/`list<T>`/bare `array` stays a plain array |
| Enums | `Rule::in([...])` → inline `enum`; `Rule::enum(Status::class)` (full case set) → `$ref` to a [shared enum component](auto-derivation.md#shared-enum-components) | Native `BackedEnum` property → `$ref` to the shared enum component; `Rule::in([...])` still works |
| Field-level enrichment | `#[RequestField]` on a `PARAM_*` class-constant whose value matches the field name | `#[RequestField]` on the promoted constructor parameter or property |
| Transformations / computed properties | No. The generator reads signatures only — method bodies are not read. | The `Data` class can carry `Optional`-typed and computed properties. PATCH semantics are inferred from `Optional\|…\|null`. |
| File / multipart | Detected from `file` / `image` / `File::…` validation rules | Same, plus a typed `UploadedFile` property is auto-detected |
| Runtime dependency | None (ships with Laravel) | `spatie/laravel-data` |
| Brownfield fit | Direct. Existing `FormRequest`s document themselves with no changes. | Requires migrating request DTOs (or introducing them). |
| Greenfield fit | Works, but loses nesting / response symmetry | Recommended. One DTO covers both directions. |

### Which to use

- **Existing codebase with `FormRequest`s**: leave them. They document
  themselves. Use `Data` classes only on new endpoints with a non-trivial
  response shape.
- **New project, or one already using `spatie/laravel-data`**: use `Data`
  classes for both input and output. One class can serve as request body and
  response payload.
- **Mixed**: both can coexist; the generator resolves per action.

### Side-by-side

```php
// FormRequest: request side only
final class StoreFlightRequest extends FormRequest
{
    public const string PARAM_DEPARTURE = 'departure';

    #[OpenApi\RequestField(format: 'date-time', description: 'UTC departure time.')]
    public const string PARAM_ARRIVAL = 'arrival';

    public function rules(): array
    {
        return [
            self::PARAM_DEPARTURE => ['required', 'date'],
            self::PARAM_ARRIVAL   => ['required', 'date', 'after:' . self::PARAM_DEPARTURE],
        ];
    }
}

public function store(StoreFlightRequest $request): FlightResource { … }
```

```php
// Spatie Data: request + response from one class
final class FlightData extends Data
{
    public function __construct(
        public string $departure,

        #[OpenApi\RequestField(format: 'date-time', description: 'UTC departure time.')]
        public string $arrival,

        public FlightStatus $status,            // backed enum → shared component $ref
        public ?AircraftData $aircraft = null,  // nested Data → $ref
    ) {}

    public static function rules(): array
    {
        return [
            'departure' => ['required', 'date'],
            'arrival'   => ['required', 'date', 'after:departure'],
        ];
    }
}

public function store(FlightData $payload): FlightData { … } // both sides documented
```

> [!TIP]
> Worked equivalents live in [`examples/form-requests/`](../examples/form-requests/)
> and [`examples/spatie-data/`](../examples/spatie-data/). Each ships its
> generated `openapi.yaml` next to the code so the difference is inspectable.

## Indirect request payloads

When a controller injects an intermediate object (e.g., a Domain Action) rather
than a Data class directly, list the indirection's base class in
`config/openapi.request_payload_indirection`. The generator descends into its
constructor and uses the first Data-class parameter as the request body.

```php
// config/openapi.php
'request_payload_indirection' => [
    App\Actions\DomainAction::class,
],
```

```php
public function store(CreateFlightAction $action): FlightResource
{
    return new FlightResource($action->run());
}

final class CreateFlightAction extends DomainAction
{
    public function __construct(public readonly FlightData $payload) {}
    // …
}
```

`FlightData` is picked up as the request body for `store`.

## Inline validation in the controller

Controllers that validate a bare `Illuminate\Http\Request` inside the method
body — instead of type-hinting a `FormRequest` or Data class — still get a
request-body schema. The generator scans the **first 10 top-level statements**
of the action for exactly four call shapes:

```php
$request->validate([...]);
$this->validate($request, [...]); // also $this->validate(request(), [...])
Validator::make($request->all(), [...]);
Request::validate([...]); // the facade
```

The rules array goes through the same rule → schema mapping as a
`FormRequest`'s `rules()`, so constraints, `required`, nested dotted/wildcard
keys, and file-rule → `multipart/form-data` detection all apply. A trailing
`//` comment on a rule entry becomes that field's description:

```php
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'name'  => 'required|string|max:255', // The display name.
        'email' => ['required', 'email'],     // The contact address.
    ]);
    // …
}
```

Instead of an array literal, the rules argument may also reference rules
declared on the controller itself — a `$rules` property or a zero-argument
`rules()` method, optionally subscripted with a literal key:

```php
$data = $this->validate($request, $this->rules);
$data = $this->validate($request, $this->rules()['create']);
```

The property is read via reflection (its default value); the method is invoked
on an instance created without running the constructor.

Boundaries, by design (no dataflow analysis):

- Only **write methods** (POST/PUT/PATCH) produce a request body this way. On
  GET/HEAD routes the recovered keys become **query parameters** instead — see
  [Auto-derivation → Request parameters from the method
  body](auto-derivation.md#request-parameters-from-the-method-body). A DELETE
  route gets neither: the validated fields may live in either place, so the
  generator refuses to guess and notes the action in the generation log —
  `#[QueryParam]` / `#[RequestBody]` document them explicitly.
- Rules built dynamically — a local variable, `array_merge(...)`, a call with
  arguments — are never guessed at. The operation keeps its empty body and the
  generation log notes the action; document it with `#[RequestBody]` /
  `#[RequestField]` instead. A single dynamic *entry* inside an otherwise
  literal rules array only drops that entry (a `Rule::unique(...)` object next
  to literal rules keeps the literal rest). A class constant
  (`RuleSets::TITLE`) counts as literal: it resolves to its value, as long as
  that value is itself a scalar or array — enum cases are objects and stay
  dynamic.
- A `validate()` call that only runs conditionally — inside an `if` branch, a
  ternary or `match` arm, a `&&` / `||` / `??` short-circuit, or a closure
  body — is not picked up, nor is one past the first 10 statements.
- An explicit `#[RequestBody]` / `#[RequestField]` always wins over the scan.

## Documenting a body field-by-field with `#[RequestField]`

When an action validates outside a `FormRequest`/Data class and beyond the
reach of the [inline-validation scan](#inline-validation-in-the-controller) —
typically inside an Action/service — there is no type the generator can
introspect. Document the body
by stacking `#[RequestField]` on the action (repeatable); each one becomes a
request-body property, and `required: true` fields populate the schema's
`required` list. Pair it with `#[RequestBody]` for the description / media type.

```php
#[RequestBody(description: 'Create a site.')]
#[RequestField('domain', required: true, type: 'string', format: 'hostname')]
#[RequestField('php_version', type: 'string', default: '8.4')]
public function store(Request $request): SiteResource
{
    $site = app(CreateSite::class)->handle($request->validate([/* … */]));

    return new SiteResource($site);
}
```

On a method the first `$name` argument is required (it is derived from the target
for the property / `PARAM_*`-constant placements). An explicit `#[RequestField]`
declaration wins over a type-hinted `FormRequest` on the same action. The same
field attributes apply as everywhere else — `type`, `format`, `enum`, `minLength`,
`pattern`, and so on (see [Validation rules → schema constraints](#validation-rules--schema-constraints)).

For a field whose value is another schema-bearing class, pass its class-string as
`type` — it resolves to a `$ref` through the ref-resolver chain (a Spatie Data
class, an API Resource, anything a registered resolver builds). For a field that is
a **collection** of such a class, keep `type: 'array'` and pass the item
class-string as `items`; it resolves to `items: { $ref: … }`. Both mirror the
response-side [`#[ResourceField]`](plugins.md#declared-fields-with-resourcefield);
a class-string no resolver recognises degrades to a permissive `type: object`
(or `items: { type: object }`).

```php
#[RequestField('owner', type: UserData::class)]
#[RequestField('members', type: 'array', items: UserData::class)]
public function store(Request $request): SiteResource { … }
```

## Runtime state in `rules()`

`rules()` bodies that read request state — `$this->route('foo')->bar`,
`$this->user()->customer_id`, deep chains through Eloquent relations — work at
spec-time. The generator instantiates the FormRequest with a permissive route
and user context: `$this->route(...)` returns a stub for every binding name,
and `$this->user()` returns the same stub. Property accesses, method calls,
and array iteration on the stub all terminate without throwing, so the rules
array's *structure* (keys, types, required-ness) is preserved.

When invoking `rules()` *does* throw anyway (a runtime read the stub cannot
satisfy), the generator falls back to a static read of the method body's rule
literal — a bare `return [ … ];` or a `$rules = [ … ]; … return $rules;` — through
the same bounded AST scan used for inline `validate()`. The base literal entries
are recovered; a conditional `$rules['x'] = …` tweak is ignored, and a genuinely
dynamic `rules()` with no readable literal still degrades (now with a log note).

The stub values inside `Rule::in([...])`, `Rule::unique(...)->ignore(...)`, and
similar are opaque placeholders, so the constraint is dropped from the schema —
the stub stringifies to an empty value and the `enum` key is omitted entirely.
That's expected: the rule's *values* aren't part of the schema shape, only the
rule's *presence* (validation requires this field to be one of an enumeration)
is. To supply enum values for documentation, author `#[RequestField(enum: [...])]`
or `#[RequestField(example: ...)]` on the FormRequest's `PARAM_*` constant.

### Boundary: runtime-assembled enum sets

A literal `Rule::in(['a', 'b'])` enumerates fine. What the generator cannot
enumerate is a set **assembled at runtime** from a source that is empty or
unavailable when the app boots for generation:

```php
Rule::in(array_keys(config('core.server_providers'))) // registered lazily by a service provider
Rule::in($server->getSshUsers())                       // queried from a model / the database
```

The first reads a config key that another package's service provider populates
at boot — at generation time it is still empty, so the rule resolves to
`Rule::in([])` and no `enum` is emitted. The second derives its values from a
runtime model instance that does not exist at generation time. (Ordinary
*static* config files are different: `config(...)` is a real call at generation
time, so a key whose values live in a committed config file does resolve.)

This is an inherent inference boundary, not a gap to close. Hardcoding the
values into `#[RequestField(enum: [...])]` would just drift from the runtime
source — the exact smell the attributes exist to avoid. The honest expression
is a plain `string` with a description naming where the values come from:

```php
#[RequestField('provider', type: 'string', description: "One of the configured server providers (`config('core.server_providers')`).")]
```

There is deliberately no attribute that names a config key to read at
generation time: the constraining set is empty at rest, so such a lever would
enumerate nothing for exactly the cases that need it.

### Limitation: branching on runtime state

If `rules()` switches on runtime state — e.g., `if ($this->user()->isAdmin()) { … }`
returning different rule sets — the stub takes the truthy branch (PHP's bool
cast of any non-null object). The spec reflects the truthy branch's rules; the
falsy branch is not introspected.

When this is the wrong default:

- Refactor the FormRequest so `rules()` returns the union of all branches'
  keys (with `sometimes` where appropriate).
- Or split the FormRequest into two: one per route/role.
- Or suppress the lint rule on the FormRequest class:

  ```php
  #[OpenApi\IgnoreLint(
      'request-body.schema-degraded',
      reason: 'rules() branches on $this->user()->role; documented elsewhere',
  )]
  final class MyRequest extends FormRequest { … }
  ```

The same suppression applies when `rules()` does `instanceof` checks or calls
into services that are not bound at spec-time.

## Validation rules → schema constraints

Validation rules are compiled from either convention (Spatie's resolver for
Data classes, `rules()` for FormRequests) and mapped to JSON Schema
constraints:

| Laravel rule | Schema field |
|---|---|
| `max:N` (string) | `maxLength: N` |
| `max:N` (numeric) | `maximum: N` |
| `max:N` (array) | `maxItems: N` |
| `min:N` | symmetric: `minLength` / `minimum` / `minItems` |
| `between:a,b` | both `min`/`max` of the appropriate kind |
| `size:N` | both min and max set to `N` |
| `regex:/…/` | `pattern: …` (delimiter stripped) |
| `multiple_of:N` | `multipleOf: N` (integer arg stays int, decimal arg becomes float) |
| `mac_address` | `pattern: '^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$'` |
| `hex_color` | `pattern: '^#?[0-9a-fA-F]{6}$'` |
| `in:a,b,c` (or `Rule::in([...])`) | `enum: ['a', 'b', 'c']` |
| `email` / `url` / `active_url` / `uuid` / `ip` / `ipv4` / `ipv6` | `format: …` (`url` and `active_url` → `format: uri`) |
| `date` / `date_format:Y-m-d` | `format: date` |
| `date_format:H:i:s` (time-only tokens) | `format: time` |
| `date_format` with date+time tokens | `format: date-time` |
| `file` / `image` (string rule) | `type: string, format: binary` + switches body to multipart |
| `digits:N` | `pattern: '^\d{N}$'` |
| `nullable` | `nullable: true` |
| `Password::min(N)` | `type: string, format: password, minLength: N` |
| `Password::…->letters()->numbers()` | `description` listing active character-class requirements |
| `File::types([…])` | `type: string, format: binary`; `description` with allowed types and size bounds |
| `File::image()` / `ImageFile` | same as `File` plus image dimensions in `description` when `.dimensions(…)` is chained |
| `Dimensions` (standalone) | `type: string, format: binary`; `description` with all dimension constraints |

### PATCH semantics

Properties typed as `Optional|string|null` (Spatie's `Optional`) are stripped
from the schema's `required` list even if `rules()` says `required`. The
PHP-type pass is authoritative for "is this field required?".

### Dotted & wildcard rules → nested schemas

Dotted (`address.city`) and wildcard (`items.*`) validation keys become nested object/array
schemas to arbitrary depth. A plain segment is a nested object property; a `*` segment is an
array element:

```php
'tags.*'               => ['string', 'max:10'],
'address.city'         => ['required', 'string'],
'items.*.name'         => ['string'],
'items.*.address.city' => ['string'],
// yields:
// tags:    { type: array, items: { type: string, maxLength: 10 } }
// address: { type: object, properties: { city: { type: string } }, required: [city] }
// items:   { type: array, items: { type: object, properties: {
//             name: { type: string },
//             address: { type: object, properties: { city: { type: string } } }
//           } } }
```

A bare `*` key maps to the schema's `additionalProperties`. A wildcard key wins the type over a
conflicting scalar rule on the same key — `['a' => 'string', 'a.*.b' => 'integer']` makes `a` an
array of objects, since `a.*` means `a` is a list. For Spatie `Data` classes the PHP-type pass
remains authoritative — rule-derived nesting only fills gaps it leaves (e.g., the element type of a
scalar array), never overriding a typed nested `Data` `$ref`.

### Custom Rule objects

Built-in Laravel rule classes (`Password`, `File`, `ImageFile`, `Dimensions`,
`In`, `Enum`) are recognised. Any other rule object, including project-local
`Rule` implementations, is dropped and reported by the `rule.unknown` lint
rule. Inject constraints for these via a
[schema transformer](extensions.md#schema-transformer).

## File uploads → `multipart/form-data`

A body that carries a file is detected on both paths and switches the whole
request body to `multipart/form-data`; the file field becomes
`type: string, format: binary`.

- **FormRequest / inline validation** — a `file`, `image`, `mimes`,
  `mimetypes`, `File`, `ImageFile`, or `Dimensions` rule marks the field as a
  file (see the rule table above).
- **Spatie `Data` class** — a typed `UploadedFile` property is auto-detected,
  transitively through nested `Data` classes:

  ```php
  final class CreateAvatarData extends Data
  {
      public function __construct(
          public string $name,
          public UploadedFile $avatar,
      ) {}
  }
  ```

  produces a `multipart/form-data` body whose `avatar` field is
  `{type: string, format: binary}` while `name` stays a plain string.

Because this is automatic, you never declare multipart yourself. The
`multipart.file-without-multipart` lint rule is therefore a **contradiction
guard**: it fires only when a `#[RequestBody(mediaType: …)]` override forces a
non-multipart media type onto an operation whose payload still carries a file —
a spec that contradicts the code. Drop the override (or stop passing the file
through that body) to clear it.
