# laravel-openapi

[![Tests](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml)
[![Quality](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml)

Convention-driven OpenAPI 3.1 generation and documentation linting for Laravel. The package
derives a complete OpenAPI document from your application's existing route definitions — typed
request DTOs (Spatie Data classes or `FormRequest`s), typed return values, PHPDoc summaries, and
auth/scope middleware — with no hand-written YAML. A companion linter (`openapi:lint`) walks the
generated document and reports documentation gaps and convention violations at a configurable
severity threshold.

## Installation

```bash
composer require radiergummi/laravel-openapi
```

The service provider is auto-discovered. Requires **PHP 8.4+** and **Laravel 12 or 13**.

## Quick start

Publish the config file:

```bash
php artisan vendor:publish --tag=openapi-config
```

Generate the OpenAPI document:

```bash
php artisan openapi:generate
```

Lint your API documentation:

```bash
php artisan openapi:lint
```

`openapi:clear` removes the generated document. `openapi:generate` accepts an output path
argument (pass `-` to print to stdout) and a `--format=json` option.

## Example

A typed Spatie Data class plus a typed return value is all the generator needs:

```php
final class FlightData extends Data
{
    public function __construct(
        public string $number,
        #[Size(3)] public string $origin,
        #[Size(3)] public string $destination,
        public DateTimeInterface $departs_at,
    ) {}
}

#[Tag('Flights')]
final class FlightController
{
    /** Show a single flight. */
    public function show(string $flight): FlightData
    {
        return FlightData::from(Flight::findOrFail($flight));
    }
}
```

Running `php artisan openapi:generate` produces an OpenAPI 3.1 document whose
`GET /flights/{flight}` operation references a `FlightData` component schema with `number`,
`origin` (3 chars), `destination` (3 chars), and `departs_at` (`string`, `date-time`) — derived
entirely from types, validation attributes, and the docblock summary. Throw a
`ModelNotFoundException` (documented via `@throws` or `#[Throws]`) and you get a 404 response;
add `#[Security(['flights:read'])]` and the operation gains a security requirement; type a
`FormRequest` parameter instead of `FlightData` and Core derives the request body the same way.

Five runnable flavors of the same flights/bookings API — vanilla validation, FormRequests,
Spatie Data, QueryBuilder, and a combined variant — live under [`examples/`](examples/) with
their generated `openapi.yaml` snapshots.

## Routes

The package can register two routes, controlled by `config/openapi.routes`:

- the **spec endpoint** (serves the raw OpenAPI YAML) — `spec.enabled`, defaults to **on**;
- the **Scalar playground** (an interactive API reference) — `playground.enabled`, defaults to
  **on only when `APP_ENV` is `local`**.

The route prefix, middleware, and URIs are all configurable. Set `routes.enabled` to `false` to
register no routes at all.

## Plugins

Core is convention-agnostic. The package ships four plugins:

- **SpatieData** (default-enabled) — request and response schemas from Spatie Data classes,
  including `DataCollection` and `PaginatedDataCollection`.
- **ApiResources** (default-enabled) — `JsonResource` / `ResourceCollection` responses declared
  via `#[ResourceField]` attributes.
- **QueryBuilder** (shipped disabled) — `filter[…]` / `sort` / `include` query parameters from
  `#[AllowedFilter]` / `#[AllowedSort]` / `#[AllowedInclude]`. Install `spatie/laravel-query-builder`
  and uncomment in `config/openapi.php` to enable.
- **Fractal** (shipped disabled) — `league/fractal` transformer responses with `DataArray`,
  `ArraySerializer`, and `JsonApi` envelopes. Install `league/fractal` (or `spatie/laravel-fractal`,
  which depends on it) and uncomment in `config/openapi.php` to enable.

`FormRequest` request schemas are supported by Core directly — no plugin required. You can write
your own plugin against the `Plugin` interface to support other conventions; see the
plugin-authoring guide in [`docs/usage.md`](docs/usage.md#adding-a-new-plugin).

## Documentation

- [`docs/usage.md`](docs/usage.md) — full usage guide: what's auto-derived, the attribute
  catalogue, validation-rule → schema mapping, the lint system, the config reference, and the
  plugin-authoring guide.
- [`docs/known-gaps.md`](docs/known-gaps.md) — documented limitations.

## License

MIT. See [`LICENSE`](LICENSE).
