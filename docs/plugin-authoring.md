# Writing a plugin

A plugin adds support for a third-party package or convention: how to read
its request DTOs, document its response envelopes, and what lint rules
enforce its conventions. Implement the `Plugin` interface and register the
class in `config/openapi.plugins`.

Bundled plugins: [Plugins](plugins.md). Architectural context:
[Architecture](architecture.md).

## The `Plugin` interface

```php
namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Registry\OpenApiRegistry;

interface Plugin
{
    public function register(OpenApiRegistry $registry): void;
}
```

`register()` runs once at boot, after Core's own registration, in
`config/openapi.plugins` declaration order. The plugin instance is resolved
from the Laravel container, so it may take constructor dependencies.

## The `OpenApiRegistry` API

`OpenApiRegistry` is the inventory the generator and linter read from. A
plugin contributes by calling its `add*` methods. Each takes a class-string;
instances are resolved from the container when the pipeline runs.

| Method | Contributes | Interface the class must implement |
|---|---|---|
| `addErrorResponseContributor(string $class)` | An inference source for error responses; called per operation, returns `ErrorDescriptor`s | `ErrorResponseContributor` |
| `addErrorResponseResolver(string $class)` | An error-response schema resolver | `ErrorResponseResolver` |
| `addOperationConventionResolver(string $class)` | An operation-level convention resolver (e.g. resourceful-action success codes and summaries derived from Tier-0 signals) | `OperationConventionResolver` |
| `addPayloadClass(string $class)` | Marks a base class as a request-payload DTO so `PayloadParameterScanner` recognises it | (a base class, not an interface) |
| `addPrimaryResponseResolver(string $class)` | A 200/204 response resolver | `PrimaryResponseResolver` |
| `addQueryParameterResolver(string $class)` | A query-parameter extractor | `QueryParameterResolver` |
| `addRefSchemaResolver(string $class)` | A `$ref` resolver for a class shape (e.g. a DTO or resource) | `RefSchemaResolver` |
| `addRequestSchemaResolver(string $class)` | A request-body schema builder | `RequestSchemaResolver` |
| `addRule(string $class)` | A lint rule | `Contracts\Lint\Rule` + one or more visitor interfaces |
| `addStage(string $class)` | A document-level pipeline stage. Plugin stages run after the pre-plugin baseline stages and before the post-plugin flush + terminal stages (see `SpecStage` below) | `Contracts\Generator\SpecStage` |

The corresponding read sides (`$registry->errorResponseContributors`,
`$registry->errorResponseResolvers`, `$registry->payloadClasses`,
`$registry->primaryResponseResolvers`, `$registry->operationConventionResolvers`,
`$registry->queryParameterResolvers`, `$registry->refSchemaResolvers`,
`$registry->requestSchemaResolvers`, `$registry->rules`, `$registry->stages`)
are `public private(set)` properties consumed by the generator and linter;
plugin authors don't read them.

### Resolver interfaces

The resolver interfaces live in `src/Contracts/Registry/`:

- **`ErrorResponseContributor`**: given an `ActionDescriptor`, return any
  error responses implied by it (`@throws` annotations, middleware, payload
  type, etc.). Bundled by Core: `ThrowsErrorContributor`,
  `MiddlewareErrorContributor`, `ValidationErrorContributor`. The
  `ErrorResponseInferenceStage` runs the chain in registration order and
  dedupes by status (first wins). Explicit `#[Response(status: X)]`
  attributes on the action always override inferred responses for that
  status.
- **`RequestSchemaResolver`**: given an `ActionDescriptor`, decide whether
  it handles the request body and produce the schema. Bundled:
  `DataClassRequestSchemaResolver` (Spatie Data),
  `FormRequestRequestSchemaResolver` (`FormRequest`).
- **`RefSchemaResolver`**: given a class-string, decide whether it owns the
  class's shape and produce a component schema. Used to teach the shared
  `$ref` pool how to serialise a class. Bundled: `DataRefSchemaResolver`.
- **`QueryParameterResolver`**: produce query parameters from the
  `ActionDescriptor`. Used for `?filter[…]`, `?include`, `?sort`, paging.
- **`PrimaryResponseResolver`**: resolve the 2xx response schema from the
  return type or attributes.
- **`ErrorResponseResolver`**: given an `ErrorDescriptor`, decide whether
  it handles the error-response schema and produce an `ErrorResponse` (or null).
  Used for error envelopes (RFC 7807, JSON:API, etc.) on 4xx/5xx responses.
  Implementations must catch internally and return `null` on failure — the
  extractor wraps each call in a `try`/`catch` and emits a
  `errors.resolver-failed` lint finding as a backstop, but a clean `null`
  return defers to the next resolver in the chain without noise.
  `resolveErrorResponse()` is invoked **per status × per operation**, so any
  schema registrations on `ComponentSchemaRegistry` must be idempotent (guard
  with `hasKey()` / `isRegisteredOrReserved()`).
