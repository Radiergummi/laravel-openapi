# ApiResources Plugin Implementation Plan

> **Read first:** `docs/superpowers/plans/plugin-suite-program.md`—the program tracker with shared ground rules, locked cross-cutting decisions, build order, and live status.
>
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Teach the OpenAPI core to document Laravel Eloquent API Resources (`JsonResource` / `ResourceCollection` subclasses) as response schemas, deriving each resource's shape from repeatable class-level `#[ResourceField]` attributes.

**Architecture:** This is build step 2 of 5 in the plugin-suite program (spec: `docs/superpowers/specs/2026-05-18-plugin-suite-design.md`). It depends on build step 1 (plan `2026-05-18-core-phpdoc-generics-and-paginators.md`) being merged—the package now ships a `PrimaryResponseResolver` pipeline. This plan adds the first *plugin* that contributes to it. A resource's `toArray()` shape lives in a method body, which the generator never reads (OAPI-017); the plugin resolves the shape from `#[ResourceField]` attributes instead. The plugin registers a `PrimaryResponseResolver` (the `200 OK` body), a `RefSchemaResolver` (so a resource composes as a `$ref` when nested or named via `#[ResponseResource]`), and three lint rules. `ApiResourcesPlugin` is **default-enabled**—`JsonResource` is Laravel core, no third-party dependency.

**Container-cycle note:** `ResourceRefSchemaResolver` depends on `SchemaFromResource`; `SchemaFromResource` needs the registered `RefSchemaResolver` list to resolve nested non-resource classes. Injecting the full list would form a construction cycle. Resolution mirrors `SchemaFromDataClass`: `SchemaFromResource` recurses **directly** for nested `JsonResource` subclasses (`build()`), and receives a resolver list with `ResourceRefSchemaResolver` **filtered out** for everything else.

**Tech Stack:** PHP 8.4, Laravel 12/13, swagger-php (`OpenApi\Annotations`), Pest + Orchestra Testbench.

---

## Conventions every task must follow

- Every new PHP file starts with `<?php`, a blank line, the MIT/copyright docblock header copied verbatim from any existing `src/` file (the block in `src/Core/Generator/OperationBuilder.php` lines 3-8), a blank line, `declare(strict_types=1);`, a blank line, then the `namespace`. Code blocks below abbreviate it as `// <copyright header>`.
- Run `composer test`, `vendor/bin/pint`, and `composer analyse` before every commit. The suite must be green, Pint must report no violations, and PHPStan (level 8, CI-blocking) must report no errors.
- Commit messages: imperative mood, `feat:` / `test:` / `docs:` prefix, and the trailer `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.
- Work happens on the existing branch `feature/plugin-suite`.
- Lint-rule severity → `level()`: High = `1`, Medium = `2`, Low = `3` (lower level = runs at the default lint level).

## File structure

| File | Responsibility |
|---|---|
| `src/Plugins/ApiResources/Attributes/ResourceField.php` (create) | Repeatable class-level attribute declaring one resource output key. |
| `src/Plugins/ApiResources/ResourceTarget.php` (create) | Value object: the resolved resource class + collection cardinality for an action. |
| `src/Plugins/ApiResources/ResourceClassLocator.php` (create) | Resolves an `ActionDescriptor` to a `ResourceTarget` (or null). Shared by the resolver and the lint rules. |
| `src/Plugins/ApiResources/ResourceEnvelopeFactory.php` (create) | Wraps an item `$ref` in the Laravel `{data}` / `{data, links, meta}` envelope. |
| `src/Plugins/ApiResources/SchemaFromResource.php` (create) | Builds the `OA\Schema` (type: object) for a resource class from its `#[ResourceField]`s. |
| `src/Plugins/ApiResources/ResourceRefSchemaResolver.php` (create) | `RefSchemaResolver` for `JsonResource` subclasses. |
| `src/Plugins/ApiResources/ResourceResponseResolver.php` (create) | `PrimaryResponseResolver` tying detection + envelope together. |
| `src/Plugins/ApiResources/ApiResourcesPlugin.php` (create) | `Plugin`—registers resolvers, payload markers, lint rules. |
| `src/Plugins/ApiResources/Lint/Rules/ResourceFieldsUndeclared.php` (create) | Lint rule `resource.fields-undeclared`. |
| `src/Plugins/ApiResources/Lint/Rules/ResourceFieldTypeMissing.php` (create) | Lint rule `resource.field-type-missing`. |
| `src/Plugins/ApiResources/Lint/Rules/ResourceResponseAmbiguous.php` (create) | Lint rule `resource.response-ambiguous`. |
| `src/OpenApiServiceProvider.php` (modify) | Add `registerApiResourcesPlugin()` with `scoped` bindings. |
| `config/openapi.php` (modify) | Add `ApiResourcesPlugin::class` to `plugins` (enabled). |
| `tests/Fixtures/ApiResources/*` (create) | Fixture resources + controllers. |
| `tests/Support/OperationNodeFactory.php` (create) | Shared test helper: builds a minimal `OperationNode` + `LintContext` for lint-rule tests. |
| `tests/Unit/Plugins/ApiResources/*` (create) | Unit tests for each class. |
| `tests/Feature/Plugins/ApiResources/ApiResourceResponseTest.php` (create) | End-to-end document generation. |
| `../../internal/known-gaps.md`, `CHANGELOG.md`, `docs/usage.md` (modify) | Per-change doc obligations. |

---

## Task 1: `ResourceField` attribute

`#[ResourceField]` is repeatable and class-level on a `JsonResource` subclass. It extends the Core `FieldAttribute` base (which carries the full JSON-Schema field surface and the `descriptor()` mapping), adding only `name` and reusing `type`—when `type` is a class-string it is treated as a nested-schema reference, otherwise a JSON-Schema scalar type.

**Files:**
- Create: `src/Plugins/ApiResources/Attributes/ResourceField.php`
- Test: `tests/Unit/Plugins/ApiResources/Attributes/ResourceFieldTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources\Attributes;

use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

it('exposes its name and forwards schema fields to the descriptor', function (): void {
    $field = new ResourceField('email', type: 'string', format: 'email');

    expect($field->name)->toBe('email')
        ->and($field->type)->toBe('string')
        ->and($field->descriptor()->format)->toBe('email');
});

it('accepts a class-string as the type for a nested schema', function (): void {
    $field = new ResourceField('owner', type: \stdClass::class);

    expect($field->type)->toBe(\stdClass::class);
});

it('is repeatable and targets classes only', function (): void {
    $reflection = new \ReflectionClass(ResourceField::class);
    $attribute = $reflection->getAttributes(\Attribute::class)[0]->newInstance();

    expect($attribute->flags & \Attribute::IS_REPEATABLE)->toBe(\Attribute::IS_REPEATABLE)
        ->and($attribute->flags & \Attribute::TARGET_CLASS)->toBe(\Attribute::TARGET_CLASS);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/Attributes/ResourceFieldTest.php`
