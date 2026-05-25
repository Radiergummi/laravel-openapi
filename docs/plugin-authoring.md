# Writing a plugin

A plugin adds support for a third-party package or convention: how to read
its request DTOs, document its response envelopes, and what lint rules
enforce its conventions. Implement the `Plugin` interface and register the
class in `config/openapi.plugins`.

Bundled plugins: [Plugins](plugins.md). Architectural context:
[Architecture](architecture.md).

## The `Plugin` interface

```php
namespace Radiergummi\OpenApi\Core\Registry;

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
| `addRequestSchemaResolver(string $class)` | A request-body schema builder | `RequestSchemaResolver` |
| `addRefSchemaResolver(string $class)` | A `$ref` resolver for a class shape (e.g. a DTO or resource) | `RefSchemaResolver` |
| `addQueryParameterResolver(string $class)` | A query-parameter extractor | `QueryParameterResolver` |
| `addPrimaryResponseResolver(string $class)` | A 200/204 response resolver | `PrimaryResponseResolver` |
| `addErrorResponseResolver(string $class)` | An error-response schema resolver | `ErrorResponseResolver` |
| `addPayloadClass(string $class)` | Marks a base class as a request-payload DTO so `PayloadParameterScanner` recognises it | (a base class, not an interface) |
| `addRule(string $class)` | A lint rule | `Core\Lint\Rules\Rule` + one or more visitor interfaces |

Getters (`requestSchemaResolvers()`, `refSchemaResolvers()`,
`queryParameterResolvers()`, `primaryResponseResolvers()`,
`errorResponseResolvers()`, `payloadClasses()`, `rules()`) are consumed by
the generator and linter; plugin authors don't call them.

### Resolver interfaces

The resolver interfaces live in `src/Core/Registry/`:

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

Each resolver pairs a "can I handle this?" predicate with a "produce the
result" method. The pipeline iterates registered resolvers in registration
order and uses the first that claims the input.

> [!TIP]
> Read the interface source for the exact signatures. They're small and
> self-documenting.

## Worked example: the bundled SpatieData plugin

`SpatieDataPlugin` (`src/Plugins/SpatieData/SpatieDataPlugin.php`) is the
reference implementation. It registers support for Spatie Data classes:

```php
namespace Radiergummi\OpenApi\Plugins\SpatieData;

use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\MultipartFileWithoutMultipart;
use Spatie\LaravelData\Data;

final class SpatieDataPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRequestSchemaResolver(DataClassRequestSchemaResolver::class);
        $registry->addRefSchemaResolver(DataRefSchemaResolver::class);
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
- `addPayloadClass(Data::class)`: mark `Spatie\LaravelData\Data` as a payload
  base so any subclass on an action signature is treated as a request body.
- `addRule(...)`: register the two plugin-specific lint rules so they only
  run when the plugin is installed.

## Building your own plugin

1. Write resolver classes implementing the relevant interfaces from
   `src/Core/Registry/`. Use `src/Plugins/SpatieData/` as a template (e.g.
   mirror `DataClassRequestSchemaResolver` for a `RequestSchemaResolver`, or
   `DataRefSchemaResolver` for a `RefSchemaResolver`).
2. Optionally write lint rules under `Lint/Rules/`, implementing
   `Core\Lint\Rules\Rule` plus the visitor interfaces they need.
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

The SpatieData plugin exercises three of the seven hooks; the rest follow the
same class-string registration pattern.

> [!NOTE]
> Plugin-specific lint rules should follow the package's
> [severity convention](linting.md#severity-levels): level 0 for broken-spec
> contributions, level 1 for "lies about the API", level 2 for missing
> documentation, level 3+ for style.