- **`SpecStage`** (`src/Contracts/Generator/`): one step in the OpenAPI
  document assembly pipeline. The whole stage order lives in one place —
  `BaselineRegistration::assemble()` — as a single top-to-bottom
  sequence: the pre-plugin baseline stages (`RootStage`, `PathsStage`,
  `ErrorResponseInferenceStage`), then every plugin's stages in registration
  order, then the post-plugin stages (`ComponentsStage` flush, `SecurityStage`,
  and the terminal `OverridesStage` → `TransformersStage`). A stage receives the
  shared `OA\OpenApi` document and a `GenerationContext` and mutates it in place.

  Because the `ComponentsStage` flush runs **after** plugin stages, a plugin
  stage that contributes schemas should register them on the shared
  `ComponentSchemaRegistry` (`register()` / `registerNamed()`) and let the flush
  write them — that gets idempotent dedup and schema-transformer dispatch for
  free, and is how the SwaggerPhp harvester works. Only reach for a direct
  `$doc->components` write in a stage that runs after the flush, and then
  **merge, don't replace** — `$doc->components` is shared with `SecurityStage`,
  so assigning a fresh `new OA\Components([...])` discards the `securitySchemes`
  it wrote. Read-modify-write instead:
  ```php
  $components = $doc->components instanceof OA\Components
      ? $doc->components
      : new OA\Components([]);
  $existing = is_array($components->schemas) ? $components->schemas : [];
  $components->schemas = array_merge($existing, $mySchemas);
  $doc->components = $components;
  ```

Each resolver pairs a "can I handle this?" predicate with a "produce the
result" method. The pipeline iterates registered resolvers in registration
order and uses the first that claims the input.

> [!NOTE]
> **Fault isolation is centralized — resolvers needn't self-isolate.** Every
> `PrimaryResponseResolver`, `RequestSchemaResolver`, `QueryParameterResolver`,
> and `RefSchemaResolver` call is wrapped at its pipeline seam: a thrown
> `Exception` (including yours) is logged with the route and resolver class,
> that resolver is skipped for the route, and the rest of the document still
> generates. So you don't need a defensive `try`/`catch` for robustness — let a
> malformed input surface as an `Exception` and the pipeline degrades for you.
> Conversely, **let programming errors propagate**: `Error`/`TypeError` are
> *not* caught, so a real bug in resolver code surfaces as a stack trace instead
> of disappearing into a silently missing schema. (The `ErrorResponseResolver`
> backstop described above is separate and unchanged.)

> [!TIP]
> Read the interface source for the exact signatures. They're small and
> self-documenting.

## Worked example: the bundled SpatieData plugin

`SpatieDataPlugin` (`src/Plugins/SpatieData/SpatieDataPlugin.php`) is the
reference implementation. It registers support for Spatie Data classes:

```php
namespace Radiergummi\OpenApi\Plugins\SpatieData;

use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\MultipartFileWithoutMultipart;
use Radiergummi\OpenApi\Plugins\SpatieData\Resolvers\DataClassRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\Resolvers\DataRefSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\Resolvers\DataResponseResolver;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Spatie\LaravelData\Data;

final class SpatieDataPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        // Optional-dependency guard: ship enabled by default, no-op when the
        // package isn't installed, so listing it imposes no runtime dependency.
        if (!class_exists(Data::class)) {
            return;
        }

        $registry->addRequestSchemaResolver(DataClassRequestSchemaResolver::class);
        $registry->addRefSchemaResolver(DataRefSchemaResolver::class);
        $registry->addPrimaryResponseResolver(DataResponseResolver::class);
        $registry->addPayloadClass(Data::class);
        $registry->addRule(MultipartFileWithoutMultipart::class);
        $registry->addRule(FieldAttributeWrongScope::class);
    }
}
```

Line by line:

- `addRequestSchemaResolver(DataClassRequestSchemaResolver::class)`: when a
  controller action type-hints a Data class (directly or via a configured
  payload-indirection object), build the request body from PHP types,
  validation rules, and field attributes.
- `addRefSchemaResolver(DataRefSchemaResolver::class)`: when a Data class is
  referenced from another schema (a nested DTO, a `#[Response(ref: …)]`),
  emit it as a `$ref` into `components.schemas`.
- `addPrimaryResponseResolver(DataResponseResolver::class)`: when an action
  returns a Data class (or a union of them), build its 200 response body.
- `addPayloadClass(Data::class)`: mark `Spatie\LaravelData\Data` as a payload
  base so any subclass on an action signature is treated as a request body.
- `addRule(...)`: register the two plugin-specific lint rules so they only
  run when the plugin is installed.

The leading `class_exists(Data::class)` guard is the pattern for an
optional-dependency plugin: it lets the plugin ship enabled by default in
`config/openapi.plugins` while no-op'ing when the package isn't installed, so
listing it imposes no runtime dependency.

## Building your own plugin

1. Write resolver classes implementing the relevant interfaces from
   `src/Contracts/Registry/`. Use `src/Plugins/SpatieData/` as a template (e.g.
   mirror `DataClassRequestSchemaResolver` for a `RequestSchemaResolver`, or
   `DataRefSchemaResolver` for a `RefSchemaResolver`).
