# Documentation

`radiergummi/laravel-openapi` generates an OpenAPI 3.1 document from Laravel
routes. No hand-written YAML.

New to the package? Read [Getting started](getting-started.md), then
[Auto-derivation](auto-derivation.md).

## Guides

| Page | Covers |
|---|---|
| [Getting started](getting-started.md) | Install, generate the first spec, view the playground. |
| [Auto-derivation](auto-derivation.md) | Which part of the spec comes from which part of your code. |
| [Request bodies](request-bodies.md) | `FormRequest` vs Spatie Data; validation-rule → schema mapping. |
| [Attributes](attributes.md) | Escape-hatch catalog. |
| [Recipes](recipes.md) | Short snippets for streaming, multipart, polymorphism, links, operationIds, and security schemes. |
| [Plugins](plugins.md) | Bundled plugins: SpatieData, ApiResources, QueryBuilder, Fractal, SwaggerPhp. |
| [Linting](linting.md) | `openapi:lint`, severity levels, rule catalog, `#[IgnoreLint]`. |
| [Multi-spec](multi-spec.md) | Multiple OpenAPI documents from one app. |
| [Configuration](config.md) | `config/openapi.php` keys. |
| [Troubleshooting](troubleshooting.md) | Symptom index. |
| [Field report](field-report.md) | How the generator performed against eleven real-world OSS Laravel apps. |

## Extending

| Page | Covers |
|---|---|
| [Extensions](extensions.md) | Programmatic transformers for operations, schemas, documents. |
| [Custom validation rules](extending/custom-validation-rules.md) | Letting custom `Rule` objects declare their own schema constraints. |
| [Plugin authoring](plugin-authoring.md) | Implementing the `Plugin` interface. |
| [Architecture](architecture.md) | Generation pipeline, plugin system, service lifecycle. |

## Project

| Page | Covers |
|---|---|
| [Changelog](../CHANGELOG.md) | Release notes. |
| [Contributing](../CONTRIBUTING.md) | Local dev, test suite, CI gates. |

## Worked examples

Five runnable Laravel apps live under [`examples/`](../examples/). Each
serves the same flights + bookings API in a different style. See
[`examples/README.md`](../examples/README.md) for the matrix.
