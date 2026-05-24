# laravel-openapi

[![Tests](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/tests.yml)
[![Quality](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml/badge.svg)](https://github.com/Radiergummi/laravel-openapi/actions/workflows/quality.yml)

Convention-driven **OpenAPI 3.1 generation** and **documentation linting** for
Laravel. The package derives a complete OpenAPI document from your application's
existing route definitions — typed request DTOs (Spatie Data classes or
`FormRequest`s), typed return values, PHPDoc summaries, and auth/scope
middleware — with **no hand-written YAML, no annotation blocks, no schema
files**. A companion linter (`openapi:lint`) walks the generated document and
reports documentation gaps and convention violations at a configurable
severity threshold.

## The pitch

| Aspect | Source |
|---|---|
| **Tag** | Last meaningful segment of the controller's namespace |
| **Summary** | First paragraph of the controller method's PHPDoc |
| **Description** | Remaining paragraphs (markdown permitted) |
| **operationId** | Route name (if named) or `{method}_{sanitized_path}` |
| **Path parameters** | Reflected from the action signature + Laravel's `Route::whereUuid()`/`whereNumber()` constraints |
| **Request body** | Spatie Data class or `FormRequest` on the action signature |
| **Response schema** | Typed return value (`Data`, `JsonResource`, `DataCollection<…>`, paginator) |
| **Security** | `auth:api` and `scope:*` middleware drive OAuth2 schemes |
| **Error responses** | `@throws ExceptionClass` PHPDoc → status codes; middleware → 401/403/429 |
| **Validation constraints** | `Data::rules()` and validation attributes → `maxLength`, `pattern`, `enum`, `format`, `minimum`, … |

If your controllers are shaped conventionally, **every endpoint documents itself
with zero attributes**. Authoring attributes exist as an escape hatch for cases
convention can't derive.

## Installation

```bash
composer require radiergummi/laravel-openapi
```

The service provider is auto-discovered. Requires **PHP 8.4+** and
**Laravel 12 or 13**.

## Quick start

```bash
php artisan vendor:publish --tag=openapi-config   # publish the config (optional)
php artisan openapi:generate                       # generate the YAML
php artisan openapi:lint                           # report doc gaps
```

By default the package registers two routes:

- `GET /api/openapi.yaml` — the raw OpenAPI 3.1 YAML
- `GET /api/docs` — an interactive Scalar playground (local only)

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
`GET /flights/{flight}` operation references a `FlightData` component schema
with `number`, `origin` (3 chars), `destination` (3 chars), and `departs_at`
(`string`, `date-time`) — derived entirely from types, validation attributes,
and the docblock summary. Throw a `ModelNotFoundException` (documented via
`@throws` or `#[ExceptionResponse]`) and you get a 404 response; add
`#[Security(['flights:read'])]` and the operation gains a security requirement;
type a `FormRequest` parameter instead of `FlightData` and Core derives the
request body the same way.

Five runnable flavors of the same flights/bookings API — vanilla validation,
FormRequests, Spatie Data, QueryBuilder, and a combined variant — live under
[`examples/`](examples/) with their generated `openapi.yaml` snapshots.

## How it compares

| | This package | l5-swagger / vyuldashev | Scramble |
|---|---|---|---|
| Source of truth | PHP types + PHPDoc + middleware | Hand-written YAML or annotation blocks | PHP types + method-body inference |
| Annotation noise | None for conventional code; attributes only where needed | Heavy — every operation needs `@OA\…` blocks | None |
| Speed / predictability | Fast; reads signatures only | Fast | Slower; parses method bodies |
| Refactor safety | High — schema follows types | Low — annotations drift from code silently | Medium — method-body changes can shift schema |
| Built-in linter | Yes — `openapi:lint` with 90+ rules | No | No |

Pick this package when your team values **convention over annotation** and
wants the generator to read the same signatures you already have. Pick Scramble
when you want method-body inference. Pick l5-swagger when you specifically need
hand-authored YAML.

## Plugins

Core is convention-agnostic. The package ships four plugins:

- **SpatieData** (default-enabled, auto-skips when the package is absent) —
  request and response schemas from Spatie Data classes, including
  `DataCollection` and `PaginatedDataCollection`. Install `spatie/laravel-data`
  to activate; the plugin entry stays in `config/openapi.plugins` either way
  and silently no-ops without the dependency.
- **ApiResources** (default-enabled) — `JsonResource` / `ResourceCollection`
  responses declared via `#[ResourceField]` attributes.
- **QueryBuilder** (shipped disabled) — `filter[…]` / `sort` / `include` query
  parameters from `#[AllowedFilter]` / `#[AllowedSort]` / `#[AllowedInclude]`.
  Install `spatie/laravel-query-builder` and uncomment in `config/openapi.php`
  to enable.
- **Fractal** (shipped disabled) — `league/fractal` transformer responses with
  `DataArray`, `ArraySerializer`, and `JsonApi` envelopes. Install
  `league/fractal` (or `spatie/laravel-fractal`, which depends on it) and
  uncomment in `config/openapi.php` to enable.

`FormRequest` request schemas are supported by Core directly — no plugin
required. You can write your own plugin against the `Plugin` interface; see
[Plugin authoring](docs/plugin-authoring.md).

## Documentation

Start with **[Getting started](docs/getting-started.md)** and
**[Auto-derivation](docs/auto-derivation.md)** — together they take ten
minutes. The full index is at [`docs/README.md`](docs/README.md).

- [Getting started](docs/getting-started.md) — install, first spec, view it
- [Auto-derivation](docs/auto-derivation.md) — what's derived from what
- [Request bodies](docs/request-bodies.md) — FormRequest vs Data + validation mapping
- [Attributes](docs/attributes.md) — the escape-hatch catalog
- [Recipes](docs/recipes.md) — 22 short snippets
- [Plugins](docs/plugins.md) — bundled plugins
- [Multi-spec](docs/multi-spec.md) — partition routes into multiple OpenAPI documents
- [Linting](docs/linting.md) — `openapi:lint` and the rule catalog
- [Configuration](docs/config.md) — every config key
- [Troubleshooting](docs/troubleshooting.md) — symptom-indexed
- [Plugin authoring](docs/plugin-authoring.md) — write your own
- [Architecture](docs/architecture.md) — internals
- [Known gaps](docs/known-gaps.md) — documented limitations

## License

MIT. See [`LICENSE`](LICENSE).