2. Optionally write lint rules under `Lint/Rules/`, implementing
   `Contracts\Lint\Rule` plus the visitor interfaces they need.
3. Write a `Plugin` class whose `register()` calls the matching
   `OpenApiRegistry` `add*` methods.
4. Bind any services your resolvers need in a service provider (the bundled
   plugin's bindings live in `OpenApiServiceProvider`; external plugins ship
   their own).
5. Add the plugin class to `config/openapi.plugins`.

A JSON:API-style plugin (query-parameter convention plus resource and error
envelopes) would register:

- a `QueryParameterResolver` (for `?filter`, `?include`, `?sort`)
- a `PrimaryResponseResolver` and `RefSchemaResolver` (for the resource envelope)
- an `ErrorResponseResolver` (for the error envelope)
- lint rules enforcing its conventions

The SpatieData plugin exercises five of the registry's ten `add*` hooks; the
rest follow the same class-string registration pattern.

> [!NOTE]
> Plugin-specific lint rules should follow the package's
> [severity convention](linting.md#severity-levels): level 0 for broken-spec
> contributions, level 1 for "lies about the API", level 2 for missing
> documentation, level 3+ for style.

## Public contracts and helpers

The library exposes three surfaces plugin authors can rely on. All ship under
the regular package autoload — they're not test-only.

### `Contracts\Routing\ResourceTargetLocator`

Resolves the resource class an action returns and whether the response is a
collection. Inject it into a `PrimaryResponseResolver` for any resource
convention (JSON:API, HAL, Fractal, …) and detection stays aligned with the
bundled `JsonResource` rules (`#[Collects]`, `$collects`, `#[ResponseResource]`).

```php
use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Routing\ResourceTarget;

final readonly class JsonApiPrimaryResponseResolver
{
    public function __construct(
        private ResourceTargetLocator $locator,
    ) {}

    public function resolveResponse(ActionDescriptor $descriptor): mixed
    {
        $target = $this->locator->locate($descriptor);

        if ($target === null || $target->isAmbiguous()) {
            return null;
        }

        // $target->resourceClass is the JsonResource subclass; $target->isCollection
        // tells you whether to wrap in a list response.
        // …
    }
}
```

The container resolves it to `Plugins\ApiResources\Support\ResourceClassLocator` — the
binding lives in `OpenApiServiceProvider::register()` and is always-on,
regardless of which plugins are enabled.

### `Testing\ActionDescriptorFactory`

Builds `ActionDescriptor` instances for plugin tests with a one-line call.
Use it instead of hand-wiring `Route` + `ReflectionMethod` + the constructor.

```php
use Radiergummi\OpenApi\Testing\ActionDescriptorFactory;

$descriptor = ActionDescriptorFactory::make(
    controller: MyController::class,
    method: 'index',
    uri: '/items',
    httpMethods: ['GET'],
);
```

Named arguments override defaults; the only required ones are `controller`
and `httpMethod`.

### `Testing\SchemaContextScope`

Pins swagger-php's global context to OAS 3.1.0 while a callable runs. Required
when constructing `OA\Schema` instances outside the generator pipeline (e.g.
in unit tests that exercise a resolver's schema output directly), because
swagger-php's default context is 3.0.0, which silently rewrites 3.1-only
keywords (`const`, `examples`) on `jsonSerialize()`.

```php
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Testing\SchemaContextScope;

$json = SchemaContextScope::with(function (): string {
    $schema = new OA\Schema(['const' => 'value']);

    return json_encode($schema, JSON_THROW_ON_ERROR);
});

// $json contains "const":"value", not "enum":["value"]
```

During real spec generation the library already pins this context; the helper
is only needed for standalone schema construction in tests.

### Extending `ComponentSchemaRegistry`

The canonical entry point is `buildOnce($className, fn(): OA\Schema => …)`.
It reserves a component key for the class, calls the factory if no schema is
registered yet, stores the result, and returns the qualified component key
(e.g. `#/components/schemas/MyDto`) suitable for use as a `$ref`. Two related
public methods:

- `keyFor(string $className): ?string` — look up the component key for an
  already-registered class.
- `isInProgress(string $className): bool` — true when `buildOnce` is currently
  building this class higher up the stack. Use it from a factory that
  recurses into the registry for a *nested* type, so you can emit a `$ref`
  placeholder instead of triggering a nested rebuild.

```php
$ref = $registry->buildOnce(MyDto::class, function () use ($registry): OA\Schema {
    // Building a nested DTO that may cycle back to MyDto:
    $nestedRef = $registry->isInProgress(NestedDto::class)
        ? $registry->keyFor(NestedDto::class)
        : $registry->buildOnce(NestedDto::class, fn () => buildNestedSchema());

    return new OA\Schema([
        'properties' => [
            new OA\Property(property: 'nested', ref: $nestedRef),
        ],
    ]);
});

// $ref === '#/components/schemas/MyDto'
```
