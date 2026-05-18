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

## Routes

The package can register two routes, controlled by `config/openapi.routes`:

- the **spec endpoint** (serves the raw OpenAPI YAML) — `spec.enabled`, defaults to **on**;
- the **Scalar playground** (an interactive API reference) — `playground.enabled`, defaults to
  **on only when `APP_ENV` is `local`**.

The route prefix, middleware, and URIs are all configurable. Set `routes.enabled` to `false` to
register no routes at all.

## Plugins

Core is convention-agnostic. The package ships one plugin — **SpatieData** — which teaches the
generator to read request schemas from Spatie Data classes. `FormRequest` request schemas are
supported by Core directly. You can write your own plugin against the `Plugin` interface to
support other request/resource conventions — see the plugin-authoring guide in
[`docs/usage.md`](docs/usage.md#adding-a-new-plugin).

## Documentation

- [`docs/usage.md`](docs/usage.md) — full usage guide: what's auto-derived, the attribute
  catalogue, validation-rule → schema mapping, the lint system, the config reference, and the
  plugin-authoring guide.
- [`docs/known-gaps.md`](docs/known-gaps.md) — documented limitations.

## License

MIT. See [`LICENSE`](LICENSE).
