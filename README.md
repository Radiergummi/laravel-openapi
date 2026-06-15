# laravel-openapi

[![Tests](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml)
[![Quality](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml)
[![Coverage](https://codecov.io/gh/Radiergummi/laravel-openapi/branch/main/graph/badge.svg)](https://codecov.io/gh/Radiergummi/laravel-openapi)

> Batteries-included OpenAPI 3.1 generation for Laravel.

Generate an OpenAPI 3.1 document from your existing Laravel routes — no handwritten YAML, no sprawling annotation
blocks. The spec is *derived* from the types, PHPDoc, attributes, and conventions your code already uses. Where
convention can't reach, a range of authoring attributes fills the gap, and a bundled linter reports what's still thin.

From a typed controller action (plain Laravel, no DTOs or extra packages) `openapi:generate` produces the full
operation — parameters, response, and a reusable component schema:

<table>
<tr>
<th>Application code</th>
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
    tags: [ Flights ]
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
      required: [ id, number, origin, destination, departs_at ]
      properties:
        id: { type: string }
        number: { type: string }
        origin: { type: string }
        destination: { type: string }
        departs_at: { type: string, format: date-time }
        created_at: { type: [ string, 'null' ], format: date-time }
        updated_at: { type: [ string, 'null' ], format: date-time }
```

</td>
</tr>
</table>

Every part of that document traces back to something already in the code: the route and its `{flight}` parameter, the
`#[Tag]` and PHPDoc summary, the `Flight` return type (whose schema is read from the model's `@property` tags and
`$casts`), and the `@throws` that produces the `404`.

## Features

- **OpenAPI 3.1 from your routes:** no handwritten YAML, no large annotation blocks.
- **Schemas from code you already write:** typed parameters and return values, PHPDoc, resource classes, validation
  rules, and model metadata (`$casts`, `$hidden`, `$appends`, migration columns).
- **Reads common method-body idioms:** bounded parsing of `$request->validate()`, `abort()`, `response()->json([…])`,
  resource `toArray()`, and similar, so untyped-but-conventional code still produces a schema.
- **The rest from convention:** tags, path/query parameters, security from auth/scope middleware, error responses
  from `@throws`.
- **Authoring attributes** for the cases convention can't reach.
- **A linter** (`openapi:lint`) that reports documentation gaps and removes redundant annotations, with CI integration.
- **A served spec and interactive playground** (Scalar) out of the box.
- **Plugins** for Spatie Data, API Resources, Spatie Query Builder, Fractal, and importing existing swagger-php
  annotations.

## Installation

Requires PHP 8.4+ and Laravel 12 or 13.

```bash
composer require radiergummi/laravel-openapi
```

The service provider is auto-discovered. Generate the document, then check it for gaps:

```bash
php artisan vendor:publish --tag=openapi-config   # optional
php artisan openapi:generate
php artisan openapi:lint
```

Two routes are registered by default:

- `GET /api/openapi.yaml` serves the OpenAPI 3.1 document.
- `GET /api/docs` serves an interactive Scalar playground (local environment only).

<!-- TODO(maintainer): drop a screenshot of the /api/docs Scalar playground here, the visual payoff sells this. -->

> [!NOTE]
> **Status:** pre-1.0, approaching the first stable release. The generated output is stable in shape; attribute and
> config names may still change before 1.0.

## How it works

Most of the spec falls out of your code with zero or minimal configuration — summaries from PHPDoc, path parameters
from action signatures and route constraints, request bodies from `FormRequest` or Spatie Data parameters, response
schemas from return types, security from `auth:*` / `scope:*` middleware, error responses from `@throws`, and tags and
`operationId`s from route metadata. Model schemas are read from `@property` tags and `$casts` — the same tags
[`laravel-ide-helper`](https://github.com/barryvdh/laravel-ide-helper) generates, so most typed apps already have them.

Where shapes live in the controller body rather than the signature, the generator parses a bounded whitelist of
well-known idioms — inline `validate()`, `abort()`, `response()->json([…])`, resource `toArray()` — to recover them
without running your code. Anything beyond that whitelist is the job of an authoring attribute.

See [Auto-derivation](docs/auto-derivation.md) for the full map of what comes from where, and
[Attributes](docs/attributes.md) for the escape-hatch catalog.

## Integrations

The richer your types, the richer the spec. Type a controller parameter as a
[Spatie Data](https://github.com/spatie/laravel-data) class, and a single definition becomes both the request body and
the response schema, validation constraints included:

```php
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

The `CreateBookingData` parameter becomes the request body (`passenger_name` carries `maxLength: 200`, `seat` its
`pattern`); the `BookingData` return type becomes the `200` response schema. One class, both directions.

Core handles vanilla Laravel — `FormRequest`, API Resources, validation rules — directly. Everything else ships as a
plugin in `config/openapi.plugins`: **SpatieData** and **ApiResources** are enabled by default; **QueryBuilder**,
**Fractal**, and **SwaggerPhp** (which harvests hand-authored `#[OA\…]` / `@OA` annotations into the spec) are present
but opt-in. To add your own, implement the `Plugin` interface.

See [Plugins](docs/plugins.md) and [Plugin authoring](docs/plugin-authoring.md) for details.

## The linter

`openapi:lint` generates the spec and reports where it's incomplete — operations without a summary, parameters without
descriptions, responses with no declared errors, schemas without examples. Each finding carries a severity, from
"broken" (a validator would reject the document) down to optional polish.

```text
$ php artisan openapi:lint --level=2

app/Http/Controllers/BookingController.php (2)
 │
 ├─ ⚠️ response.no-error
 │      Operation POST /bookings has no error response (4xx/5xx)
 │      at app/Http/Controllers/BookingController.php:28 (POST /bookings)
 │
 │      Suggested Fix: Add at least one error response (e.g. 400, 401, 404, 422, 500) to the operation.
 ╰─ ℹ️ operation.description-missing
        Operation POST /bookings has no description
        at app/Http/Controllers/BookingController.php:28 (POST /bookings)

 Summary: 1 warning, 1 notice (2 total across 1 route)
```

Run it in CI (`--format=github` annotates the pull request), scope it to routes changed since a git ref (`--diff`),
gate on coverage (`--min-coverage`), or apply mechanical fixes (`--fix`). See [Linting](docs/linting.md) for the full
rule catalog and [CI integration](docs/ci.md) for pipeline recipes.

## How this compares

If you've reached for an OpenAPI tool in Laravel before, here's where this one sits:

- **vs. L5-Swagger / handwritten `#[OA\]` attributes:** those make you *write* the spec as annotations, a second
  source of truth maintained by hand; here it's *derived* from code you already write. The SwaggerPhp plugin can even
  import your existing `#[OA\]` / `@OA` annotations and the linter flags the ones inference now covers, so you can
  migrate off them incrementally.
- **vs. Scribe:** Scribe is annotation- and config-driven and renders its own HTML; this leans on your existing types
  and PHPDoc, emits standard OpenAPI 3.1, and lets you bring your own renderer (Scalar ships wired up).
- **vs. Scramble:** both generate without annotations and both read controller bodies. The difference is depth versus
  determinism: Scramble follows full type-flow through your code, recovering shapes this package would want a type hint
  or attribute for. This package deliberately stops at a bounded whitelist of well-known idioms — predictable and cheap
  — and ships a linter that reports exactly where the spec is still thin, which Scramble has no equivalent for.

For a detailed look at how the generator fares against real codebases, see the
[Field report](docs/field-report.md) (eleven real-world OSS apps).

## Caveats

Two constraints are deliberate and here to stay:

- **It never runs your code.** Generation is pure static analysis — types, PHPDoc, attributes, model metadata, and a
  bounded read of common method-body idioms. A shape that only exists at runtime (a conditionally assembled payload, a
  dynamically keyed array) can't be read from source; describe it with an [authoring attribute](docs/attributes.md).
- **No bespoke documentation UI.** The output is a standard OpenAPI 3.1 document, rendered through the bundled Scalar
  playground or any OpenAPI tool you prefer. The package won't grow its own HTML renderer.

The direction beyond 1.0 — broader method-body idiom coverage, deeper response inference, OpenAPI 3.2 output — is
tracked on the [Roadmap project](https://github.com/Radiergummi/laravel-openapi/projects).

## Documentation

Full documentation lives in [`docs/`](docs/README.md). Start with [Getting started](docs/getting-started.md) and
[Auto-derivation](docs/auto-derivation.md); the rest (request bodies, attributes, recipes, plugins, multi-spec,
linting, configuration, troubleshooting, and architecture) is indexed there.

Eight runnable flavors of a flights/bookings API — vanilla, FormRequest, Spatie Data, API Resources, Fractal,
QueryBuilder, swagger-php, and a combined app — live under [`examples/`](examples/README.md) alongside their generated
snapshots.

## License

MIT. See [`LICENSE`](LICENSE).
