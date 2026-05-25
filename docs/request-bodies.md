# Request bodies

Request bodies are derived from one of two conventions:

- **`FormRequest`**, supported natively.
- **Spatie `Data` class**, via the bundled
  [SpatieData plugin](plugins.md#spatiedata) (default-enabled).

Validation rules become schema constraints either way; the lint surface is
identical. The conventions differ in what each offers beyond input
validation.

## FormRequest vs Spatie Data

| Aspect | `FormRequest` (Core) | Spatie `Data` class (plugin) |
|---|---|---|
| Validation rules live in | `rules()` (Laravel core) | `rules()` and/or `#[Validation*]` attributes |
| Request-side support | Yes, always on | Yes, via the SpatieData plugin |
| Response-side support | No. Return a typed resource (`JsonResource`) or use `#[ResponseResource]`. | Yes. Return a `Data`, `DataCollection<…>`, or paginated variant. |
| Nested objects | No, flat key→rule map only | Yes. Nested `Data` classes become nested component schemas via `$ref`. |
| Enums | `Rule::in([...])` / `enum:` validation rule → `enum` | Native PHP enum property → `enum`; validation `Rule::in([...])` still works |
| Field-level enrichment | `#[RequestField]` on a `PARAM_*` class-constant whose value matches the field name | `#[RequestField]` on the promoted constructor parameter or property |
| Transformations / computed properties | No. The generator reads signatures only (see [OAPI-017](internal/known-gaps.md#oapi-017--no-method-body-inference)). | The `Data` class can carry `Optional`-typed and computed properties. PATCH semantics are inferred from `Optional\|…\|null`. |
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

        public FlightStatus $status,            // PHP enum → schema enum
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

When a controller injects an intermediate object (e.g. a Domain Action) rather
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
| `in:a,b,c` (or `Rule::in([...])`) | `enum: ['a', 'b', 'c']` |
| `email` / `url` / `uuid` / `ip` / `ipv4` / `ipv6` | `format: …` |
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

### Dotted-key rules (one level)

`foo.*` rules are applied to the parent field's `items` schema:

```php
'tags.*' => ['string', 'max:10']
// yields:
// tags: { type: array, items: { type: string, maxLength: 10 } }
```

Deeper paths (`foo.*.bar`) are not yet supported and are silently dropped.

### Custom Rule objects

Built-in Laravel rule classes (`Password`, `File`, `ImageFile`, `Dimensions`,
`In`, `Enum`) are recognised. Any other rule object, including project-local
`Rule` implementations, is dropped and reported by the `rule.unknown` lint
rule. Inject constraints for these via a
[schema transformer](extensions.md#schema-transformer).
