# Architecture

This page is for plugin authors and contributors. If you're just using the
package, you don't need to read it.

The subsystem is split into a convention-agnostic **Core** (`src/Core/`) and
**Plugins** (`src/Plugins/`) that teach Core about specific packages. The
package ships four plugins — see [Plugins](plugins.md) — and a `Plugin`
interface so you can write your own (see [Plugin authoring](plugin-authoring.md)).

## Generation pipeline

```
                                    ┌─ DocCommentParser ─ summary / description / @throws
Laravel routes                      │
              │                     ├─ UriParametersExtractor ─ path params + regex constraints
              ▼                     │
       RouteIntrospector ────────── ActionDescriptor ──────► OperationBuilder
                                                                    │
                                                                    │ for each resolver (from OpenApiRegistry):
                                                                    ▼
                                    ┌── QueryParameterResolver(s)
                                    ├── RequestSchemaResolver(s)      ← SpatieDataPlugin: DataClassRequestSchemaResolver
                                    │                                 ← Core: FormRequestRequestSchemaResolver
                                    ├── PrimaryResponseResolver(s)
                                    ├── SecurityExtractor (auth + scope middleware)
                                    └── StandardResponsesExtractor    (@throws + middleware → error responses)
                                                                    │
                                                                    ▼
                                                          ComponentSchemaRegistry
                                                  (shared $ref pool for Data schemas)
                                                                    │
                                                                    ▼
                                                            OpenApiGenerator
                                                                    │
                                                                    ▼
                                                     OpenAPI 3.1 YAML / JSON document
```

### Walkthrough

1. **`RouteIntrospector`** walks Laravel routes (after applying every
   configured `RouteFilter`), producing an `ActionDescriptor` per route.
   `DocCommentParser` extracts summary, description, and `@throws` tags.
2. **`OperationBuilder`** builds each operation by running every
   resolver/extractor registered in the `OpenApiRegistry`:
   query-parameter resolvers, request-schema resolvers, primary-response
   resolvers, `SecurityExtractor`, `StandardResponsesExtractor`, and
   `UriParametersExtractor`.
3. **`ComponentSchemaRegistry`** is the shared `$ref` pool for reusable
   Data-class schemas.
4. **`OpenApiGenerator`** assembles the final OpenAPI 3.1 document (YAML or
   JSON).

`OpenApiServiceProvider` wires everything.

## Plugin system

`Core` itself is package-agnostic. It ships a `Plugin` interface
(`src/Core/Registry/Plugin.php`). Plugins register resolvers, extractors,
error-response factories, payload class markers, and lint rules into an
`OpenApiRegistry` instance.

```php
namespace Radiergummi\OpenApi\Core\Registry;

interface Plugin
{
    public function register(OpenApiRegistry $registry): void;
}
```

Plugins are listed in `config/openapi.plugins` and resolved from the container.
`CoreRegistration::register()` runs first (registering
`FormRequestRequestSchemaResolver` and all core lint rules), then each plugin
in declaration order, then any `config/openapi.lint.rules` extras.

For the registry surface and a worked plugin example, see
[Plugin authoring](plugin-authoring.md).

## Lint subsystem

`SpecTreeBuilder` converts the generated document into a domain tree
(`Tree/*Node`). `SpecTreeWalker` walks it; each `Rules/*` rule implements one
or more visitor interfaces (`Rules/Visitors/*Rule`) and emits `Finding`s into
a `FindingsCollector`. `RuleRegistry` holds the active rules with
config-driven severity overrides. `SuppressionCollector` reads `#[IgnoreLint]`
attributes. Each lint rule has a stable string ID (e.g. `operation.id-missing`).

For the rule catalog and severity scale, see [Linting](linting.md).

## Service lifecycle

All pipeline classes are bound as **scoped** singletons (not regular
singletons). Octane resets scoped bindings between requests, so each
generation run gets fresh instances — `ComponentSchemaRegistry` and
`ExampleFileLoader` carry mutable per-run state and would corrupt concurrent
runs otherwise.

`reset()` methods exist but are redundant under the scoped lifecycle.

> [!WARNING]
> Don't downgrade any of the package's bindings to a regular `singleton()`
> in a host service provider — that would share mutable per-run state across
> requests and break concurrent generation.
