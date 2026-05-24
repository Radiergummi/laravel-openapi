# Getting started

`radiergummi/laravel-openapi` derives a complete OpenAPI 3.1 document from your
existing route definitions — typed request DTOs, typed return values, PHPDoc
summaries, and middleware. There is no hand-written YAML.

In under five minutes you can install it, generate a spec for your existing
API, and open an interactive playground.

## Requirements

- PHP **8.4+**
- Laravel **12** or **13**

## Install

```bash
composer require radiergummi/laravel-openapi
```

The service provider is auto-discovered.

Publish the config file if you want to customise anything:

```bash
php artisan vendor:publish --tag=openapi-config
```

## Generate your first spec

```bash
php artisan openapi:generate
```

That's it. The generator walks every route Laravel knows about, derives one
operation per route from controller signatures and PHPDoc, and writes an
OpenAPI 3.1 YAML document.

> [!NOTE]
> No routes appearing? See
> [Troubleshooting → My endpoint doesn't appear in the generated spec](troubleshooting.md#my-endpoint-doesnt-appear-in-the-generated-spec).

## View it

| URL / Command | Purpose |
|---|---|
| `GET /api/docs` | Interactive Scalar playground — "Try it out", schema browser |
| `GET /api/openapi.yaml` | Raw OpenAPI 3.1 YAML — what tooling consumes |
| `php artisan openapi:generate` | Regenerate the YAML file. Pass a spec name positionally to target one spec; `--output=path` to override the destination (`--output=-` writes to stdout); `--format=json` to emit JSON. |
| `php artisan openapi:lint` | Report documentation gaps |
| `php artisan openapi:clear` | Drop the cached spec |

The playground route is **enabled only when `APP_ENV` is `local`** by default;
the spec route is always on. Both are configurable in `config/openapi.routes`
— see [Config reference](config.md#routes).

## A worked example

A typed Spatie Data class plus a typed return value is all the generator
needs:

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

`php artisan openapi:generate` produces an operation for `GET /flights/{flight}`:

- summary `"Show a single flight."` (from the PHPDoc)
- a `FlightData` component schema with `number`, `origin` (3 chars), `destination`
  (3 chars), and `departs_at` (`string`, `date-time`)
- a `404` response if the controller's `@throws` lists `ModelNotFoundException`
- a security requirement if the route uses `auth:api` middleware

All derived from PHP types, validation attributes, and the docblock — no YAML,
no annotations.

## Run the examples

Five runnable Laravel apps live under [`examples/`](../examples/), each exposing
the same flights + bookings API in a different style (vanilla, FormRequest,
Spatie Data, QueryBuilder, combined). Each ships its generated `openapi.yaml`
next to the code so the difference is inspectable.

```bash
composer examples           # regenerate every snapshot
composer examples:vanilla   # regenerate one
```

## Where to next

- **[Auto-derivation](auto-derivation.md)** — which parts of an operation come from
  which part of your code. Read this once, then most endpoints document themselves.
- **[Request bodies](request-bodies.md)** — choose between FormRequest and Spatie Data;
  validation-rule → schema mapping.
- **[Attributes](attributes.md)** — the escape-hatch catalog for cases convention
  can't derive.
- **[Recipes](recipes.md)** — 22 short cookbook entries: streaming, multipart,
  polymorphism, security schemes, hidden endpoints, webhooks, links.
- **[Linting](linting.md)** — `openapi:lint`, the rule catalog, suppression with
  `#[IgnoreLint]`.
- **[Troubleshooting](troubleshooting.md)** — symptom-indexed answers.
