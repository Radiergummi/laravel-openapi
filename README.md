# laravel-openapi

[![Tests](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml)
[![Quality](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml)
[![Coverage](https://codecov.io/gh/Radiergummi/laravel-openapi/branch/main/graph/badge.svg)](https://codecov.io/gh/Radiergummi/laravel-openapi)

> Batteries-included OpenAPI 3.1 generation for Laravel.

Generate an OpenAPI 3.1 document from your existing Laravel routes: no handwritten YAML, no sprawling annotation blocks.
Schemas come from the types, PHPDoc, and conventions your code already uses. If something can't be inferred
automatically, you can fill the gap with a range of authoring attributes. An included linter reports documentation gaps.

From a typed controller action (plain Laravel, no DTOs or extra packages) `openapi:generate` produces the full operation
parameters, response, and a reusable component schema:

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
```

</td>
</tr>
</table>

Every part of that document traces back to something already in the code:

- the route `GET /flights/{flight}` and its `{flight}` path parameter come from the route definition and the `show()`
  signature;
- `#[Tag('Flights')]` sets the tag, and the first line of the method's PHPDoc becomes the summary;
- the `Flight` return type produces the `200` response and a reusable `Flight` schema, whose properties are read from
  the model's `@property` tags, so `departs_at` becomes a `date-time` string because of the `datetime` cast;
- `@throws ModelNotFoundException` produces the `404`.

## Features

- **OpenAPI 3.1 from your routes:** no handwritten YAML, no large annotation blocks.
- **Schemas from code you already write:** typed return values, request parameters, resource classes, PHPDoc, validation
  rules, model metadata, and more.
- **The rest from convention:** tags, path and query parameters, security from auth/scope middleware, error responses
  from `@throws`.
- **Authoring attributes** for the cases convention can't reach.
- **Included Linter** (`openapi:lint`) that reports documentation gaps and removes redundant annotations.
- **A served spec and interactive playground** (Scalar) out of the box.
- **Plugins** for Spatie Data, API Resources, Spatie Query Builder, and Fractal.

## Quick start

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

## What gets derived

Most of the spec falls out of your code with zero or minimal configuration:

- Summaries and descriptions from PHPDoc.
- Path parameters from action signatures and route constraints.
- Request bodies from typed `FormRequest` or Spatie Data parameters.
- Response schemas from return types.
- Security requirements from `auth:*` and `scope:*` middleware.
- Error responses from `@throws` and built-in middleware.
- Validation constraints from `rules()` and validation attributes.
- `operationId` from route names, or `{method}_{sanitized_path}`.
- Tags from controller namespaces (or `#[Tag]`).

Model schemas are read from `@property` tags and `$casts`: Those `@property` tags are what
[`laravel-ide-helper`](https://github.com/barryvdh/laravel-ide-helper) generates, so most typed apps already have them.
For anything convention can't derive, the authoring attributes fill the gap.

## Integrations

The richer your types, the richer the spec, and it reads the conventions your stack already uses. Type a controller
parameter as a [Spatie Data](https://github.com/spatie/laravel-data) class, and a single definition becomes both the
request body and the response schema, validation constraints included:

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

The `CreateBookingData` parameter becomes the request body (`passenger_name` carries `maxLength: 200`
and `seat` its `pattern`) while the `BookingData` return type becomes the `200` response schema. One class, both
directions.

Core handles `FormRequest` request bodies directly. Everything else ships as a plugin in `config/openapi.plugins`:

- **SpatieData** (default-enabled): request and response schemas from Spatie Data classes, including `DataCollection`
  and `PaginatedDataCollection`. No-ops without `spatie/laravel-data` installed.
- **ApiResources** (default-enabled): `JsonResource` / `ResourceCollection` responses declared with `#[ResourceField]`.
- **QueryBuilder** (disabled): `filter[…]` / `sort` / `include` parameters from `#[AllowedFilter]` / `#[AllowedSort]` /
  `#[AllowedInclude]`. Requires `spatie/laravel-query-builder`.
- **Fractal** (disabled): `league/fractal` transformer responses with `DataArray`, `ArraySerializer`, and `JsonApi`
  envelopes.

To add your own, implement the `Plugin` interface. See [Plugin authoring](docs/plugin-authoring.md).

Five runnable flavors of a flights/bookings API (vanilla validation, FormRequest, Spatie Data, QueryBuilder, combined)
live under [`examples/`](examples/README.md) alongside their generated
`openapi.yaml` snapshots.

## The linter

`openapi:lint` generates the spec and reports where it's incomplete: operations without a summary or description,
parameters without descriptions, responses with no declared errors, schemas without examples. Each finding carries a
severity score, from "broken" (a validator would reject the document) down to optional polish.

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

Run it in CI (`--format=github` annotates the pull request), limit it to routes changed since a git ref (`--diff`), or
restrict it to specific rules. `--fix` applies the mechanical fixes (removing redundant or no-op annotations, such as a
`#[Tag]` declared twice), while `--check` reports without writing, for CI.

```bash
php artisan openapi:lint --fix     # auto-fix some findings (currently just redundant annotations)
php artisan openapi:lint --check   # report only; exits non-zero if a fix is pending (like pint --test)
```

See [Linting](docs/linting.md) for the full rule catalog and severity levels.

## How this compares

If you've reached for an OpenAPI tool in Laravel before, here's where this one sits:

- **vs. L5-Swagger / handwritten `#[OA\]` attributes:** those make you *write* the spec as annotations, a second source
  of truth you maintain by hand; here it's *derived* from code you already write. (Importing and migrating off existing
  `#[OA\]`/swagger-php annotations is [on the roadmap](#roadmap).)
- **vs. Scribe:** Scribe is annotation- and config-driven and renders its own HTML; this leans on your existing types
  and PHPDoc, emits standard OpenAPI 3.1, and lets you bring your own renderer (Scalar ships wired up).
- **vs. Scramble:** both packages generate without annotations. Scramble does deeper code analysis: it reads method
  bodies (return statements, `validate()` calls, resource `toArray()`) and follows the flow, so it often pulls schemas
  out of code where this package would want a type hint or attribute. The trade-off is determinism: this package stays
  at types, PHPDoc, attributes, and model metadata (no method-body parsing yet, see [Roadmap](#roadmap)) and ships a
  linter that reports exactly where the spec is still thin; Scramble has no equivalent completeness check.

If you're looking for detailed comparisons with specific tools, see the [Field report](docs/field-report.md) on
performance against eleven real-world OSS apps.

## Caveats

A couple of constraints are deliberate and here to stay:

- **Static analysis only.** It inspects your code, route definitions, types, and metadata; it never executes your
  controller actions or calls an endpoint to observe a real response. A shape that exists only at runtime (e.g., a
  payload assembled conditionally, a dynamically keyed array, etc.) can't be read from the source, so describe it with
  an [authoring attribute](docs/attributes.md).
- **No bespoke documentation UI.** The output is a standard OpenAPI 3.1 document, rendered through the bundled Scalar
  playground or any OpenAPI tool you prefer. The package won't grow its own HTML documentation renderer.

Gaps that are simply not implemented *yet* - response schemas from untyped returns, inline `validate()` bodies - are
tracked on the [Roadmap](#roadmap), not here.

## Roadmap

The direction below is tracked on the [Roadmap project](https://github.com/Radiergummi/laravel-openapi/projects)
and bucketed into milestones; specifics may shift.

**Shipping in 1.0:** generation from types, PHPDoc, attributes, and model metadata; Spatie Data and API Resource
plugins; multiple specs per app; the linter with CI integration and mechanical auto-fix; the Scalar playground.

**Next (v1.1):** deeper response- and request-body inference; typed path parameters from route-model binding; query
parameters derived from convention; convention-derived error responses; backed-enum schema components; importing
existing `@OA`/swagger-php annotations.

**Later (v1.2):** Tier-1 reading of well-known method-body idioms (inline `validate()`,
`response()->json([...])`, `abort()`); Eloquent API Resource `toArray()` inference; broader auto-fix (corrections and
stubs) plus migrating off redundant `#[OA\]` annotations; OpenAPI 3.2.0 output; an interactive "Try it out" docs
playground.

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
