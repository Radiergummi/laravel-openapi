# Architecture

For plugin authors and contributors. Library users don't need this page.

The codebase splits into three primary namespaces with distinct roles:

- **`Contracts\`** — the public extension surface (interfaces like `Plugin`,
  `RequestSchemaResolver`, `RefSchemaResolver`, `QueryParameterResolver`,
  `PrimaryResponseResolver`, `ErrorResponseResolver`, `SpecStage`,
  `RouteFilter`). Implement these to extend the library.
- **`Support\`** — internal infrastructure used by every plugin (the generator
  pipeline, the registry and its assembly in
  `Support\Generator\BaselineRegistration`, spec resolution, the inclusion
  evaluator, the visibility resolver, extraction primitives, and the shared
  Faker example synthesiser). Treat as `@internal`; not a stable extension
  point.
- **`Plugins\`** — the bundled plugins. **Core** (`Plugins\Core\CorePlugin`) is
  the **Core Plugin**: the concrete strategies that understand vanilla Laravel
  (FormRequest request-body extraction, paginator and Eloquent-model response
  resolvers, the default query-parameter resolver, the `@throws` / middleware /
  validation / route-model-binding error contributors, resourceful-action
  conventions, route introspection). It is registered first, ahead of the
  convention plugins (SpatieData, ApiResources, QueryBuilder, Fractal) and the
  swagger-php harvester (SwaggerPhp). See [Plugins](plugins.md).

The package stays functional with Core disabled — just without the smarts to
read those vanilla patterns. The `Plugin` interface is public; see
[Plugin authoring](plugin-authoring.md).

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
                                    └── SecurityExtractor (auth + scope middleware)
                                                                    │
                                                                    ▼  PathsStage (binds Operation → ActionDescriptor)
                                                                    │
                                                                    ▼  ErrorResponseInferenceStage
                                    ┌── ErrorResponseContributor(s)   ← Core: ThrowsErrorContributor
                                    │                                 ← Core: AbortErrorContributor
                                    │                                 ← Core: MiddlewareErrorContributor
                                    └──                               ← Core: ValidationErrorContributor
                                                                    │  (dedupes by status; explicit #[Response] wins)
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
   resolvers, `SecurityExtractor`, and `UriParametersExtractor`.
3. **`PathsStage`** populates `GenerationContext` with a per-operation
   `ActionDescriptor` lookup so later stages can find the action that
   produced each `OA\Operation`.
4. **`ErrorResponseInferenceStage`** runs the registered
   `ErrorResponseContributor` chain per operation, collects
   `ErrorDescriptor`s from each contributor (`ThrowsErrorContributor`,
   `AbortErrorContributor`, `MiddlewareErrorContributor`,
   `ValidationErrorContributor`), dedupes by status (first contributor
   wins), and appends inferred error responses.
   Explicit `#[Response(status: X)]` attributes on the action always
   override inferred responses for that status.
5. **`ComponentSchemaRegistry`** is the shared `$ref` pool for reusable
   Data-class schemas.
6. **`OpenApiGenerator`** assembles the final OpenAPI 3.1 document (YAML or
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
`BaselineRegistration::assemble()` assembles everything in one place: it adds the
pre-plugin baseline stages, runs each plugin's `register()` in declaration order
(starting with `CorePlugin`), then adds the post-plugin stages (the
`ComponentsStage` flush, `SecurityStage`, and the terminal `OverridesStage` →
`TransformersStage`), registers the baseline and `config/openapi.lint.rules` lint
rules, and finally **seals** the registry so no further registration is accepted
out-of-band. The `OpenApiServiceProvider` factory closure owns only the
Laravel/config glue and passes the plugin list, config rules, and resolved
error-envelope class into `assemble()` as class-strings, keeping that assembly
plugin-agnostic.

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
