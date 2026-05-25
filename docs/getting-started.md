# Getting started

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Install

```bash
composer require radiergummi/laravel-openapi
```

The service provider is auto-discovered. To customise the configuration:

```bash
php artisan vendor:publish --tag=openapi-config
```

## Generate a spec

```bash
php artisan openapi:generate
```

The generator walks every registered route, derives one operation per route
from controller signatures and PHPDoc, and writes an OpenAPI 3.1 YAML file to
`storage/openapi.yaml`.

> [!NOTE]
> Missing routes? See
> [Troubleshooting → My endpoint doesn't appear in the generated spec](troubleshooting.md#my-endpoint-doesnt-appear-in-the-generated-spec).

## Commands and routes

| Command / Route | Purpose |
|---|---|
| `php artisan openapi:generate` | Regenerate the YAML. Pass a spec name to target one spec; `--output=path` overrides the destination (`-` for stdout); `--format=json` emits JSON. |
| `php artisan openapi:lint` | Report documentation gaps. |
| `php artisan openapi:clear` | Drop the cached spec. |
| `GET /api/openapi.yaml` | Serve the OpenAPI 3.1 YAML. |
| `GET /api/docs` | Interactive Scalar playground. Local environment only by default. |

Configure both routes in `config/openapi.routes`. See
[Configuration → Routes](config.md#routes).

## A worked example

```php
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\Validation\Size;

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

`openapi:generate` produces an operation for `GET /flights/{flight}` with:

- Summary `"Show a single flight."`, from the PHPDoc.
- A `FlightData` component schema with `number`, `origin` (3 chars),
  `destination` (3 chars), and `departs_at` (`string`, `date-time`).
- A 404 response if the method's `@throws` lists `ModelNotFoundException`.
- A security requirement if the route uses `auth:api` middleware.
- Error response bodies from the configured envelope preset (`config('openapi.error_envelope')`): `none` (default, no body), `laravel`, `rfc7807`, or `json-api`. See [Recipes › Choosing an error envelope](recipes.md#choosing-an-error-envelope).

## Examples

Five runnable apps live under [`examples/`](../examples/) (vanilla,
FormRequest, Spatie Data, QueryBuilder, combined), each with its generated
`openapi.yaml` snapshot.

```bash
composer examples           # regenerate every snapshot
composer examples:vanilla   # regenerate one
```

## Next

- [Auto-derivation](auto-derivation.md): where each part of an operation comes from.
- [Request bodies](request-bodies.md): `FormRequest` vs Spatie Data, validation mapping.
- [Attributes](attributes.md): escape-hatch catalog.
- [Recipes](recipes.md): short snippets for streaming, multipart, polymorphism, security schemes.
- [Linting](linting.md): `openapi:lint`, rule catalog, `#[IgnoreLint]`.
- [Troubleshooting](troubleshooting.md): symptom index.
