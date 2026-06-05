# Extensions

`OpenApiExtensions` exposes three hook points for behaviour that authoring
attributes can't express. Register them from a service provider's `boot()`:

```php

```

> [!TIP]
> Extensions are for project-specific behaviour. For logic that applies to
> every consumer of a third-party package, write a [plugin](plugin-authoring.md).

## Operation transformer

Runs once per assembled operation, after all attributes and extractors:

```php
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Extensions\OperationContext;

OpenApiExtensions::transformOperation(
    static function (OA\Operation $operation, OperationContext $context): void {
        if (str_contains($context->routeUri, 'webhooks/stripe')) {
            $operation->tags = ['Stripe'];
        }
    },
);
```

`OperationContext` exposes:

- `$descriptor`: the full `ActionDescriptor`
- `$httpMethod`
- `$controllerClass`, `$methodName`, `$routeUri`

## Schema transformer

Runs once per component schema. The primary escape hatch for custom `Rule`
objects the validation-rule mapper doesn't recognise.

```php
use Radiergummi\OpenApi\Extensions\SchemaContext;

OpenApiExtensions::transformSchema(
    static function (OA\Schema $schema, SchemaContext $context): void {
        if ($context->sourceClass === null) {
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

- `$componentKey`: the `components.schemas` key
- `$sourceClass`: the PHP class the schema was derived from (`null` for hand-built schemas)

## Document transformer

Runs once on the fully assembled `OA\OpenApi` document before it is returned:

```php
OpenApiExtensions::transformDocument(
    static function (OA\OpenApi $document): void {
        $document->{'x-api-version'} = config('app.version');
    },
);
```

## Flushing (tests)

`OpenApiExtensions::flush()` removes all registered transformers. Call it in
`afterEach()` when testing code that registers them:

```php
afterEach(function (): void {
    OpenApiExtensions::flush();
});
```

## Events

The generator and linter dispatch standard Laravel events for observability,
not mutation. Use a transformer to modify the spec; use an event listener to
log, export, or notify. Four events ship:

| Event | When | Carries |
|---|---|---|
| [`SpecGenerationStarted`](../src/Events/SpecGenerationStarted.php) | Before a spec is assembled. | `spec`, `environment` |
| [`SpecGenerationCompleted`](../src/Events/SpecGenerationCompleted.php) | After document transformers finish. | `spec`, `environment`, `document`, `durationMs` |
| [`RouteSkipped`](../src/Events/RouteSkipped.php) | Once per (route × spec) pair excluded. | `route`, `spec`, `reason` (`SkipReason` enum), `summary` |
| [`LintFindingEmitted`](../src/Events/LintFindingEmitted.php) | Whenever a finding is accepted (during generation or lint runs). | `finding` |

Register listeners the usual way:

```php
use Illuminate\Support\Facades\Event;
use Radiergummi\OpenApi\Events\SpecGenerationCompleted;

Event::listen(static function (SpecGenerationCompleted $event): void {
    Storage::disk('s3')->put(
        "openapi/{$event->spec}.yaml",
        $event->document->toYaml(),
    );
});
```

`RouteSkipped` fires for every exclusion: bundled vendor skippers (Telescope,
Nova, Ignition, Passport, Horizon, Pulse, Cashier, broadcasting channel-auth),
user-configured `RouteFilter`s, spec membership decisions, and visibility
attributes. Any excluded route emits an event.
