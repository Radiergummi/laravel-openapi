# Documentation

`radiergummi/laravel-openapi` generates an OpenAPI 3.1 document from your
existing Laravel route definitions — no hand-written YAML.

If you're new, start with **[Getting started](getting-started.md)** and then
read **[Auto-derivation](auto-derivation.md)** — together they take ten
minutes and cover what most projects need.

## Guides

| Page | What it covers |
|---|---|
| [Getting started](getting-started.md) | Install, generate your first spec, view the playground. |
| [Auto-derivation](auto-derivation.md) | What's derived from what. The magic table. |
| [Request bodies](request-bodies.md) | FormRequest vs Spatie Data; validation-rule → schema mapping. |
| [Attributes](attributes.md) | The escape-hatch catalog. |
| [Recipes](recipes.md) | 22 short snippets: streaming, multipart, polymorphism, links, security schemes. |
| [Plugins](plugins.md) | Bundled plugins: SpatieData, ApiResources, QueryBuilder, Fractal. |
| [Linting](linting.md) | `openapi:lint`, severity scale, the rule catalog, `#[IgnoreLint]`. |
| [Multi-spec](multi-spec.md) | Partition routes into multiple OpenAPI documents. |
| [Configuration](config.md) | Every `config/openapi.php` key. |
| [Troubleshooting](troubleshooting.md) | Symptom-indexed answers. |

## Extending

| Page | What it covers |
|---|---|
| [Extensions](extensions.md) | Programmatic transformers for operations, schemas, and the whole document. |
| [Plugin authoring](plugin-authoring.md) | Writing your own plugin against the `Plugin` interface. |
| [Architecture](architecture.md) | Generation pipeline, plugin system, service lifecycle. |

## Project

| Page | What it covers |
|---|---|
| [Known gaps](known-gaps.md) | Documented limitations and their workarounds. |
| [Changelog](../CHANGELOG.md) | Release notes. |
| [Contributing](../CONTRIBUTING.md) | Local dev, test suite, CI gates. |

## Worked examples

Five runnable Laravel apps live under [`examples/`](../examples/), each
exposing the same flights + bookings API in a different style. See
[`examples/README.md`](../examples/README.md) for the matrix.
