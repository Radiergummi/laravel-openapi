# Architecture

For plugin authors and contributors. Library users don't need this page.

The codebase splits into four namespaces with distinct roles:

- **`Contracts\`** — the public extension surface (interfaces like `Plugin`,
  `RequestSchemaResolver`, `RefSchemaResolver`, `QueryParameterResolver`,
  `PrimaryResponseResolver`, `ErrorResponseResolver`, `SpecStage`,
  `RouteFilter`). Implement these to extend the library.
- **`Core\`** — the bundled **Core Plugin**: concrete extraction and
  processing strategies that ship by default (FormRequest extractor,
  error-envelope strategies, paginator response resolver, standard-response
  extractor, the default query-parameter resolver, the Faker example
  synthesiser, route introspection). It registers itself via
  `Core\Registration` before any third-party plugin.
- **`Support\`** — internal infrastructure used by Core and every plugin
  (the generator pipeline, registry, spec resolution, inclusion evaluator,
  visibility resolver, extraction primitives). Treat as `@internal`; not a
  stable extension point.
- **`Plugins\`** — third-party convention packages bundled with the library
  (Spatie Data, API Resources, Fractal, QueryBuilder); see [Plugins](plugins.md).

The `Plugin` interface is public; see [Plugin authoring](plugin-authoring.md).

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

Plugins implement the `Plugin` interface
(`src/Contracts/Registry/Plugin.php`) and register resolvers, extractors,
error-response factories, payload class markers, and lint rules into an
`OpenApiRegistry`.

```php
namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Registry\OpenApiRegistry;

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

Pipeline classes are bound as **scoped** singletons (not regular singletons).
Octane resets scoped bindings between requests, so each generation run gets
fresh instances. `ComponentSchemaRegistry` and `ExampleFileLoader` carry
mutable per-run state and would otherwise corrupt concurrent runs.

`reset()` methods exist but are redundant under the scoped lifecycle.

> [!WARNING]
> Don't downgrade these bindings to regular `singleton()` in a host service
> provider. That shares mutable per-run state across requests and breaks
> concurrent generation.
