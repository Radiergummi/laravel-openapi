# laravel-openapi

[![Tests](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml)
[![Quality](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml)
[![Coverage](https://codecov.io/gh/Radiergummi/laravel-openapi/branch/main/graph/badge.svg)](https://codecov.io/gh/Radiergummi/laravel-openapi)

Generate an OpenAPI 3.1 document from your existing Laravel routes. Schemas are read from typed request DTOs (Spatie
Data or `FormRequest`), typed return values, PHPDoc summaries, and auth/scope middleware. A bundled linter
(`openapi:lint`) reports documentation gaps.

From a typed controller action — plain Laravel, no DTOs or extra packages — `openapi:generate`
derives the operation, its parameters, the response, and a reusable component schema, formats and all:

<table>
<tr>
<th>Your code</th>
<th>Generated OpenAPI</th>
</tr>
<tr>
<td>

```php
/**
 * @property string $id
 * @property string $number
 * @property string $origin
 * @property string $destination
 * @property Carbon $departs_at
 */
final class Flight extends Model
{
    protected $casts = ['departs_at' => 'datetime'];
}

#[Tag('Flights')]
final class FlightController
{
    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException
     */
    public function show(string $flight): Flight
    {
        return Flight::findOrFail($flight);
    }
}
```

</td>
<td>

```yaml
/flights/{flight}:
  get:
    tags: [Flights]
    summary: Show a single flight.
    operationId: get_flights_flight_
    parameters:
      - name: flight
        in: path
        required: true
        schema: { type: string }
    responses:
      '200':
        description: OK
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Flight'
      '404':
        $ref: '#/components/responses/NotFound'

components:
  schemas:
    Flight:
      type: object
      required: [id, number, origin, destination, departs_at]
      properties:
        id: { type: string }
        number: { type: string }
        origin: { type: string }
        destination: { type: string }
        departs_at: { type: string, format: date-time }
```

</td>
</tr>
</table>

No DTOs, no attributes beyond `#[Tag]` — the schema comes straight from the model's `@property` types
and `$casts`, the `404` from `@throws`. (Those `@property` tags are what
[`laravel-ide-helper`](https://github.com/barryvdh/laravel-ide-helper) generates; most typed Laravel apps
already have them.) Request bodies derive the same way from a typed `FormRequest` or `Data` parameter;
add `#[Security(['flights:read'])]` for auth.

## What gets derived

Most of the spec falls out of your code with zero or minimal configuration. For example:

- Tags from controller namespaces.
- Summaries and descriptions from PHPDocs.
- `operationId` from route names or `{method}_{sanitized_path}`.
- Path parameters from action signatures and route constraints.
- Request bodies from typed `FormRequest` or Spatie Data parameters.
- Response schemas from return types.
- Security requirements from `auth:*` and `scope:*` middleware.
- Error responses from `@throws` and builtin middleware.
- Validation constraints from `rules()` and validation attributes.

For anything it can't derive, the included authoring attributes fill the gap.

## What it won't infer

The generator reads structure that already exists in your code — signatures, types, PHPDoc,
attributes, model metadata. It does **not** analyze method bodies to guess shapes. The practical
consequences:

- **Type your returns and you get response schemas; return an untyped `array` or a hand-built
  `response()->json([...])` and you won't** — there's no shape to read. Type the return (or use a
  `Data` class / API Resource) to fill it in.
- Request bodies derive from a typed `FormRequest` or `Data` parameter, not from an inline
  `$request->validate([...])` call (that's [on the roadmap](#roadmap)).
- Anything genuinely runtime-only — a payload assembled conditionally, a dynamically-keyed array —
  is the job of an [authoring attribute](docs/attributes.md), not inference.

This is a deliberate boundary: everything derived is deterministic and cheap, and the linter (below)
tells you precisely where a gap remains, so nothing is silently wrong.

## Know what's still undocumented

Generating a spec is half the job; the other half is knowing where it's thin. `openapi:lint`
generates the document and reports the gaps — operations with no summary or description, parameters
with no description, success responses with no declared error, schemas with no example — graded by
severity (broken → degraded → underspecified → …):

```text
$ php artisan openapi:lint --level=2

app/Http/Controllers/BookingController.php (2)
 │
 ├─ ⚠️ response.no-error
        Operation POST /bookings has no error response (4xx/5xx)
        at app/Http/Controllers/BookingController.php:28 (POST /bookings)
 │
        Suggested Fix: Add at least one error response (e.g. 400, 401, 404, 422, 500) to the operation.
 ╰─ ℹ️ operation.description-missing
        Operation POST /bookings has no description
        at app/Http/Controllers/BookingController.php:28 (POST /bookings)

 Summary: 1 warning, 1 notice (2 total across 1 route)
```

It runs in CI (`--format=github` annotates the PR), scopes to changed routes (`--diff`), and **fixes
the mechanical findings for you**: `--fix` deletes redundant and no-op annotations from your source —
a `#[Tag]` declared twice, a field attribute that has no effect — then reports what's left to write by
hand. `--check` is the CI-safe dry run.

```bash
php artisan openapi:lint --fix     # apply mechanical fixes to your PHP source
php artisan openapi:lint --check   # report-only, exit 1 if anything is pending (like pint --test)
```

See [Linting](docs/linting.md) for the full rule catalog and severity levels.

## How this compares

If you've reached for an OpenAPI tool in Laravel before, here's where this one sits:

- **vs. L5-Swagger / hand-written `#[OA\]` attributes** — those make you *write* the spec as
  annotations, a second source of truth you maintain by hand; here it's *derived* from code you
  already write. (Importing and migrating off existing `#[OA\]`/swagger-php annotations is [on the
  roadmap](#roadmap).)
- **vs. Scribe** — Scribe is annotation- and config-driven and renders its own HTML; this leans on
  your existing types and PHPDoc, emits standard OpenAPI 3.1, and lets you bring your own renderer
  (Scalar ships wired up).
- **vs. Scramble** — closest in spirit: both generate without annotations. Scramble does deeper code
  analysis — it reads method bodies (return statements, `validate()` calls, resource `toArray()`) and
  follows the flow, so it often pulls schemas out of code where this package would want a type hint or
  attribute. The trade-off is determinism: this package stays at types, PHPDoc, attributes, and model
  metadata (no method-body parsing yet — see [what it won't infer](#what-it-wont-infer)) and ships a
  **linter** that flags exactly where the spec is still thin and auto-fixes the mechanical parts —
  Scramble has no equivalent completeness check.

## Install

Requires PHP 8.4+ and Laravel 12 or 13.

```bash
composer require radiergummi/laravel-openapi
```

The service provider is auto-discovered.

> **Status:** pre-1.0, approaching the first stable release. The generated output is stable in shape;
> attribute and config names may still change before 1.0.

## Quick start

```bash
php artisan vendor:publish --tag=openapi-config   # optional
php artisan openapi:generate
php artisan openapi:lint
```

Two routes are registered by default:

- `GET /api/openapi.yaml` serves the OpenAPI 3.1 YAML.
- `GET /api/docs` serves the Scalar playground (local environment only) — a browsable, try-it-out UI
  over the generated spec, with no extra setup.

<!-- TODO(maintainer): drop a screenshot of the /api/docs Scalar playground here — the visual payoff sells this. -->

## Integrations

The richer your types, the richer the spec, and it reads the conventions your stack already uses. Type
a controller parameter as a [Spatie Data](https://github.com/spatie/laravel-data) class and a single
definition becomes both the request body and the response schema, validation constraints and all:

```php
#[Tag('Flights')]
final class BookingController
{
    /** Book a seat on a flight. */
    public function store(CreateBookingData $booking): BookingData
    {
        return BookingData::from(Booking::create($booking->toArray()));
    }
}

final class CreateBookingData extends Data
{
    public function __construct(
        #[Max(200)] public string $passenger_name,
        #[Regex('/^\d{1,3}[A-Z]$/')] public string $seat,
    ) {}
}
```

The `CreateBookingData` parameter becomes the request body — `passenger_name` carries `maxLength: 200`
and `seat` its `pattern` — while the `BookingData` return type becomes the `200` response schema. One
class, both directions.

Core handles `FormRequest` request bodies directly. Everything else ships as a plugin in `config/openapi.plugins`:

- **SpatieData** (default-enabled): request and response schemas from Spatie Data classes, including `DataCollection`
  and `PaginatedDataCollection`. No-ops without `spatie/laravel-data` installed.
- **ApiResources** (default-enabled): `JsonResource` / `ResourceCollection` responses declared with `#[ResourceField]`.
- **QueryBuilder** (disabled): `filter[…]` / `sort` / `include` parameters from `#[AllowedFilter]` / `#[AllowedSort]` /
  `#[AllowedInclude]`. Requires `spatie/laravel-query-builder`.
- **Fractal** (disabled): `league/fractal` transformer responses with `DataArray`, `ArraySerializer`, and `JsonApi`
  envelopes.

To add your own, implement the `Plugin` interface. See [Plugin authoring](docs/plugin-authoring.md).

Five runnable flavors of a flights/bookings API (vanilla validation, FormRequest, Spatie Data,
QueryBuilder, combined) live under [`examples/`](examples/README.md) alongside their generated
`openapi.yaml` snapshots.

## Roadmap

The direction below is tracked on the [Roadmap project](https://github.com/Radiergummi/laravel-openapi/projects)
and bucketed into milestones; specifics may shift.

**Shipping in 1.0** — generation from types, PHPDoc, attributes, and model metadata; Spatie Data and
API Resource plugins; multiple specs per app; the linter with CI integration and mechanical auto-fix;
the Scalar playground.

**Next (v1.1)** — deeper response- and request-body inference; typed path parameters from
route-model binding; query parameters derived from convention; convention-derived error responses;
backed-enum schema components; importing existing `@OA`/swagger-php annotations.

**Later (v1.2)** — Tier-1 reading of well-known method-body idioms (inline `validate()`,
`response()->json([...])`, `abort()`); Eloquent API Resource `toArray()` inference; broader auto-fix
(corrections and stubs) plus migrating off redundant `#[OA\]` annotations; OpenAPI 3.2.0 output; an
interactive "Try it out" docs playground.

## Documentation

Index: [`docs/README.md`](docs/README.md).

- [Getting started](docs/getting-started.md): install, first spec.
- [Auto-derivation](docs/auto-derivation.md): what's derived from what.
- [Request bodies](docs/request-bodies.md): `FormRequest` vs `Data`, validation mapping.
- [Attributes](docs/attributes.md): escape-hatch catalog.
- [Recipes](docs/recipes.md): short snippets for specific cases.
- [Plugins](docs/plugins.md): bundled plugins.
- [Multi-spec](docs/multi-spec.md): multiple OpenAPI documents per app.
- [Linting](docs/linting.md): `openapi:lint`, rule catalog.
- [Configuration](docs/config.md): config keys.
- [Troubleshooting](docs/troubleshooting.md): symptom index.
- [Field report](docs/field-report.md): how it performed against eleven real-world OSS apps.
- [Plugin authoring](docs/plugin-authoring.md): write a plugin.
- [Architecture](docs/architecture.md): generation pipeline internals.

## License

MIT. See [`LICENSE`](LICENSE).
