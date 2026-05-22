# Writing a plugin

A plugin teaches Core about a specific package or convention — how to read its
request DTOs, how to document its response envelopes, what lint rules enforce
its conventions. Implement the `Plugin` interface and register the class in
`config/openapi.plugins`.

For the four bundled plugins, see [Plugins](plugins.md). For the architectural
context, see [Architecture](architecture.md).

## The `Plugin` interface

```php
namespace Radiergummi\OpenApi\Core\Registry;

interface Plugin
{
    public function register(OpenApiRegistry $registry): void;
}
```

`register()` is called once at boot, after `CoreRegistration` and in
`config/openapi.plugins` declaration order. The plugin instance itself is
resolved from the Laravel container, so it may take constructor dependencies.

## The `OpenApiRegistry` API

`OpenApiRegistry` is the assembled inventory the generator and linter read
from. A plugin contributes by calling its `add*` methods — each takes a
**class-string**; instances are resolved from the container when the pipeline
runs.

| Method | Contributes | Interface the class must implement |
|---|---|---|
| `addRequestSchemaResolver(string $class)` | A request-body schema builder | `RequestSchemaResolver` |
| `addRefSchemaResolver(string $class)` | A `$ref` resolver for a class shape (e.g. a DTO or resource) | `RefSchemaResolver` |
| `addQueryParameterResolver(string $class)` | A query-parameter extractor | `QueryParameterResolver` |
| `addPrimaryResponseResolver(string $class)` | A 200/204 response resolver | `PrimaryResponseResolver` |
| `addErrorResponseFactory(string $class)` | An error-response schema factory | `ErrorResponseFactory` |
| `addPayloadClass(string $class)` | Marks a base class as a request-payload DTO so `PayloadParameterScanner` recognises it | (a base class, not an interface) |
| `addRule(string $class)` | A lint rule | `Core\Lint\Rules\Rule` + one or more visitor interfaces |

The corresponding getters (`requestSchemaResolvers()`, `refSchemaResolvers()`,
`queryParameterResolvers()`, `primaryResponseResolvers()`,
`errorResponseFactories()`, `payloadClasses()`, `rules()`) are what the
generator and linter consume — a plugin author does not call them.

### Resolver interfaces

The resolver interfaces live in `src/Core/Registry/`:

- **`RequestSchemaResolver`** — given an `ActionDescriptor`, decide whether it
  can resolve a request body for that action, and if so produce the body
  schema. Used to support a new kind of request DTO. The bundled
  `DataClassRequestSchemaResolver` handles Spatie Data classes; Core's
  `FormRequestRequestSchemaResolver` handles `FormRequest` subclasses.
- **`RefSchemaResolver`** — given a class-string, decide whether it owns that
  class's shape and produce a component schema for it. Used to teach the
  shared `$ref` pool how to serialise a class (a DTO base, a resource base,
  an entity). The bundled `DataRefSchemaResolver` handles Spatie Data classes.
- **`QueryParameterResolver`** — given an `ActionDescriptor`, produce
  query-string parameters. Used to derive `?filter[…]`, `?include`, `?sort`,
  paging, etc. from a request-class convention.
- **`PrimaryResponseResolver`** — given an `ActionDescriptor`, resolve the
  primary 2xx response schema from the controller's return type or attributes.
- **`ErrorResponseFactory`** — build the error-response schema (e.g. an
  RFC 7807 or JSON:API error envelope) used for the 4xx/5xx responses
  contributed by `StandardResponsesExtractor`.

Each resolver follows the same shape: a "can I handle this?" predicate plus a
"produce the result" method. The generator iterates registered resolvers in
registration order and uses the first that claims the input.

> [!TIP]
> Read the interface source for the exact method signatures — they are small
> and self-documenting.

## Worked example: the bundled SpatieData plugin

The shipped `SpatieDataPlugin`
(`src/Plugins/SpatieData/SpatieDataPlugin.php`) is the reference implementation.
It teaches Core to read request schemas from Spatie Data classes:

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

What each line does:

- `addRequestSchemaResolver(DataClassRequestSchemaResolver::class)` — when a
  controller action type-hints a Spatie Data class (directly or via a
  configured payload-indirection object), this resolver builds the request
  body from the Data class's PHP types, validation rules, and field attributes.
- `addRefSchemaResolver(DataRefSchemaResolver::class)` — when a Data class is
  referenced from another schema (a nested DTO, a `#[Response(ref: …)]`), this
  resolver emits it as a `$ref` into `components.schemas`.
- `addPayloadClass(Data::class)` — marks `Spatie\LaravelData\Data` as a payload
  base so `PayloadParameterScanner` treats any subclass found on an action
  signature as a request body.
- `addRule(...)` — registers the two plugin-specific lint rules
  (`field.attribute-wrong-scope`, `multipart.file-without-multipart`), so they
  only run when the plugin is installed.

## Building your own plugin

To support a different request-class convention or resource library, follow the
same shape:

1. Write the resolver classes implementing the relevant interfaces from
   `src/Core/Registry/`. Use `src/Plugins/SpatieData/` as a template — for
   instance, mirror `DataClassRequestSchemaResolver` for a
   `RequestSchemaResolver`, or `DataRefSchemaResolver` for a
   `RefSchemaResolver`.
2. Optionally write lint rules in a `Lint/Rules/` sub-namespace, each
   implementing `Core\Lint\Rules\Rule` plus the visitor interfaces it needs.
3. Write a `Plugin` class whose `register()` calls the matching
   `OpenApiRegistry` `add*` methods.
4. Bind any services your resolvers need in a service provider (the bundled
   plugin's bindings live in `OpenApiServiceProvider`; an external plugin
   would supply its own provider).
5. Add the plugin class to `config/openapi.plugins`.

A plugin that adds a query-parameter convention plus a resource/response shape
— i.e. a plugin for a JSON:API-style library — would register:

- a `QueryParameterResolver` (to derive `?filter`, `?include`, `?sort` parameters)
- a `PrimaryResponseResolver` and `RefSchemaResolver` (to serialise its
  resource envelope)
- an `ErrorResponseFactory` (to emit its error envelope)
- whatever lint rules enforce its conventions

All of those hooks exist on the registry; the SpatieData plugin exercises
three of the seven, and the remaining four follow the identical
class-string-registration pattern.

> [!NOTE]
> Plugin-specific lint rules should follow the package's
> [severity convention](linting.md#severity-levels): level 0 for broken-spec
> contributions, level 1 for "lies about the API", level 2 for missing
> documentation, level 3+ for style.
