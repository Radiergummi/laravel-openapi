# Request bodies

The generator documents request bodies from two conventions:

- **`FormRequest`** — Laravel core, handled directly by Core. No plugin required.
- **Spatie `Data` class** — handled by the bundled
  [SpatieData plugin](plugins.md#spatiedata) (default-enabled).

Pick the one that fits the rest of your application. The generator treats them
symmetrically: validation rules become schema constraints either way, and the
lint surface is identical. The differences are in what each convention offers
*beyond* input validation.

## FormRequest vs Spatie Data

| Aspect | `FormRequest` (Core) | Spatie `Data` class (plugin) |
|---|---|---|
| Validation rules live in | `rules()` (Laravel core) | `rules()` and/or `#[Validation*]` attributes |
| Request-side support | Yes — always on | Yes — SpatieData plugin |
| Response-side support | No — return a typed resource (`JsonResource`) or use `#[ResponseResource]` | Yes — return a `Data`, `DataCollection<…>`, or paginated variant |
| Nested objects | No — flat key→rule map only | Yes — nested `Data` classes become nested component schemas via `$ref` |
| Enums | `Rule::in([...])` / `enum:` validation rule → `enum` | Native PHP enum property → `enum`; validation `Rule::in([...])` still works |
| Field-level enrichment | `#[RequestField]` on a `PARAM_*` class-constant whose value matches the field name | `#[RequestField]` on the promoted constructor parameter or property |
| Transformations / computed properties | No — runtime concern; the generator reads signatures only ([OAPI-017](known-gaps.md#oapi-017--no-method-body-inference)) | The `Data` class can carry `Optional`-typed and computed properties; PATCH semantics are inferred from `Optional\|…\|null` |
| File / multipart | Detected from `file` / `image` / `File::…` validation rules | Same, plus a typed `UploadedFile` property is auto-detected |
| Runtime dependency | None — ships with Laravel | `spatie/laravel-data` |
| Brownfield fit | Direct: existing `FormRequest`s document themselves with no changes | Requires migrating request DTOs (or introducing them) |
| Greenfield fit | Works, but loses nesting / response symmetry | Recommended — one DTO covers both directions |

### Rules of thumb

- **An existing Laravel codebase with `FormRequest`s in place:** leave them.
  They document themselves. Reach for `Data` classes only for new endpoints
  where the response shape is non-trivial.
- **A new project, or one already using `spatie/laravel-data`:** use `Data`
  classes for both input and output. The same class can be the request body
  and the response payload.
- **The two conventions coexist** — one endpoint may inject a `FormRequest`,
  another may inject a `Data` class. The generator picks the matching resolver
  per action.

### Side-by-side

```php
// FormRequest — request side only
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
// Spatie Data — request + response from one class
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
> The worked equivalents live in [`examples/form-requests/`](../examples/form-requests/)
> and [`examples/spatie-data/`](../examples/spatie-data/) — each ships its
> generated `openapi.yaml` next to the code so the difference is inspectable.

## Indirect request payloads

If your controllers inject an intermediate object (e.g. a Domain Action) instead
of a Data class directly, `PayloadParameterScanner` can descend into that
object's constructor to find Data-class parameters.

List the indirection base class in `config/openapi.request_payload_indirection`.
The default is empty — controllers that type-hint a Data class directly need no
configuration.

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

The generator descends into `CreateFlightAction::__construct`, finds the
`FlightData` parameter, and uses it as the request body.

## Validation rules → schema constraints

For Spatie Data classes, `SchemaFromDataClass` calls
`YourData::getValidationRules($payload)` — Spatie's own resolver — then maps the
compiled rules array into JSON Schema constraints. **FormRequest** support is
symmetric: `SchemaFromFormRequest` runs the same `ValidationRulesToSchema`
mapper against `rules()` output.

| Laravel rule | Schema field |
|---|---|
| `max:N` (string) | `maxLength: N` |
| `max:N` (numeric) | `maximum: N` |
| `max:N` (array) | `maxItems: N` |
| `min:N` | symmetric — `minLength` / `minimum` / `minItems` |
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
| `File::image()` / `ImageFile` | same as `File` + image dimensions in `description` when `.dimensions(…)` is chained |
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

`ValidationRulesToSchema` handles the built-in Laravel rule classes (`Password`,
`File`, `ImageFile`, `Dimensions`, `In`, `Enum`). Any other rule object —
including project-local `Rule` implementations — is silently ignored and
reported by the `rule.unknown` lint rule.

Use a [schema transformer](extensions.md#schema-transformer) to inject
constraints for these.
