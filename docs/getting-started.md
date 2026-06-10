# Getting started

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- If you use [Spatie Laravel Data](plugins.md#spatiedata): `spatie/laravel-data` `^4.0`.

## Install

```bash
composer require radiergummi/laravel-openapi
```

### Compatibility notes

- **`zircote/swagger-php ^5.8 || ^6.1.2`** — both the 5.x and 6.x lines are
  supported; a dedicated CI job runs the suite against swagger-php 5.8, so an
  app that embeds swagger-php itself installs fine on either major.
- **`symfony/type-info ^7.3 || ^8.0`** — works on Laravel 12 (Symfony 7) and
  Laravel 13 without dragging in a newer Symfony major than your app already
  uses.

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
| `php artisan openapi:generate` | Regenerate the YAML. Pass a spec name to target one spec; `--output=path` overrides the destination (`-` for stdout); `--format=json` emits JSON; `--no-validate` skips the swagger-php validation pass (faster). |
| `php artisan openapi:lint` | Report documentation gaps. |
| `php artisan openapi:why <route>` | Explain why a route is included in (or excluded from) each defined spec. Accepts a route name or URI substring; `--for-env=` overrides the environment for `#[Hide]`/`#[Expose]` evaluation. The first stop when a route doesn't appear. |
| `php artisan openapi:clear` | Drop the cached spec. |
| `php artisan openapi:diff:config` | Show drift between your published `config/openapi.php` and the package default — flags added keys (`+`), removed keys (`-`), and changed default values (`~`). |
| `GET /api/openapi.yaml` | Serve the OpenAPI 3.1 YAML. |
| `GET /api/docs` | Interactive Scalar playground. Local environment only by default. |

Configure both routes in `config/openapi.routes`. See
[Configuration → Routes](config.md#routes).

## Common first-run friction

The library's own spec (`/api/openapi.yaml`) and playground (`/api/docs`)
routes are **excluded from your generated spec by default** — a stock
`openapi:generate` won't document the endpoints serving the document itself.
To include them, remove `SkipSelfRoutes::class` from `config('openapi.filters')`.

## A worked example

Plain Laravel — an Eloquent model and a typed controller return, no DTOs or
extra packages:

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

`openapi:generate` produces an operation for `GET /flights/{flight}` with:

- Summary `"Show a single flight."`, from the PHPDoc.
- A `Flight` component schema with `id`, `number`, `origin`, `destination`, and
  `departs_at` (`string`, `date-time` — from the `datetime` cast), derived from
  the model's `@property` tags and `$casts`.
- A 404 response, from the method's `@throws ModelNotFoundException`.
- A `Flights` tag, derived from the controller name.
- A security requirement if the route uses `auth:api` middleware.
- Error response bodies from the configured envelope preset (`config('openapi.error_envelope')`): `none` (default, no body), `laravel`, `rfc7807`, or `json-api`. See [Recipes › Choosing an error envelope](recipes.md#choosing-an-error-envelope).

For a typed request body, swap the model for a `FormRequest` or a Spatie Data
class — see [Request bodies](request-bodies.md).

## Examples

Eight runnable apps live under [`examples/`](../examples/) (vanilla,
FormRequest, Spatie Data, API Resources, Fractal, QueryBuilder, swagger-php,
and a combined app), each with its generated `openapi.yaml` snapshot. See
[`examples/README.md`](../examples/README.md) for the matrix.

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