Expected: FAIL—`Class "…\ResourceField" not found`.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Attributes;

use Attribute;
use BackedEnum;
use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;

/**
 * Declares one output key of an Eloquent API Resource.
 *
 * Repeatable and class-level: a `JsonResource`'s keys are arbitrary `toArray()`
 * entries, not typed class properties, so each key is declared with its own
 * attribute on the resource class.
 *
 * When `type` is a class-string the field is emitted as a `$ref` to that
 * class's schema (resolved through the registered ref-schema resolvers);
 * otherwise `type` is a JSON-Schema scalar type (`string`, `integer`, …).
 *
 * ```php
 * #[ResourceField('id', type: 'integer')]
 * #[ResourceField('owner', type: CompanyResource::class)]
 * final class ProjectResource extends JsonResource { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ResourceField extends FieldAttribute
{
    /**
     * @param string                           $name        The output key.
     * @param null|class-string|string          $type        A JSON-Schema scalar type, or a class-string for a nested `$ref`.
     * @param bool                             $conditional When true, the key is kept in `properties` but omitted from `required`—for `$this->when()` / `$this->whenLoaded()` fields.
     * @param null|list<BackedEnum|int|string> $enum
     */
    public function __construct(
        public string $name,
        ?string $title = null,
        ?string $description = null,
        mixed $example = null,
        ?string $type = null,
        ?string $format = null,
        ?bool $nullable = null,
        ?array $enum = null,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        ?int $minItems = null,
        ?int $maxItems = null,
        ?bool $uniqueItems = null,
        bool $conditional = false,
    ) {
        parent::__construct(
            title: $title,
            description: $description,
            example: $example,
            type: $type,
            format: $format,
            nullable: $nullable,
            enum: $enum,
            minimum: $minimum,
            maximum: $maximum,
            minLength: $minLength,
            maxLength: $maxLength,
            pattern: $pattern,
            minItems: $minItems,
            maxItems: $maxItems,
            uniqueItems: $uniqueItems,
            conditional: $conditional,
        );
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/Attributes/ResourceFieldTest.php`
Expected: PASS—3 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/ApiResources/Attributes/ResourceField.php tests/Unit/Plugins/ApiResources/Attributes/ResourceFieldTest.php
git commit -m "feat: add ResourceField attribute for ApiResources plugin"
```

---

## Task 2: `ResourceTarget` value object

A tiny immutable value object: the resource class an action returns, and whether the response is a collection. `resourceClass === null` is the **ambiguous** state—the action returns a resource collection type but no `#[ResponseResource]` names the item class.

**Files:**
- Create: `src/Plugins/ApiResources/ResourceTarget.php`
- Test: `tests/Unit/Plugins/ApiResources/ResourceTargetTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Radiergummi\OpenApi\Plugins\ApiResources\ResourceTarget;

it('reports a resolved single-resource target', function (): void {
    $target = new ResourceTarget(\stdClass::class, isCollection: false);

    expect($target->resourceClass)->toBe(\stdClass::class)
        ->and($target->isCollection)->toBeFalse()
        ->and($target->isAmbiguous())->toBeFalse();
});

it('reports an ambiguous target when the resource class is null', function (): void {
    $target = new ResourceTarget(null, isCollection: true);

    expect($target->isAmbiguous())->toBeTrue();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/ResourceTargetTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

/**
 * The resource an action returns: the resource class and the response
 * cardinality. A null `resourceClass` marks an *ambiguous* endpoint—it
 * returns a resource collection type but no `#[ResponseResource]` names the
 * item class, so the shape cannot be derived.
 */
final readonly class ResourceTarget
{
    /**
     * @param null|class-string $resourceClass
     */
    public function __construct(
        public ?string $resourceClass,
        public bool $isCollection,
    ) {}

    public function isAmbiguous(): bool
    {
        return $this->resourceClass === null;
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/ResourceTargetTest.php`
Expected: PASS—2 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/ApiResources/ResourceTarget.php tests/Unit/Plugins/ApiResources/ResourceTargetTest.php
git commit -m "feat: add ResourceTarget value object"
```

---

## Task 3: `ResourceClassLocator`

Resolves an `ActionDescriptor` to a `ResourceTarget`, or `null` when the action is not a resource endpoint at all. Shared by `ResourceResponseResolver` and the lint rules so resolution logic is not duplicated.

Resolution precedence:
1. A `#[ResponseResource]` attribute on the action (method, then class) → `resourceClass` = `attr->class`; `isCollection` = `attr->collection` if non-null, else inferred from the return type.
2. Native return type is a `JsonResource` subclass:
   - a `ResourceCollection` subclass → collection; `resourceClass` is `null` (ambiguous—item class unknown).
   - any other `JsonResource` subclass → single; `resourceClass` is the return type.
3. Otherwise → `null` (not a resource endpoint).

**Files:**
- Create: `src/Plugins/ApiResources/ResourceClassLocator.php`
- Test: `tests/Unit/Plugins/ApiResources/ResourceClassLocatorTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use ReflectionClass;
use ReflectionMethod;

class LocatorFixtureResource extends JsonResource {}
class LocatorFixtureCollection extends ResourceCollection {}

class LocatorFixtureController
{
    public function single(): LocatorFixtureResource { /** @phpstan-ignore-next-line */ return new LocatorFixtureResource(null); }

    public function collectionType(): LocatorFixtureCollection { /** @phpstan-ignore-next-line */ return new LocatorFixtureCollection([]); }

    #[ResponseResource(LocatorFixtureResource::class, collection: true)]
    public function attributed(): LocatorFixtureCollection { /** @phpstan-ignore-next-line */ return new LocatorFixtureCollection([]); }

    public function notAResource(): string { return ''; }
}

function locatorDescriptor(string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new ReflectionClass(LocatorFixtureController::class),
        method: new ReflectionMethod(LocatorFixtureController::class, $method),
        summary: null,
        description: null,
    );
}

it('locates a single resource from the return type', function (): void {
    $target = (new ResourceClassLocator())->locate(locatorDescriptor('single'));

    expect($target?->resourceClass)->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeFalse();
});

it('returns an ambiguous target for a bare collection return type', function (): void {
    $target = (new ResourceClassLocator())->locate(locatorDescriptor('collectionType'));

    expect($target)->not->toBeNull()
        ->and($target?->isAmbiguous())->toBeTrue()
        ->and($target?->isCollection)->toBeTrue();
});

it('resolves the item class from a #[ResponseResource] attribute', function (): void {
    $target = (new ResourceClassLocator())->locate(locatorDescriptor('attributed'));

    expect($target?->resourceClass)->toBe(LocatorFixtureResource::class)
        ->and($target?->isCollection)->toBeTrue();
});

it('returns null when the action does not return a resource', function (): void {
    expect((new ResourceClassLocator())->locate(locatorDescriptor('notAResource')))->toBeNull();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/ResourceClassLocatorTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use ReflectionFunctionAbstract;
use ReflectionNamedType;

use function class_exists;
use function is_a;

/**
 * Resolves the API Resource an action returns. Used by both
 * {@see ResourceResponseResolver} (to build the response) and the ApiResources
 * lint rules (to flag undeclared/ambiguous resources) so resolution is defined
 * exactly once.
 */
final readonly class ResourceClassLocator
{
    public function locate(ActionDescriptor $descriptor): ?ResourceTarget
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return null;
        }

        $returnsCollection = $this->returnTypeIsCollection($reflector);

        $attribute = $this->readResponseResource($descriptor);

        if ($attribute !== null && class_exists($attribute->class)) {
            return new ResourceTarget(
                resourceClass: $attribute->class,
                isCollection: $attribute->collection ?? $returnsCollection,
            );
        }

        $returnType = $reflector->getReturnType();

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $name = $returnType->getName();

        if (!is_a($name, JsonResource::class, allow_string: true)) {
            return null;
        }

        if (is_a($name, ResourceCollection::class, allow_string: true)) {
            // Collection return type with no #[ResponseResource]: the item
            // class is not recoverable from the signature—ambiguous.
            return new ResourceTarget(resourceClass: null, isCollection: true);
        }

        /** @var class-string<JsonResource> $name */
        return new ResourceTarget(resourceClass: $name, isCollection: false);
    }

    private function readResponseResource(ActionDescriptor $descriptor): ?ResponseResource
    {
        $reflector = $descriptor->actionReflector;

        $source = $reflector?->getAttributes(ResponseResource::class)[0] ?? null;

        if ($source === null && $descriptor->controller !== null) {
            $source = $descriptor->controller->getAttributes(ResponseResource::class)[0] ?? null;
        }

        return $source?->newInstance();
    }

    private function returnTypeIsCollection(ReflectionFunctionAbstract $reflector): bool
    {
        $returnType = $reflector->getReturnType();

        return $returnType instanceof ReflectionNamedType
            && !$returnType->isBuiltin()
            && is_a($returnType->getName(), ResourceCollection::class, allow_string: true);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/ResourceClassLocatorTest.php`
Expected: PASS—4 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/ApiResources/ResourceClassLocator.php tests/Unit/Plugins/ApiResources/ResourceClassLocatorTest.php
git commit -m "feat: add ResourceClassLocator for ApiResources plugin"
```

---

## Task 4: `ResourceEnvelopeFactory`

Laravel wraps a `JsonResource` response in a `data` key (`JsonResource::$wrap`). A paginated `ResourceCollection` additionally serializes `links` and `meta`. This factory builds the `OA\Schema` envelope around a resolved item `$ref`.

- **Single:** `{ data: {$ref} }`.
- **Collection:** `{ data: [{$ref}], links: {first,last,prev,next}, meta: {current_page,from,last_page,path,per_page,to,total} }`. This models the paginated-collection shape—the dominant API convention. (A non-paginated collection is just `{data: […]}`; the extra keys are harmless documentation when absent at runtime.)

**Files:**
- Create: `src/Plugins/ApiResources/ResourceEnvelopeFactory.php`
- Test: `tests/Unit/Plugins/ApiResources/ResourceEnvelopeFactoryTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceEnvelopeFactory;

/** @return list<string> */
function envelopePropertyNames(OA\Schema $schema): array
{
    $names = [];

    foreach ($schema->properties as $property) {
        $names[] = $property->property;
    }

    return $names;
}

it('wraps a single resource in a data object', function (): void {
    $schema = (new ResourceEnvelopeFactory())->single('#/components/schemas/Project');

    expect($schema->type)->toBe('object')
        ->and(envelopePropertyNames($schema))->toBe(['data']);

    $data = $schema->properties[0];
    expect($data->ref)->toBe('#/components/schemas/Project');
});

it('wraps a collection in data/links/meta', function (): void {
    $schema = (new ResourceEnvelopeFactory())->collection('#/components/schemas/Project');
    $names = envelopePropertyNames($schema);

    expect($names)->toContain('data')
        ->and($names)->toContain('links')
        ->and($names)->toContain('meta');

    $data = $schema->properties[0];
    expect($data->type)->toBe('array')
        ->and($data->items->ref)->toBe('#/components/schemas/Project');
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/ResourceEnvelopeFactoryTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use OpenApi\Annotations as OA;

/**
 * Builds the `data` / `data+links+meta` envelope Laravel serializes API
 * Resource responses into. The single shape is `{data}`; the collection shape
 * models the paginated `{data, links, meta}` form (the dominant convention).
 */
final class ResourceEnvelopeFactory
{
    public function single(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property(['property' => 'data', 'ref' => $ref]),
            ],
        ]);
    }

    public function collection(string $ref): OA\Schema
    {
        return new OA\Schema([
            'type' => 'object',
            'properties' => [
                new OA\Property([
                    'property' => 'data',
                    'type' => 'array',
                    'items' => new OA\Items(['ref' => $ref]),
                ]),
                new OA\Property([
                    'property' => 'links',
                    'type' => 'object',
                    'properties' => [
                        new OA\Property(['property' => 'first', 'type' => 'string']),
                        new OA\Property(['property' => 'last', 'type' => 'string']),
                        new OA\Property(['property' => 'prev', 'type' => 'string']),
                        new OA\Property(['property' => 'next', 'type' => 'string']),
                    ],
                ]),
                new OA\Property([
                    'property' => 'meta',
                    'type' => 'object',
                    'properties' => [
                        new OA\Property(['property' => 'current_page', 'type' => 'integer']),
                        new OA\Property(['property' => 'from', 'type' => 'integer']),
                        new OA\Property(['property' => 'last_page', 'type' => 'integer']),
                        new OA\Property(['property' => 'path', 'type' => 'string']),
                        new OA\Property(['property' => 'per_page', 'type' => 'integer']),
                        new OA\Property(['property' => 'to', 'type' => 'integer']),
                        new OA\Property(['property' => 'total', 'type' => 'integer']),
                    ],
                ]),
            ],
        ]);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/ResourceEnvelopeFactoryTest.php`
Expected: PASS—2 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/ApiResources/ResourceEnvelopeFactory.php tests/Unit/Plugins/ApiResources/ResourceEnvelopeFactoryTest.php
git commit -m "feat: add ResourceEnvelopeFactory for ApiResources plugin"
```

---

## Task 5: `SchemaFromResource`

Builds the `OA\Schema` (type: object) for a resource class from its `#[ResourceField]` attributes, registers it in `ComponentSchemaRegistry`, and returns the component key. Mirrors `SchemaFromDataClass::build()`—including the in-progress cycle guard.

Per-field logic:
- `type` is a `JsonResource` subclass → recurse `build()` directly, emit `$ref`.
- `type` is any other class-string → resolve via the injected `RefSchemaResolver` list (which excludes `ResourceRefSchemaResolver`—see the container-cycle note). If no resolver claims it → `type: object`.
- `type` is a scalar (or null) → spread `descriptor()->toOpenApi()` onto the property.
- `conditional: false` → field is added to the schema's `required` list.

**Files:**
- Create: `src/Plugins/ApiResources/SchemaFromResource.php`
- Test: `tests/Unit/Plugins/ApiResources/SchemaFromResourceTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\SchemaFromResource;

#[ResourceField('id', type: 'integer')]
#[ResourceField('name', type: 'string')]
#[ResourceField('owner', type: SchemaOwnerResource::class)]
#[ResourceField('avatar', type: 'string', conditional: true)]
class SchemaProjectResource extends JsonResource {}

#[ResourceField('id', type: 'integer')]
class SchemaOwnerResource extends JsonResource {}

it('builds an object schema from #[ResourceField] attributes', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = (new SchemaFromResource($registry, []))->build(SchemaProjectResource::class);

    $schema = null;
    foreach ($registry->all() as $candidate) {
        if ($candidate->schema === $key) {
            $schema = $candidate;
        }
    }

    expect($schema)->toBeInstanceOf(OA\Schema::class)
        ->and($schema->type)->toBe('object');

    $names = array_map(static fn(OA\Property $p): string => $p->property, $schema->properties);
    expect($names)->toContain('id')->toContain('name')->toContain('owner')->toContain('avatar');
});

it('omits conditional fields from required', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = (new SchemaFromResource($registry, []))->build(SchemaProjectResource::class);

    $schema = null;
    foreach ($registry->all() as $candidate) {
        if ($candidate->schema === $key) {
            $schema = $candidate;
        }
    }

    expect($schema->required)->toContain('id')
        ->and($schema->required)->not->toContain('avatar');
});

it('emits a $ref for a nested resource and registers it', function (): void {
    $registry = new ComponentSchemaRegistry();
    (new SchemaFromResource($registry, []))->build(SchemaProjectResource::class);

    $keys = array_map(static fn(OA\Schema $s): string => $s->schema, $registry->all());
    expect($keys)->toContain('SchemaOwnerResource');
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/SchemaFromResourceTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\NullableSchema;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use ReflectionClass;

use function class_exists;
use function is_a;

/**
 * Builds the `OA\Schema` (type: object) for an Eloquent API Resource from its
 * class-level `#[ResourceField]` attributes and registers it as a component.
 *
 * Nested `JsonResource` field types recurse through {@see build()} directly;
 * other class-string field types are resolved via the injected resolver list
 * (which deliberately excludes {@see ResourceRefSchemaResolver} to avoid a
 * container construction cycle—see the plan's architecture note).
 */
final class SchemaFromResource
{
    /**
     * @param list<RefSchemaResolver> $refSchemaResolvers Registered ref resolvers, minus this plugin's own.
     */
    public function __construct(
        private readonly ComponentSchemaRegistry $registry,
        private readonly array $refSchemaResolvers = [],
    ) {}

    /**
     * Registers the resource as a component schema and returns the component key.
     *
     * @param class-string<JsonResource> $resourceClass
     */
    public function build(string $resourceClass): string
    {
        if ($this->registry->isInProgress($resourceClass)) {
            return $this->registry->reserveKey($resourceClass);
        }

        if ($this->registry->isRegisteredOrReserved($resourceClass)) {
            /** @var string $key */
            $key = $this->registry->keyFor($resourceClass);

            return $key;
        }

        $this->registry->markInProgress($resourceClass);

        $schema = $this->buildSchema($resourceClass);

        $this->registry->register($resourceClass, $schema);
        $this->registry->markComplete($resourceClass);

        /** @var string $key */
        $key = $this->registry->keyFor($resourceClass);

        return $key;
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     */
    private function buildSchema(string $resourceClass): OA\Schema
    {
        $reflection = new ReflectionClass($resourceClass);

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        foreach ($reflection->getAttributes(ResourceField::class) as $attribute) {
            $field = $attribute->newInstance();
            $properties[] = $this->buildProperty($field);

            if (!$field->conditional) {
                $required[] = $field->name;
            }
        }

        $props = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $props['required'] = $required;
        }

        return new OA\Schema($props);
    }

    private function buildProperty(ResourceField $field): OA\Property
    {
        $type = $field->type;

        if ($type !== null && class_exists($type)) {
            $ref = $this->resolveClassRef($type);

            $property = $ref !== null
                ? new OA\Property(['property' => $field->name, 'ref' => $ref])
                : new OA\Property(['property' => $field->name, 'type' => 'object']);

            if ($field->description !== null) {
                $property->description = $field->description;
            }

            return $property;
        }

        $props = ['property' => $field->name];

        foreach ($field->descriptor()->toOpenApi() as $key => $value) {
            $props[$key] = $value;
        }

        $property = new OA\Property($props);

        if ($field->nullable === true) {
            NullableSchema::applyTo($property);
        }

        return $property;
    }

    /**
     * @param class-string $class
     */
    private function resolveClassRef(string $class): ?string
    {
        if (is_a($class, JsonResource::class, allow_string: true)) {
            /** @var class-string<JsonResource> $class */
            return $this->registry->qualifyKey($this->build($class));
        }

        foreach ($this->refSchemaResolvers as $resolver) {
            $ref = $resolver->resolveRef($class);

            if ($ref !== null) {
                return $ref;
            }
        }

        return null;
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/SchemaFromResourceTest.php`
Expected: PASS—3 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/ApiResources/SchemaFromResource.php tests/Unit/Plugins/ApiResources/SchemaFromResourceTest.php
git commit -m "feat: add SchemaFromResource schema builder"
```

---

## Task 6: `ResourceRefSchemaResolver`

A thin `RefSchemaResolver`: claims any `JsonResource` subclass and delegates to `SchemaFromResource`. This is what makes a resource compose as a `$ref` when named via `#[ResponseResource]` on a `#[Response]` attribute, or nested inside another resource / a Data class.

**Files:**
- Create: `src/Plugins/ApiResources/ResourceRefSchemaResolver.php`
- Test: `tests/Unit/Plugins/ApiResources/ResourceRefSchemaResolverTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceRefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\SchemaFromResource;

#[ResourceField('id', type: 'integer')]
class RefFixtureResource extends JsonResource {}

function makeResourceRefResolver(): ResourceRefSchemaResolver
{
    $registry = new ComponentSchemaRegistry();

    return new ResourceRefSchemaResolver(new SchemaFromResource($registry, []));
}

it('resolves a JsonResource subclass to a components ref', function (): void {
    expect(makeResourceRefResolver()->resolveRef(RefFixtureResource::class))
        ->toBe('#/components/schemas/RefFixtureResource');
});

it('returns null for a non-resource class', function (): void {
    expect(makeResourceRefResolver()->resolveRef(\stdClass::class))->toBeNull();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/ResourceRefSchemaResolverTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;

use function is_a;

/**
 * Resolves an Eloquent API Resource class to a `#/components/schemas/…` ref,
 * registering its component schema via {@see SchemaFromResource} on first use.
 */
final readonly class ResourceRefSchemaResolver implements RefSchemaResolver
{
    public function __construct(
        private SchemaFromResource $schemaFromResource,
    ) {}

    public function resolveRef(string $class): ?string
    {
        if (!is_a($class, JsonResource::class, allow_string: true)) {
            return null;
        }

        /** @var class-string<JsonResource> $class */
        return '#/components/schemas/' . $this->schemaFromResource->build($class);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/ApiResources/ResourceRefSchemaResolverTest.php`
Expected: PASS—2 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/ApiResources/ResourceRefSchemaResolver.php tests/Unit/Plugins/ApiResources/ResourceRefSchemaResolverTest.php
git commit -m "feat: add ResourceRefSchemaResolver"
```

---

## Task 7: `ResourceResponseResolver`

The `PrimaryResponseResolver`. Locates the `ResourceTarget`; defers (`null`) when the action is not a resource endpoint or is ambiguous; otherwise builds the inner resource `$ref` and wraps it via `ResourceEnvelopeFactory`. Degrades gracefully—catches its own exceptions and returns `null`.

**Resolver ordering (by design—do not change).** Core's `PaginatorResponseResolver` (build step 1) is registered before this resolver. A method whose *native return type* is a paginator (`LengthAwarePaginator`, `Paginator`, `CursorPaginator`) is therefore claimed by the paginator resolver and gets Core's *flat* paginator envelope—even if its item type is an API Resource. This resolver only claims actions whose return type is a `JsonResource` / `ResourceCollection` subclass (a paginator is neither), and emits the `{data}` / `{data, links, meta}` resource envelope. The two envelopes are intentionally different shapes (program decision #5); which one an endpoint gets is decided entirely by its native return type.

**Files:**
- Create: `src/Plugins/ApiResources/ResourceResponseResolver.php`
- Test: covered by the Task 9 feature test (collaborators are integration-shaped).

- [x] **Step 1: Write the implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Throwable;

use function sprintf;

/**
 * Resolves an Eloquent API Resource return type into its `200 OK` response.
 *
 * Defers (returns null) when the action is not a resource endpoint, or when it
 * returns a collection type whose item class is undeclared—the latter is
 * reported by the `resource.response-ambiguous` lint rule.
 */
final readonly class ResourceResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private ResourceClassLocator $locator,
        private SchemaFromResource $schemaFromResource,
        private ResourceEnvelopeFactory $envelopeFactory,
        private LoggerInterface $logger,
    ) {}

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        try {
            $target = $this->locator->locate($descriptor);

            if ($target === null || $target->isAmbiguous()) {
                return null;
            }

            /** @var class-string<\Illuminate\Http\Resources\Json\JsonResource> $resourceClass */
            $resourceClass = $target->resourceClass;
            $ref = '#/components/schemas/' . $this->schemaFromResource->build($resourceClass);

            $envelope = $target->isCollection
                ? $this->envelopeFactory->collection($ref)
                : $this->envelopeFactory->single($ref);

            return new OA\Response([
                'response' => '200',
                'description' => 'OK',
                'content' => [MediaType::Json->schema($envelope)],
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning(sprintf(
                'ResourceResponseResolver failed for route %s: %s',
                $descriptor->route->uri(),
                $exception->getMessage(),
            ));

            return null;
        }
    }
}
```

- [x] **Step 2: Run lint + analyse to confirm no regressions**

Run: `vendor/bin/pint && composer analyse`
Expected: Pint clean; PHPStan clean (the resolver is not yet registered, so behaviour is unchanged).

- [x] **Step 3: Commit**

```bash
git add src/Plugins/ApiResources/ResourceResponseResolver.php
git commit -m "feat: add ResourceResponseResolver primary-response resolver"
```

---

## Task 8: `ApiResourcesPlugin` + service-provider wiring + config

Register the plugin's resolvers, bind its services, and enable it by default. The plugin's `register()` method is created here registering only the two resolvers—the three lint rules are added in Task 13, *after* their classes exist. Registering them here would reference not-yet-created classes and break `composer analyse` (PHPStan would error on the unknown class-strings); deferring keeps both `composer test` and `composer analyse` green throughout the plan.

**Files:**
- Create: `src/Plugins/ApiResources/ApiResourcesPlugin.php`
- Modify: `src/OpenApiServiceProvider.php`
- Modify: `config/openapi.php`

- [x] **Step 1: Write `ApiResourcesPlugin`**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Registry\Plugin;

/**
 * Teaches the OpenAPI core to document Eloquent API Resources
 * (`JsonResource` / `ResourceCollection` subclasses) as response schemas.
 */
final class ApiResourcesPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRefSchemaResolver(ResourceRefSchemaResolver::class);
        $registry->addPrimaryResponseResolver(ResourceResponseResolver::class);
        // The three lint rules are added in Task 13, once their classes exist.
    }
}
```

- [x] **Step 2: Add `registerApiResourcesPlugin()` to `OpenApiServiceProvider`**

In `src/OpenApiServiceProvider.php`, add the call inside `register()` after `registerSpatieDataPlugin();`:

```php
        $this->registerSpatieDataPlugin();
        $this->registerApiResourcesPlugin();
        $this->registerGenerator();
```

Add this method after `registerSpatieDataPlugin()`. Note `SchemaFromResource` receives the registry's ref resolvers **minus** `ResourceRefSchemaResolver`—this breaks the construction cycle:

```php
    /**
     * Binds the ApiResources plugin services.
     *
     * `SchemaFromResource` receives every ref resolver except this plugin's own
     * `ResourceRefSchemaResolver`: it handles nested resources by direct
     * recursion, and injecting its own resolver would form a construction cycle.
     */
    private function registerApiResourcesPlugin(): void
    {
        $this->app->scoped(
            Plugins\ApiResources\SchemaFromResource::class,
            static function (Container $app): Plugins\ApiResources\SchemaFromResource {
                $registry = $app->make(OpenApiRegistry::class);

                $resolvers = [];

                foreach ($registry->refSchemaResolvers() as $class) {
                    if ($class === Plugins\ApiResources\ResourceRefSchemaResolver::class) {
                        continue;
                    }

                    $resolvers[] = $app->make($class);
                }

                return new Plugins\ApiResources\SchemaFromResource(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    refSchemaResolvers: $resolvers,
                );
            },
        );

        $this->app->scoped(
            Plugins\ApiResources\ResourceRefSchemaResolver::class,
            static fn(Container $app) => new Plugins\ApiResources\ResourceRefSchemaResolver(
                schemaFromResource: $app->make(Plugins\ApiResources\SchemaFromResource::class),
            ),
        );

        $this->app->scoped(
            Plugins\ApiResources\ResourceResponseResolver::class,
            static fn(Container $app) => new Plugins\ApiResources\ResourceResponseResolver(
                locator: $app->make(Plugins\ApiResources\ResourceClassLocator::class),
                schemaFromResource: $app->make(Plugins\ApiResources\SchemaFromResource::class),
                envelopeFactory: $app->make(Plugins\ApiResources\ResourceEnvelopeFactory::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }
```

`ResourceClassLocator` and `ResourceEnvelopeFactory` have no constructor dependencies—Laravel autowires them; no explicit binding is needed. `ApiResourcesPlugin` itself is autowired when the registry instantiates it.

- [x] **Step 3: Enable the plugin in `config/openapi.php`**

Add the import near the existing `use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;` line:

```php
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
```

Change the `plugins` array from:

```php
    'plugins' => [
        SpatieDataPlugin::class,
    ],
```

to:

```php
    'plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,
    ],
```

- [x] **Step 4: Run lint + analyse**

Run: `vendor/bin/pint && composer analyse && composer test`
Expected: Pint clean; PHPStan clean; `composer test` green. The plugin registers only its two resolvers, so nothing references a not-yet-created class—analyse and the suite both stay green.

- [x] **Step 5: Commit**

```bash
git add src/Plugins/ApiResources/ApiResourcesPlugin.php src/OpenApiServiceProvider.php config/openapi.php
git commit -m "feat: register and wire the ApiResources plugin"
```

---

## Task 9: Feature test—API Resource endpoints end-to-end

Mirrors `tests/Feature/Plugins/SpatieData/` structure: fixture resources + controllers, routes registered per test, the document generated via `app(OpenApiGenerator::class)->generate()->toYaml()` and parsed with `Yaml::parse()`.

**Files:**
- Create: `tests/Feature/Plugins/ApiResources/ApiResourceResponseTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi', 'plugin:api-resources');

#[ResourceField('id', type: 'integer')]
#[ResourceField('name', type: 'string')]
class WidgetResource extends JsonResource {}

class WidgetCollection extends ResourceCollection {}

class WidgetResourceController extends Controller
{
    /** Show a widget. */
    public function show(): WidgetResource
    {
        /** @phpstan-ignore-next-line */
        return new WidgetResource(null);
    }

    /** List widgets—item class declared by attribute. */
    #[ResponseResource(WidgetResource::class, collection: true)]
    public function index(): WidgetCollection
    {
        /** @phpstan-ignore-next-line */
        return new WidgetCollection([]);
    }

    /** List widgets—item class undeclared. */
    public function ambiguous(): WidgetCollection
    {
        /** @phpstan-ignore-next-line */
        return new WidgetCollection([]);
    }
}

it('documents a single resource wrapped in a data object', function (): void {
    Route::get('/widgets/{widget}', [WidgetResourceController::class, 'show']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/widgets/{widget}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('data');
});

it('documents a resource collection with data/links/meta', function (): void {
    Route::get('/widgets', [WidgetResourceController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/widgets']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['type'])->toBe('array');
});

it('registers the resource as a reusable component schema', function (): void {
    Route::get('/widgets/{widget}', [WidgetResourceController::class, 'show']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    expect($spec['components']['schemas'] ?? [])->toHaveKey('WidgetResource');
});

it('falls back to a bare 200 for an ambiguous collection endpoint', function (): void {
    Route::get('/widgets-ambiguous', [WidgetResourceController::class, 'ambiguous']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $response = $spec['paths']['/widgets-ambiguous']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});
```

- [x] **Step 2: Run the test**

Run: `vendor/bin/pest tests/Feature/Plugins/ApiResources/ApiResourceResponseTest.php`
Expected: PASS—4 tests. (Generation does not instantiate lint rules, so this test stands alone—it does not depend on Tasks 10–13.)

- [x] **Step 3: Commit**

```bash
vendor/bin/pint
git add tests/Feature/Plugins/ApiResources/ApiResourceResponseTest.php
git commit -m "test: cover API Resource response resolution end-to-end"
```

---

## Task 10: Lint rule `resource.fields-undeclared`

High severity (`level: 1`). Flags an operation that resolves to a resource response whose resource class carries **zero** `#[ResourceField]` attributes—the shape is unknown, producing an empty schema. Uses `ResourceClassLocator` for resolution.

**Detection approach (all three rules).** These rules re-resolve the resource from the operation's `ActionDescriptor` via `ResourceClassLocator` (reflection on the return type + `#[ResponseResource]`), rather than inspecting the produced `200` response in the spec tree. This mirrors existing descriptor-based core rules (e.g. `OperationNode::hasPublicEndpointAttribute()`). Consequence to accept: the rules key off the *signature*, so an endpoint that documents its response another way (e.g. an explicit `#[Response]` attribute) could still be flagged. This is consistent with the no-method-body-inference rule and the conservative-detection program decisions; it is a deliberate trade-off, not an oversight.

**Files:**
- Create: `src/Plugins/ApiResources/Lint/Rules/ResourceFieldsUndeclared.php`
- Test: `tests/Feature/Plugins/ApiResources/Lint/ResourceFieldsUndeclaredTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources\Lint;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceFieldsUndeclared;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:api-resources');

class BareLintResource extends JsonResource {}

class BareLintController
{
    public function show(): BareLintResource { /** @phpstan-ignore-next-line */ return new BareLintResource(null); }
}

it('flags a resource response whose class declares no #[ResourceField]', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/bare', []),
        controller: new \ReflectionClass(BareLintController::class),
        method: new \ReflectionMethod(BareLintController::class, 'show'),
        summary: null,
        description: null,
    );

    $rule = new ResourceFieldsUndeclared(new ResourceClassLocator());
    $node = OperationNodeFactory::forDescriptor($descriptor);

    $findings = iterator_to_array($rule->checkOperation($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.fields-undeclared');
});
```

> **Shared test helper—create `tests/Support/OperationNodeFactory.php` first.** The
> three lint-rule tests (Tasks 10–12) all need a minimal `OperationNode` carrying an
> `ActionDescriptor`, plus a `LintContext` to pass to `checkOperation()`. `OperationNode`
> has **14 required constructor parameters** and `LintContext` has **5**—neither can be
> default-constructed. The plugin lint rules read only `$operation->descriptor` (and
> `webhook` / `method` / `pathUri`); they never touch the `LintContext`, so the context is
> a minimal valid stub. `OA\Operation` is **abstract**—`raw` must be a concrete subclass
> (`OA\Get`). Autoload-dev maps `Radiergummi\OpenApi\Tests\` → `tests/`, so no
> `composer dump-autoload` is needed. Create the file (with the standard copyright header):
>
> ```php
> <?php
>
> // <copyright header>
>
> declare(strict_types=1);
>
> namespace Radiergummi\OpenApi\Tests\Support;
>
> use OpenApi\Annotations as OA;
> use OpenApi\Context;
> use Radiergummi\OpenApi\Core\Lint\LintContext;
> use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
> use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
> use Radiergummi\OpenApi\Core\Lint\TreeIndex;
> use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
>
> /**
>  * Builds minimal lint-tree fixtures for exercising `OperationRule` lint rules
>  * in isolation. Shared across the plugin-suite lint-rule tests.
>  */
> final class OperationNodeFactory
> {
>     /**
>      * A minimal `OperationNode` carrying `$descriptor`. Every document-shaped
>      * field is empty: the plugin lint rules resolve everything from the
>      * descriptor's reflection, not from the produced operation.
>      */
>     public static function forDescriptor(ActionDescriptor $descriptor): OperationNode
>     {
>         return new OperationNode(
>             pathUri: $descriptor->route->uri(),
>             method: 'GET',
>             operationId: null,
>             summary: null,
>             description: null,
>             deprecated: false,
>             parameters: [],
>             queryParameters: [],
>             requestBody: null,
>             responses: [],
>             security: [],
>             tags: [],
>             descriptor: $descriptor,
>             raw: new OA\Get(['_context' => new Context()]),
>             webhook: false,
>         );
>     }
>
>     /**
>      * A minimal valid `LintContext`. The plugin lint rules never read it, but
>      * `checkOperation()` requires a non-null instance.
>      */
>     public static function emptyContext(): LintContext
>     {
>         $spec = new OA\OpenApi(['openapi' => '3.1.0']);
>
>         return new LintContext(
>             api: new ApiNode(
>                 operations: [],
>                 components: [],
>                 webhooks: [],
>                 declaredTags: [],
>                 tagDescriptions: [],
>                 raw: $spec,
>             ),
>             index: TreeIndex::empty(),
>             rawSpec: $spec,
>             actionDescriptors: [],
>             suppressions: [],
>         );
>     }
> }
> ```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Plugins/ApiResources/Lint/ResourceFieldsUndeclaredTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use ReflectionClass;

use function sprintf;

/**
 * Flags an operation whose resource response class declares no
 * `#[ResourceField]`—the response shape is unknown, yielding an empty schema.
 */
final readonly class ResourceFieldsUndeclared implements Rule, OperationRule
{
    public function __construct(
        private ResourceClassLocator $locator,
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook || $operation->descriptor === null) {
            return;
        }

        $target = $this->locator->locate($operation->descriptor);

        if ($target === null || $target->isAmbiguous()) {
            return;
        }

        /** @var class-string $resourceClass */
        $resourceClass = $target->resourceClass;

        if ((new ReflectionClass($resourceClass))->getAttributes(ResourceField::class) !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s returns %s but it declares no #[ResourceField]—the response schema is empty',
                $operation->method,
                $operation->pathUri,
                $resourceClass,
            ),
            fixHint: 'Declare each output key with a class-level #[ResourceField] on the resource.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'resource.fields-undeclared';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'An API Resource used as a response declares no #[ResourceField] attributes.';
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Plugins/ApiResources/Lint/ResourceFieldsUndeclaredTest.php`
Expected: PASS—1 test.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/ApiResources/Lint/Rules/ResourceFieldsUndeclared.php tests/Feature/Plugins/ApiResources/Lint/ResourceFieldsUndeclaredTest.php tests/Support/OperationNodeFactory.php
git commit -m "feat: add resource.fields-undeclared lint rule"
```

---

## Task 11: Lint rule `resource.field-type-missing`

Medium severity (`level: 2`). Flags any `#[ResourceField]` whose `type` is `null`—the field's schema cannot be derived. Walks every `#[ResourceField]` on the resource class behind a resource response.

**Files:**
- Create: `src/Plugins/ApiResources/Lint/Rules/ResourceFieldTypeMissing.php`
- Test: `tests/Feature/Plugins/ApiResources/Lint/ResourceFieldTypeMissingTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources\Lint;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceFieldTypeMissing;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:api-resources');

#[ResourceField('id', type: 'integer')]
#[ResourceField('mystery')]
class TypelessFieldResource extends JsonResource {}

class TypelessFieldController
{
    public function show(): TypelessFieldResource { /** @phpstan-ignore-next-line */ return new TypelessFieldResource(null); }
}

it('flags a #[ResourceField] with no type', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/typeless', []),
        controller: new \ReflectionClass(TypelessFieldController::class),
        method: new \ReflectionMethod(TypelessFieldController::class, 'show'),
        summary: null,
        description: null,
    );

    $rule = new ResourceFieldTypeMissing(new ResourceClassLocator());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.field-type-missing')
        ->and($findings[0]->message)->toContain('mystery');
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Plugins/ApiResources/Lint/ResourceFieldTypeMissingTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use ReflectionClass;

use function sprintf;

/**
 * Flags a `#[ResourceField]` declared with no `type`—its schema cannot be
 * derived, so the field is emitted untyped.
 */
final readonly class ResourceFieldTypeMissing implements Rule, OperationRule
{
    public function __construct(
        private ResourceClassLocator $locator,
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook || $operation->descriptor === null) {
            return;
        }

        $target = $this->locator->locate($operation->descriptor);

        if ($target === null || $target->isAmbiguous()) {
            return;
        }

        /** @var class-string $resourceClass */
        $resourceClass = $target->resourceClass;

        foreach ((new ReflectionClass($resourceClass))->getAttributes(ResourceField::class) as $attribute) {
            $field = $attribute->newInstance();

            if ($field->type !== null) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '#[ResourceField(\'%s\')] on %s has no type—the field is emitted untyped',
                    $field->name,
                    $resourceClass,
                ),
                fixHint: 'Add a type: a JSON-Schema scalar (\'string\', \'integer\', …) or a class-string for a nested $ref.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'resource.field-type-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A #[ResourceField] is declared without a resolvable type.';
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Plugins/ApiResources/Lint/ResourceFieldTypeMissingTest.php`
Expected: PASS—1 test.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/ApiResources/Lint/Rules/ResourceFieldTypeMissing.php tests/Feature/Plugins/ApiResources/Lint/ResourceFieldTypeMissingTest.php
git commit -m "feat: add resource.field-type-missing lint rule"
```

---

## Task 12: Lint rule `resource.response-ambiguous`

High severity (`level: 1`). Flags an operation whose `ResourceTarget` is **ambiguous**—a resource collection return type with no `#[ResponseResource]` naming the item class.

**Files:**
- Create: `src/Plugins/ApiResources/Lint/Rules/ResourceResponseAmbiguous.php`
- Test: `tests/Feature/Plugins/ApiResources/Lint/ResourceResponseAmbiguousTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources\Lint;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceResponseAmbiguous;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:api-resources');

class AmbiguousLintCollection extends ResourceCollection {}

class AmbiguousLintController
{
    public function index(): AmbiguousLintCollection { /** @phpstan-ignore-next-line */ return new AmbiguousLintCollection([]); }
}

it('flags a collection return type with no #[ResponseResource]', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/ambiguous', []),
        controller: new \ReflectionClass(AmbiguousLintController::class),
        method: new \ReflectionMethod(AmbiguousLintController::class, 'index'),
        summary: null,
        description: null,
    );

    $rule = new ResourceResponseAmbiguous(new ResourceClassLocator());
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        OperationNodeFactory::emptyContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.response-ambiguous');
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Plugins/ApiResources/Lint/ResourceResponseAmbiguousTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;

use function sprintf;

/**
 * Flags an operation that returns a resource collection type without a
 * `#[ResponseResource]` naming the item class—the response shape cannot be
 * derived and the endpoint falls back to a bare `200 OK`.
 */
final readonly class ResourceResponseAmbiguous implements Rule, OperationRule
{
    public function __construct(
        private ResourceClassLocator $locator,
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook || $operation->descriptor === null) {
            return;
        }

        $target = $this->locator->locate($operation->descriptor);

        if ($target === null || !$target->isAmbiguous()) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s returns a resource collection but no #[ResponseResource] names the item class',
                $operation->method,
                $operation->pathUri,
            ),
            fixHint: 'Add #[ResponseResource(SomeResource::class, collection: true)] to the action.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'resource.response-ambiguous';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A resource collection response has no #[ResponseResource] naming its item class.';
    }
}
```

- [x] **Step 4: Run the full suite, lint, and analysis**

Run: `vendor/bin/pest tests/Feature/Plugins/ApiResources/Lint/ResourceResponseAmbiguousTest.php && composer test && vendor/bin/pint --test && composer analyse`
Expected: the new test passes; the full suite is green; Pint clean; PHPStan clean.

- [x] **Step 5: Commit**

```bash
git add src/Plugins/ApiResources/Lint/Rules/ResourceResponseAmbiguous.php tests/Feature/Plugins/ApiResources/Lint/ResourceResponseAmbiguousTest.php
git commit -m "feat: add resource.response-ambiguous lint rule"
```

---

## Task 13: Register the lint rules in the plugin

The three lint-rule classes now exist (Tasks 10–12), so they can be wired into
`ApiResourcesPlugin` without breaking `composer analyse`. This single change is
what makes the rules active—the rules registered with `OpenApiRegistry` are
instantiated by `RuleRegistry` and run by `openapi:lint`.

**Files:**
- Modify: `src/Plugins/ApiResources/ApiResourcesPlugin.php`

- [x] **Step 1: Add the three rule registrations**

Merge these imports into the existing `use` block (Pint enforces alphabetical
ordering—run `vendor/bin/pint` before committing):

```php
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceFieldsUndeclared;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceFieldTypeMissing;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceResponseAmbiguous;
```

and extend `register()` to its final form:

```php
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRefSchemaResolver(ResourceRefSchemaResolver::class);
        $registry->addPrimaryResponseResolver(ResourceResponseResolver::class);
        $registry->addRule(ResourceFieldsUndeclared::class);
        $registry->addRule(ResourceFieldTypeMissing::class);
        $registry->addRule(ResourceResponseAmbiguous::class);
    }
```

- [x] **Step 2: Run the full suite, lint, and analysis**

Run: `composer test && vendor/bin/pint --test && composer analyse`
Expected: suite green; Pint clean; PHPStan clean. The three rules are now active.

- [x] **Step 3: Commit**

```bash
vendor/bin/pint
git add src/Plugins/ApiResources/ApiResourcesPlugin.php
git commit -m "feat: register ApiResources lint rules in the plugin"
```

---

## Task 14: Documentation

**Files:**
- Modify: `CHANGELOG.md`, `docs/usage.md`, `../../internal/known-gaps.md`

- [x] **Step 1: Add a `CHANGELOG.md` entry**

Under the existing `## [Unreleased]` → `### Added` list, append:

```markdown
- Eloquent API Resources (`JsonResource` / `ResourceCollection`) are now
  documented automatically via the default-enabled `ApiResourcesPlugin`. Each
  resource declares its output keys with repeatable class-level
  `#[ResourceField]` attributes; single responses emit the `{data}` envelope and
  collections the `{data, links, meta}` envelope. Three lint rules
  (`resource.fields-undeclared`, `resource.field-type-missing`,
  `resource.response-ambiguous`) report incomplete declarations.
```

- [x] **Step 2: Update `docs/usage.md`**

Find the section that documents response handling / the SpatieData plugin and add a short subsection: how to enable the plugin (it is on by default), how to declare `#[ResourceField]` keys, the nested class-string shorthand, and that collection endpoints need `#[ResponseResource(..., collection: true)]` when the return type is a generic collection. Keep it to the minimal observable-behaviour description CLAUDE.md mandates—the full prose pass is a separate workstream.

- [x] **Step 3: Update `../../internal/known-gaps.md`**

In the OAPI-017 section, note that API Resource response shapes are derived from `#[ResourceField]` attributes rather than the resource's `toArray()` body, consistent with the no-method-body-inference rule.

- [x] **Step 4: Run the full suite once more**

Run: `vendor/bin/pint && composer test && composer analyse`
Expected: Pint clean; suite green; PHPStan clean.

- [x] **Step 5: Commit**

```bash
git add CHANGELOG.md docs/usage.md docs/known-gaps.md
git commit -m "docs: document the ApiResources plugin"
```

---

## Self-Review

**Spec coverage:** `ApiResourcesPlugin` under `src/Plugins/ApiResources/` (Task 8); `#[ResourceField]` repeatable class-level attribute (Task 1); consumes the existing Core `#[ResponseResource]` with no new method-level attribute (Task 3 `ResourceClassLocator`); nested-object class-string shorthand resolved through ref resolvers (Task 5); primary-response resolver (Task 7), ref-schema resolver (Task 6); the three spec lint rules with the spec's IDs and severities (Tasks 10–12), wired into the plugin in Task 13; default-enabled in config (Task 8); unit + feature tests mirroring the SpatieData coverage pattern (every task); `CHANGELOG.md` + `docs/usage.md` per-change updates (Task 14).

**Type consistency:** `ResourceTarget(?string $resourceClass, bool $isCollection)` / `isAmbiguous()` are used identically across Tasks 2, 3, 7, 10–12. `SchemaFromResource::build(): string` returns a bare component key; callers prepend `#/components/schemas/` (Tasks 6, 7)—the registry's `qualifyKey()` is used inside `SchemaFromResource` for nested refs. `ResourceClassLocator::locate(): ?ResourceTarget` signature is constant across all consumers.

**Done criteria:**
- `composer test` is green with the new unit + feature tests.
- `vendor/bin/pint` reports no violations; `composer analyse` reports no errors (PHPStan level 8).
- A controller returning a `JsonResource` with `#[ResourceField]`s produces a `{data}` response; a `#[ResponseResource(..., collection: true)]` collection produces `{data, links, meta}`; an ambiguous collection falls back to a bare `200` and is flagged by `resource.response-ambiguous`.

## Next plans in this program

3. `QueryBuilderPlugin`—`docs/superpowers/plans/2026-05-19-querybuilder-plugin.md`.
4. `FractalPlugin`—`docs/superpowers/plans/2026-05-19-fractal-plugin.md`.
5. composer.json + config-defaults wiring—`docs/superpowers/plans/2026-05-19-plugin-suite-wiring.md`.
