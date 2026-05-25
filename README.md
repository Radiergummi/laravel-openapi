# laravel-openapi

[![Tests](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml)
[![Quality](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml)

Generate an OpenAPI 3.1 document from your existing Laravel routes. Schemas are read from typed request DTOs (Spatie
Data or `FormRequest`), typed return values, PHPDoc summaries, and auth/scope middleware. A bundled linter
(`openapi:lint`) reports documentation gaps.

## What gets derived

The package is able to derive a lot of the OpenAPI spec from your code, with zero or minimal configuration. For example:

- Tags from controller namespaces.
- Summaries and descriptions from PHPDocs.
- `operationId` from route names or `{method}_{sanitized_path}`.
- Path parameters from action signatures and route constraints.
- Request bodies from typed `FormRequest` or Spatie Data parameters.
- Response schemas from return types.
- Security requirements from `auth:*` and `scope:*` middleware.
- Error responses from `@throws` and builtin middleware.
- Validation constraints from `rules()` and validation attributes.

For anything it doesn't catch automatically, you can use the included authoring attributes.

## Install

Requires PHP 8.4+ and Laravel 12 or 13.

```bash
composer require radiergummi/laravel-openapi
```

The service provider is auto-discovered.

## Quick start

```bash
php artisan vendor:publish --tag=openapi-config   # optional
php artisan openapi:generate
php artisan openapi:lint
```

Two routes are registered by default:

- `GET /api/openapi.yaml` serves the OpenAPI 3.1 YAML.
- `GET /api/docs` serves the Scalar playground (local environment only).

## Example

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
    /**
     * Show a single flight. 
     */
    public function show(string $flight): FlightData
    {
        return FlightData::from(Flight::findOrFail($flight));
    }
}
```

`openapi:generate` emits a `GET /flights/{flight}` operation referencing a `FlightData` component schema with `number`,
`origin` (3 chars), `destination` (3 chars), and `departs_at` (`string`, `date-time`). Add
`@throws ModelNotFoundException` for a 404; add `#[Security(['flights:read'])]` for a security requirement. Type the
parameter as a `FormRequest` instead and the request body is derived the same way.

Five runnable flavors of the same flights/bookings API (vanilla validation, FormRequest, Spatie Data, QueryBuilder,
combined) live under [`examples/`](examples/README.md) alongside their generated `openapi.yaml` snapshots.

## Plugins

Core handles `FormRequest` request bodies directly. Everything else ships as a plugin in `config/openapi.plugins`:

- **SpatieData** (default-enabled): request and response schemas from Spatie Data classes, including `DataCollection`
  and `PaginatedDataCollection`. No-ops without `spatie/laravel-data` installed.
- **ApiResources** (default-enabled): `JsonResource` / `ResourceCollection` responses declared with `#[ResourceField]`.
- **QueryBuilder** (disabled): `filter[…]` / `sort` / `include` parameters from `#[AllowedFilter]` / `#[AllowedSort]` /
  `#[AllowedInclude]`. Requires `spatie/laravel-query-builder`.
- **Fractal** (disabled): `league/fractal` transformer responses with `DataArray`, `ArraySerializer`, and `JsonApi`
  envelopes.

To add your own, implement the `Plugin` interface. See [Plugin authoring](docs/plugin-authoring.md).

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
- [Plugin authoring](docs/plugin-authoring.md): write a plugin.
- [Architecture](docs/architecture.md): generation pipeline internals.
- [Known gaps](docs/internal/known-gaps.md): limitations.

## License

MIT. See [`LICENSE`](LICENSE).
