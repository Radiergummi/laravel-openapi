# Extensions

For cases that can't be expressed with authoring attributes, `OpenApiExtensions`
exposes three static hook points. Register them at boot (typically in a service
provider's `boot()` method).

```php
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;
```

> [!TIP]
> Extensions are for project-specific behaviour. If you find yourself writing a
> hook that would apply to every consumer of a third-party package, consider
> writing a [plugin](plugin-authoring.md) instead.

## Operation transformer

Invoked once per assembled operation, after all attributes and extractors have
run:

```php
use Radiergummi\OpenApi\Core\Extensions\OperationContext;
use OpenApi\Annotations as OA;

OpenApiExtensions::transformOperation(
    static function (OA\Operation $operation, OperationContext $ctx): void {
        if (str_contains($ctx->routeUri, 'webhooks/stripe')) {
            $operation->tags = ['Stripe'];
        }
    },
);
```

`OperationContext` exposes:

- `$descriptor` — the full `ActionDescriptor`
- `$httpMethod`
- `$controllerClass`, `$methodName`, `$routeUri`

## Schema transformer

Invoked once per component schema. Primary escape hatch for custom `Rule`
objects the generic extractor cannot handle:

```php
use Radiergummi\OpenApi\Core\Extensions\SchemaContext;

OpenApiExtensions::transformSchema(
    static function (OA\Schema $schema, SchemaContext $ctx): void {
        if ($ctx->sourceClass === null) {
            return;
        }
        foreach ($schema->properties ?? [] as $property) {
            if ($property->property === 'color') {
                $property->pattern = '^#[0-9a-fA-F]{6}$';
            }
        }
    },
);
```

`SchemaContext` exposes:

- `$componentKey` — the `components.schemas` key
- `$sourceClass` — the PHP class the schema was derived from (`null` for
  hand-built schemas)

## Document transformer

Invoked once on the fully assembled `OA\OpenApi` document before it is returned:

```php
OpenApiExtensions::transformDocument(
    static function (OA\OpenApi $document): void {
        $document->{'x-api-version'} = config('app.version');
    },
);
```

## Flushing (tests)

`OpenApiExtensions::flush()` removes all registered transformers. Call it in
`afterEach()` when testing code that registers transformers:

```php
afterEach(function (): void {
    OpenApiExtensions::flush();
});
```

## Events

For *observability* — not mutation — the generator and linter dispatch standard
Laravel events. Use a transformer to modify the spec; use an event listener to
observe, log, export, or notify. Four events ship out of the box:

| Event | When | Carries |
|---|---|---|
| [`SpecGenerationStarted`](../src/Core/Events/SpecGenerationStarted.php) | Immediately before a spec is assembled. | `spec`, `environment` |
| [`SpecGenerationCompleted`](../src/Core/Events/SpecGenerationCompleted.php) | After the document (and document transformers) finish. | `spec`, `environment`, `document`, `durationMs` |
| [`RouteSkipped`](../src/Core/Events/RouteSkipped.php) | Once per (route × spec) pair the [`InclusionEvaluator`](../src/Core/Inclusion/InclusionEvaluator.php) excludes. | `route`, `spec`, `reason` (`SkipReason` enum), `summary` |
| [`LintFindingEmitted`](../src/Core/Events/LintFindingEmitted.php) | Whenever a `FindingsCollector` accepts a finding — both during generation and during lint runs. | `finding` |

Register listeners the usual way:

```php
use Illuminate\Support\Facades\Event;
use Radiergummi\OpenApi\Core\Events\SpecGenerationCompleted;

Event::listen(static function (SpecGenerationCompleted $event): void {
    Storage::disk('s3')->put(
        "openapi/{$event->spec}.yaml",
        $event->document->toYaml(),
    );
});
```

`RouteSkipped` fires for every exclusion the inclusion evaluator handles: the
bundled vendor skippers (Telescope, Nova, Ignition, Passport), any
user-configured `RouteFilter`, spec membership decisions, and visibility
attributes. If a route never makes it into your spec, you get an event.
