# Core PHPDoc Generics & Paginator Response Resolver—Implementation Plan

> **Read first:** `docs/superpowers/plans/plugin-suite-program.md`—the program tracker with shared ground rules, locked cross-cutting decisions, build order, and live status.
>
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Teach the OpenAPI core to document Laravel paginator return types (`LengthAwarePaginator`, `Paginator`, `CursorPaginator`) as the flat schema Laravel actually serializes, resolving the paginated item type from a `#[ResponseResource]` attribute or a `@return Paginator<Item>` PHPDoc generic.

**Architecture:** This is build step 1 of 5 in the plugin-suite program (spec: `docs/superpowers/specs/2026-05-18-plugin-suite-design.md`). The package ships **no** `PrimaryResponseResolver` today—routes without a `#[Response]` attribute get a bare `200 OK`. This plan adds the **first** such resolver. It is Core-only; no plugin, no third-party dependency. Four new classes plus three small modifications. Each is consulted by `OperationBuilder` in registration order, first non-null wins; the resolver degrades gracefully (catches its own exceptions, returns `null`).

**Tech Stack:** PHP 8.4, Laravel 12/13, swagger-php (`OpenApi\Annotations`), `phpdocumentor/reflection-docblock` (already a dependency—see `ThrowsExtractor`), Pest + Orchestra Testbench.

---

## Conventions every task must follow

- Every new PHP file starts with `<?php`, a blank line, the MIT/copyright docblock header copied verbatim from any existing `src/` file (the block in `src/Core/Generator/OperationBuilder.php` lines 3-8), a blank line, `declare(strict_types=1);`, a blank line, then the `namespace`. (219 of 225 `src/` files carry this header—it is the convention.)
- Run `composer test` (full Pest suite) and `vendor/bin/pint` before every commit. Pint must report no violations; the suite must be green.
- Commit messages: imperative mood, `feat:` / `test:` / `docs:` prefix, and the trailer `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.
- Work happens on the existing branch `feature/plugin-suite`.

## File structure

| File | Responsibility |
|---|---|
| `src/Core/Enums/PaginatorKind.php` (create) | Enum of the three paginator shapes + `fromClass()` detection. |
| `src/Core/Routing/ReturnTypeExtractor.php` (create) | Extracts the single generic argument of a `@return` PHPDoc tag as an FQCN. |
| `src/Core/Generator/PaginatorSchemaFactory.php` (create) | Builds the flat `OA\Schema` envelope for a given `PaginatorKind` and item schema. |
| `src/Core/Extraction/PaginatorResponseResolver.php` (create) | `PrimaryResponseResolver` tying detection + item resolution + envelope together. |
| `src/Core/Registry/CoreRegistration.php` (modify) | Register `PaginatorResponseResolver` as a primary-response resolver. |
| `src/OpenApiServiceProvider.php` (modify) | Bind `PaginatorResponseResolver` as a `scoped` singleton (it needs the ref-resolver list). |
| `../../../tests/Unit/Enums/PaginatorKindTest.php` (create) | Unit tests for `PaginatorKind`. |
| `tests/Unit/Core/Routing/ReturnTypeExtractorTest.php` (create) | Unit tests for `ReturnTypeExtractor`. |
| `tests/Unit/Core/Generator/PaginatorSchemaFactoryTest.php` (create) | Unit tests for `PaginatorSchemaFactory`. |
| `tests/Feature/PaginatorResponseTest.php` (create) | End-to-end: generate a document from paginator-returning fixture controllers. |
| `../../internal/known-gaps.md` (modify) | Note the narrowed OAPI-017 surface. |
| `CHANGELOG.md` (modify) | Add an `[Unreleased]` entry. |

---

## Task 1: `PaginatorKind` enum

**Files:**
- Create: `src/Core/Enums/PaginatorKind.php`
- Test: `../../../tests/Unit/Enums/PaginatorKindTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Enums;

use Illuminate\Pagination\CursorPaginator;use Illuminate\Pagination\LengthAwarePaginator;use Illuminate\Pagination\Paginator;use Radiergummi\OpenApi\Enums\PaginatorKind;

it('detects a length-aware paginator', function (): void {
    expect(PaginatorKind::fromClass(LengthAwarePaginator::class))
        ->toBe(PaginatorKind::LengthAware);
});

it('detects a simple paginator', function (): void {
    expect(PaginatorKind::fromClass(Paginator::class))
        ->toBe(PaginatorKind::Simple);
});

it('detects a cursor paginator', function (): void {
    expect(PaginatorKind::fromClass(CursorPaginator::class))
        ->toBe(PaginatorKind::Cursor);
});

it('returns null for a non-paginator class', function (): void {
    expect(PaginatorKind::fromClass(\stdClass::class))->toBeNull();
});

it('returns null for a class that does not exist', function (): void {
    expect(PaginatorKind::fromClass('Not\\A\\Real\\Class'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Core/Enums/PaginatorKindTest.php`
Expected: FAIL—`Class "Radiergummi\OpenApi\Core\Enums\PaginatorKind" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

// <copyright header verbatim from an existing src/ file>

namespace Radiergummi\OpenApi\Core\Enums;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;

use function is_a;

/**
 * The three Laravel paginator shapes, distinguished by the metadata each
 * serializes via its `toArray()` method.
 */
enum PaginatorKind
{
    case LengthAware;
    case Simple;
    case Cursor;

    /**
     * Maps a class name to its paginator kind, or null when the class is not a
     * paginator. Order matters: `LengthAwarePaginator` extends `Paginator`, so
     * the more specific contract must be tested first.
     */
    public static function fromClass(string $class): ?self
    {
        return match (true) {
            is_a($class, CursorPaginator::class, allow_string: true) => self::Cursor,
            is_a($class, LengthAwarePaginator::class, allow_string: true) => self::LengthAware,
            is_a($class, Paginator::class, allow_string: true) => self::Simple,
            default => null,
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Core/Enums/PaginatorKindTest.php`
Expected: PASS—5 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Core/Enums/PaginatorKind.php tests/Unit/Core/Enums/PaginatorKindTest.php
git commit -m "feat: add PaginatorKind enum for paginator detection"
```

---

## Task 2: `ReturnTypeExtractor`

Extracts the single generic argument of an action's `@return` PHPDoc tag, resolved to an FQCN. `phpdocumentor/reflection-docblock` parses `Foo<Bar>` into a `phpDocumentor\Reflection\Types\Collection` whose `getValueType()` is the inner type. Namespace resolution reuses the same `ContextFactory` pattern as `ThrowsExtractor` (`src/Core/Routing/ThrowsExtractor.php`).

**Files:**
- Create: `src/Core/Routing/ReturnTypeExtractor.php`
- Test: `tests/Unit/Core/Routing/ReturnTypeExtractorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Routing;

use Illuminate\Pagination\LengthAwarePaginator;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\ContextFactory;
use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;
use ReflectionMethod;

/**
 * Fixture: its @return tags exercise the extractor. The `use` import above
 * gives the docblock context a way to resolve the short name.
 */
class ReturnTypeExtractorFixture
{
    /** @return LengthAwarePaginator<\stdClass> */
    public function generic(): LengthAwarePaginator
    {
        /** @phpstan-ignore-next-line */
        return new LengthAwarePaginator([], 0, 15);
    }

    /** @return LengthAwarePaginator */
    public function noGeneric(): LengthAwarePaginator
    {
        /** @phpstan-ignore-next-line */
        return new LengthAwarePaginator([], 0, 15);
    }

    public function noDocblock(): LengthAwarePaginator
    {
        /** @phpstan-ignore-next-line */
        return new LengthAwarePaginator([], 0, 15);
    }
}

function makeReturnTypeExtractor(): ReturnTypeExtractor
{
    return new ReturnTypeExtractor(
        DocBlockFactory::createInstance(),
        new ContextFactory(),
    );
}

it('extracts the FQCN of a generic return argument', function (): void {
    $method = new ReflectionMethod(ReturnTypeExtractorFixture::class, 'generic');

    expect(makeReturnTypeExtractor()->genericArgument($method))
        ->toBe('stdClass');
});

it('returns null when the return type has no generic argument', function (): void {
    $method = new ReflectionMethod(ReturnTypeExtractorFixture::class, 'noGeneric');

    expect(makeReturnTypeExtractor()->genericArgument($method))->toBeNull();
});

it('returns null when the method has no docblock', function (): void {
    $method = new ReflectionMethod(ReturnTypeExtractorFixture::class, 'noDocblock');

    expect(makeReturnTypeExtractor()->genericArgument($method))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Core/Routing/ReturnTypeExtractorTest.php`
Expected: FAIL—`Class "Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

// <copyright header verbatim from an existing src/ file>

namespace Radiergummi\OpenApi\Core\Routing;

use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Types\Collection;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Object_;
use ReflectionFunctionAbstract;
use UnexpectedValueException;

use function ltrim;

/**
 * Extracts the single generic argument of an action's `@return` PHPDoc tag.
 *
 * PHP native return types cannot carry generics—`function index():
 * LengthAwarePaginator` has no inner type. The inner type lives only in a
 * PHPDoc `@return LengthAwarePaginator<UserResource>`. This reader exposes
 * exactly that one piece of information; it never reads method bodies.
 *
 * Returned names are not verified—callers run `class_exists()` before
 * trusting them.
 */
final class ReturnTypeExtractor
{
    public function __construct(
        private readonly DocBlockFactoryInterface $docBlockFactory,
        private readonly ContextFactory $contextFactory,
    ) {}

    /**
     * Returns the FQCN (without a leading backslash) of the generic argument of
     * the `@return` tag, or null when there is no docblock, no `@return` tag,
     * or no generic argument.
     */
    public function genericArgument(ReflectionFunctionAbstract $reflector): ?string
    {
        $comment = $reflector->getDocComment();

        if ($comment === false || $comment === '') {
            return null;
        }

        try {
            $context = $this->contextFactory->createFromReflector($reflector);
        } catch (UnexpectedValueException) {
            // ContextFactory does not support every Reflector (e.g. closures).
            // Without context, short class names will not resolve—acceptable.
            $context = new Context('');
        }

        $docBlock = $this->docBlockFactory->create($comment, $context);

        foreach ($docBlock->getTagsByName('return') as $tag) {
            if (!$tag instanceof Return_) {
                continue;
            }

            $type = $tag->getType();

            if (!$type instanceof Collection) {
                continue;
            }

            $valueType = $type->getValueType();

            if ($valueType instanceof Object_ && $valueType->getFqsen() !== null) {
                return ltrim((string) $valueType->getFqsen(), '\\');
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Core/Routing/ReturnTypeExtractorTest.php`
Expected: PASS—3 tests. (If `genericArgument` returns the FQCN `stdClass` for a root-namespace class, the assertion `toBe('stdClass')` passes; if phpDocumentor yields `\stdClass`, the `ltrim` already strips the backslash.)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Core/Routing/ReturnTypeExtractor.php tests/Unit/Core/Routing/ReturnTypeExtractorTest.php
git commit -m "feat: add ReturnTypeExtractor for @return PHPDoc generics"
```

---

## Task 3: `PaginatorSchemaFactory`

Builds the flat `OA\Schema` Laravel actually serializes a bare paginator to (its `toArray()` shape)—**not** the `{data, links, meta}` resource envelope, which the ApiResources plugin handles separately.

**Files:**
- Create: `src/Core/Generator/PaginatorSchemaFactory.php`
- Test: `tests/Unit/Core/Generator/PaginatorSchemaFactoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Generator;

use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Core\Support\PaginatorSchemaFactory;use Radiergummi\OpenApi\Enums\PaginatorKind;

/**
 * @return list<string>
 */
function propertyNames(OA\Schema $schema): array
{
    $names = [];

    foreach ($schema->properties as $property) {
        $names[] = $property->property;
    }

    return $names;
}

it('builds a length-aware envelope with the full toArray() key set', function (): void {
    $items = new OA\Items(['ref' => '#/components/schemas/User']);

    $schema = (new PaginatorSchemaFactory())->envelope(PaginatorKind::LengthAware, $items);
    $names = propertyNames($schema);

    expect($schema->type)->toBe('object')
        ->and($names)->toContain('current_page')
        ->and($names)->toContain('data')
        ->and($names)->toContain('last_page')
        ->and($names)->toContain('total')
        ->and($names)->toContain('per_page')
        ->and($names)->toContain('links');
});

it('omits last_page and total for a simple paginator', function (): void {
    $items = new OA\Items([]);

    $schema = (new PaginatorSchemaFactory())->envelope(PaginatorKind::Simple, $items);
    $names = propertyNames($schema);

    expect($names)->toContain('data')
        ->and($names)->toContain('current_page')
        ->and($names)->not->toContain('last_page')
        ->and($names)->not->toContain('total');
});

it('builds a cursor envelope with next_cursor and prev_cursor', function (): void {
    $items = new OA\Items([]);

    $schema = (new PaginatorSchemaFactory())->envelope(PaginatorKind::Cursor, $items);
    $names = propertyNames($schema);

    expect($names)->toContain('data')
        ->and($names)->toContain('next_cursor')
        ->and($names)->toContain('prev_cursor')
        ->and($names)->not->toContain('total');
});

it('wires the supplied items into the data array', function (): void {
    $items = new OA\Items(['ref' => '#/components/schemas/User']);

    $schema = (new PaginatorSchemaFactory())->envelope(PaginatorKind::LengthAware, $items);

    $data = null;
    foreach ($schema->properties as $prop) {
        if ($prop->property === 'data') {
            $data = $prop;
        }
    }

    expect($data)->not->toBeNull()
        ->and($data->type)->toBe('array')
        ->and($data->items)->toBe($items);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Core/Generator/PaginatorSchemaFactoryTest.php`
Expected: FAIL—`Class "Radiergummi\OpenApi\Core\Pagination\PaginatorSchemaFactory" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

// <copyright header verbatim from an existing src/ file>

namespace Radiergummi\OpenApi\Core\Generator;

use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Enums\PaginatorKind;

/**
 * Builds the flat OpenAPI schema Laravel serializes a bare paginator to via its
 * `toArray()` method. This is the raw-paginator shape; the `{data, links,
 * meta}` resource envelope is a separate shape produced only when a
 * `ResourceCollection` wraps a paginator, and is handled by the ApiResources
 * plugin.
 */
final class PaginatorSchemaFactory
{
    /**
     * Builds the response-body schema for one paginator kind, wrapping the
     * supplied per-item schema in the `data` array.
     */
    public function envelope(PaginatorKind $kind, OA\Items $items): OA\Schema
    {
        $properties = [
            $this->prop('data', ['type' => 'array', 'items' => $items]),
            $this->prop('per_page', ['type' => 'integer']),
            $this->prop('path', ['type' => 'string']),
        ];

        if ($kind === PaginatorKind::Cursor) {
            $properties[] = $this->prop('next_cursor', ['type' => 'string']);
            $properties[] = $this->prop('prev_cursor', ['type' => 'string']);
            $properties[] = $this->prop('next_page_url', ['type' => 'string']);
            $properties[] = $this->prop('prev_page_url', ['type' => 'string']);

            return new OA\Schema(['type' => 'object', 'properties' => $properties]);
        }

        // LengthAware and Simple share these.
        $properties[] = $this->prop('current_page', ['type' => 'integer']);
        $properties[] = $this->prop('from', ['type' => 'integer']);
        $properties[] = $this->prop('to', ['type' => 'integer']);
        $properties[] = $this->prop('first_page_url', ['type' => 'string']);
        $properties[] = $this->prop('next_page_url', ['type' => 'string']);
        $properties[] = $this->prop('prev_page_url', ['type' => 'string']);

        if ($kind === PaginatorKind::LengthAware) {
            $properties[] = $this->prop('last_page', ['type' => 'integer']);
            $properties[] = $this->prop('last_page_url', ['type' => 'string']);
            $properties[] = $this->prop('total', ['type' => 'integer']);
            $properties[] = $this->prop('links', ['type' => 'array', 'items' => new OA\Items([])]);
        }

        return new OA\Schema(['type' => 'object', 'properties' => $properties]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function prop(string $name, array $definition): OA\Property
    {
        return new OA\Property(['property' => $name, ...$definition]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Core/Generator/PaginatorSchemaFactoryTest.php`
Expected: PASS—4 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Core/Generator/PaginatorSchemaFactory.php tests/Unit/Core/Generator/PaginatorSchemaFactoryTest.php
git commit -m "feat: add PaginatorSchemaFactory for paginator response schemas"
```

---

## Task 4: `PaginatorResponseResolver`

The `PrimaryResponseResolver` itself. It: (a) reads the action's native return type; (b) maps it to a `PaginatorKind`, deferring (`null`) if it is not a paginator; (c) resolves the item class—`#[ResponseResource]` attribute wins, else the `@return` PHPDoc generic; (d) turns the item class into an `OA\Items` `$ref` via the registered `RefSchemaResolver`s, or a generic `OA\Items` when no resolver claims it; (e) builds the envelope and an `OA\Response`. When no item type can be found it logs a generation warning and returns `null`.

**Files:**
- Create: `src/Core/Extraction/PaginatorResponseResolver.php`
- Test: covered by the Task 6 feature test (the resolver's collaborators are all integration-shaped; a feature test exercises it end-to-end more meaningfully than a mock-heavy unit test).

- [ ] **Step 1: Write the implementation**

```php
<?php

declare(strict_types=1);

// <copyright header verbatim from an existing src/ file>

namespace Radiergummi\OpenApi\Core\Extractors;

use OpenApi\Annotations as OA;use Psr\Log\LoggerInterface;use Radiergummi\OpenApi\Attributes\ResponseResource;use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;use Radiergummi\OpenApi\Core\Support\PaginatorSchemaFactory;use Radiergummi\OpenApi\Enums\MediaType;use Radiergummi\OpenApi\Enums\PaginatorKind;use Radiergummi\OpenApi\Routing\ActionDescriptor;use Radiergummi\OpenApi\Support\Routing\ReturnTypeExtractor;use ReflectionNamedType;use Throwable;use function class_exists;use function sprintf;

/**
 * Resolves a paginator return type (`LengthAwarePaginator`, `Paginator`,
 * `CursorPaginator`) into its `200 OK` response.
 *
 * The paginated item type is resolved with this precedence (attribute wins):
 *   1. A `#[ResponseResource]` attribute on the action.
 *   2. The `@return Paginator<Item>` PHPDoc generic argument.
 * When neither is present the resolver logs a generation warning and returns
 * null, deferring to the next resolver (and ultimately the bare-200 fallback).
 */
final readonly class PaginatorResponseResolver implements PrimaryResponseResolver
{
    /**
     * @param list<RefSchemaResolver> $refSchemaResolvers
     */
    public function __construct(
        private ReturnTypeExtractor $returnTypeExtractor,
        private PaginatorSchemaFactory $schemaFactory,
        private LoggerInterface $logger,
        private array $refSchemaResolvers = [],
    ) {}

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        try {
            return $this->resolve($descriptor);
        } catch (Throwable $exception) {
            $this->logger->warning(sprintf(
                'PaginatorResponseResolver failed for route %s: %s',
                $descriptor->route->uri(),
                $exception->getMessage(),
            ));

            return null;
        }
    }

    private function resolve(ActionDescriptor $descriptor): ?OA\Response
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return null;
        }

        $returnType = $reflector->getReturnType();

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $kind = PaginatorKind::fromClass($returnType->getName());

        if ($kind === null) {
            return null;
        }

        $itemClass = $this->resolveItemClass($descriptor);

        if ($itemClass === null) {
            $this->logger->warning(sprintf(
                'Route %s returns a paginator but its item type is undeclared; '
                . 'add #[ResponseResource(...)] or a @return Paginator<Item> docblock.',
                $descriptor->route->uri(),
            ));

            return null;
        }

        $envelope = $this->schemaFactory->envelope($kind, $this->itemsFor($itemClass));

        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($envelope)],
        ]);
    }

    /**
     * @return null|class-string
     */
    private function resolveItemClass(ActionDescriptor $descriptor): ?string
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector !== null) {
            $attribute = $reflector->getAttributes(ResponseResource::class)[0] ?? null;

            if ($attribute !== null) {
                $instance = $attribute->newInstance();

                if (class_exists($instance->class)) {
                    return $instance->class;
                }
            }

            $generic = $this->returnTypeExtractor->genericArgument($reflector);

            if ($generic !== null && class_exists($generic)) {
                return $generic;
            }
        }

        return null;
    }

    /**
     * Turns the item class into an `OA\Items`: a `$ref` when a registered
     * resolver claims the class, otherwise a generic object item.
     *
     * @param class-string $itemClass
     */
    private function itemsFor(string $itemClass): OA\Items
    {
        foreach ($this->refSchemaResolvers as $resolver) {
            $ref = $resolver->resolveRef($itemClass);

            if ($ref !== null) {
                return new OA\Items(['ref' => $ref]);
            }
        }

        return new OA\Items(['type' => 'object']);
    }
}
```

- [ ] **Step 2: Run lint and the existing suite to confirm no regressions**

Run: `vendor/bin/pint && composer test`
Expected: Pint clean; the full suite still passes (the resolver is not yet registered, so behaviour is unchanged).

- [ ] **Step 3: Commit**

```bash
git add src/Core/Extractors/PaginatorResponseResolver.php
git commit -m "feat: add PaginatorResponseResolver primary-response resolver"
```

---

## Task 5: Register and wire the resolver

`PaginatorResponseResolver` needs the `list<RefSchemaResolver>` built from the registry, so—like `OperationBuilder` and `DataRefSchemaResolver`—it cannot be plain autowired; it needs an explicit `scoped` binding. `CorePlugin` adds it to the registry's primary-response-resolver list; `OperationBuilder`'s existing constructor wiring (`src/OpenApiServiceProvider.php` `registerGenerator()`, the `primaryResponseResolvers:` argument) then picks it up via `$app->make()`.

**Files:**
- Modify: `src/Core/Registry/CoreRegistration.php`
- Modify: `src/OpenApiServiceProvider.php`

- [ ] **Step 1: Register the resolver in `CoreRegistration::register()`**

In `src/Core/Registry/CoreRegistration.php`, add the import near the existing `use` block:

```php

```

Then change the `register()` method body from:

```php
    public static function register(OpenApiRegistry $registry): void
    {
        $registry->addRequestSchemaResolver(FormRequestRequestSchemaResolver::class);

        foreach (self::RULES as $rule) {
            $registry->addRule($rule);
        }
    }
```

to:

```php
    public static function register(OpenApiRegistry $registry): void
    {
        $registry->addRequestSchemaResolver(FormRequestRequestSchemaResolver::class);
        $registry->addPrimaryResponseResolver(PaginatorResponseResolver::class);

        foreach (self::RULES as $rule) {
            $registry->addRule($rule);
        }
    }
```

- [ ] **Step 2: Bind the resolver in the service provider**

In `src/OpenApiServiceProvider.php`, locate the method that binds `DataRefSchemaResolver` as a `scoped` singleton (the `$this->app->scoped(DataRefSchemaResolver::class, ...)` block). Immediately after that block, add:

```php
        $this->app->scoped(
            PaginatorResponseResolver::class,
            static function (Container $app): PaginatorResponseResolver {
                $registry = $app->make(OpenApiRegistry::class);

                return new PaginatorResponseResolver(
                    returnTypeExtractor: $app->make(ReturnTypeExtractor::class),
                    schemaFactory: $app->make(PaginatorSchemaFactory::class),
                    logger: $app->make(LoggerInterface::class),
                    refSchemaResolvers: array_map(
                        static fn(string $class) => $app->make($class),
                        $registry->refSchemaResolvers(),
                    ),
                );
            },
        );
```

Add any missing imports to the top of `src/OpenApiServiceProvider.php`—check which are already present before adding:

```php


```

`ReturnTypeExtractor` is autowireable only if `DocBlockFactoryInterface` and `ContextFactory` are container-resolvable. `ThrowsExtractor` already depends on both—confirm they resolve (search `src/OpenApiServiceProvider.php` for `DocBlockFactory` / `ContextFactory`). If `ThrowsExtractor` is autowired without an explicit binding, `ReturnTypeExtractor` will autowire too. If `ThrowsExtractor` has an explicit `scoped`/`singleton` binding, add a matching one for `ReturnTypeExtractor` right beside it:

```php
        $this->app->scoped(
            ReturnTypeExtractor::class,
            static fn() => new ReturnTypeExtractor(
                DocBlockFactory::createInstance(),
                new ContextFactory(),
            ),
        );
```

(with `use phpDocumentor\Reflection\DocBlockFactory;` and `use phpDocumentor\Reflection\Types\ContextFactory;`).

- [ ] **Step 3: Run the full suite**

Run: `vendor/bin/pint && composer test`
Expected: Pint clean; suite green. Existing tests are unaffected because no current fixture controller returns a paginator type.

- [ ] **Step 4: Commit**

```bash
git add src/Core/Registry/CoreRegistration.php src/OpenApiServiceProvider.php
git commit -m "feat: register PaginatorResponseResolver in the core pipeline"
```

---

## Task 6: Feature test—paginator endpoints end-to-end

Mirrors the structure of `tests/Feature/Oapi024Test.php`: fixture controllers declared in the test file, routes registered per test, the document generated via `app(OpenApiGenerator::class)->generate()->toYaml()` and parsed with `Yaml::parse()`.

**Files:**
- Create: `tests/Feature/PaginatorResponseTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;use Illuminate\Pagination\LengthAwarePaginator;use Illuminate\Routing\Controller;use Illuminate\Support\Facades\Route;use Radiergummi\OpenApi\Attributes\ResponseResource;use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;use Symfony\Component\Yaml\Yaml;

uses()->group('openapi');

/** A trivial schema-bearing item used as the paginated element. */
class PaginatedWidget
{
    public int $id = 0;
    public string $name = '';
}

class PaginatorDocblockController extends Controller
{
    /**
     * List widgets.
     *
     * @return LengthAwarePaginatorContract<PaginatedWidget>
     */
    public function index(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([], 0, 15);
    }
}

class PaginatorAttributeController extends Controller
{
    /** List widgets—item type declared by attribute. */
    #[ResponseResource(PaginatedWidget::class, collection: true)]
    public function index(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([], 0, 15);
    }
}

class PaginatorUndeclaredController extends Controller
{
    /** List widgets—no item type anywhere. */
    public function index(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([], 0, 15);
    }
}

class CursorPaginatorController extends Controller
{
    /**
     * Stream widgets.
     *
     * @return CursorPaginatorContract<PaginatedWidget>
     */
    public function index(): CursorPaginatorContract
    {
        /** @phpstan-ignore-next-line */
        return new \Illuminate\Pagination\CursorPaginator([], 15);
    }
}

it('documents a length-aware paginator with the flat envelope', function (): void {
    Route::get('/paginator/docblock', [PaginatorDocblockController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $response = $spec['paths']['/paginator/docblock']['get']['responses']['200'] ?? null;
    $schema = $response['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['current_page', 'data', 'last_page', 'total', 'per_page'])
        ->and($schema['properties']['data']['type'])->toBe('array');
});

it('resolves the item type from a #[ResponseResource] attribute', function (): void {
    Route::get('/paginator/attribute', [PaginatorAttributeController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/paginator/attribute']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties']['data']['type'])->toBe('array');
});

it('falls back to a bare 200 when the paginator item type is undeclared', function (): void {
    Route::get('/paginator/undeclared', [PaginatorUndeclaredController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $response = $spec['paths']['/paginator/undeclared']['get']['responses']['200'] ?? null;

    // The resolver defers; the bare-200 fallback has no JSON body.
    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});

it('documents a cursor paginator with cursor metadata', function (): void {
    Route::get('/paginator/cursor', [CursorPaginatorController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/paginator/cursor']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties'])->toHaveKeys(['data', 'next_cursor', 'prev_cursor'])
        ->and($schema['properties'])->not->toHaveKey('total');
});
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/pest tests/Feature/PaginatorResponseTest.php`
Expected: PASS—4 tests.

If the attribute test or docblock test instead emits `data.items` as a `$ref`, that is also correct (it means a `RefSchemaResolver` claimed `PaginatedWidget`); the assertions above only check `data` is an array and so hold either way. If a test fails because the generated `data.items` is undefined, widen the assertion to accept either an inline object or a `$ref`—do not weaken the `type: array` check.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint
git add tests/Feature/PaginatorResponseTest.php
git commit -m "test: cover paginator response resolution end-to-end"
```

---

## Task 7: Documentation

**Files:**
- Modify: `../../internal/known-gaps.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update `../../internal/known-gaps.md`**

Find the OAPI-017 section (the "no controller method-body inference" gap). Append this paragraph to it:

```markdown
As of the paginator-response work, the generator reads one PHPDoc tag —
`@return Foo<Bar>`—to recover the item type of a paginated return value.
This is the only place the generator looks beyond a native signature; it still
never reads method bodies. A paginator return type whose item type is declared
by neither `#[ResponseResource]` nor a `@return` generic falls back to a bare
`200 OK` and is reported in the generation log.
```

- [ ] **Step 2: Update `CHANGELOG.md`**

Add an `[Unreleased]` section directly above the existing `[0.1.0]` entry:

```markdown
## [Unreleased]

### Added

- Laravel paginator return types (`LengthAwarePaginator`, `Paginator`,
  `CursorPaginator`) are now documented automatically. The paginated item type
  is resolved from a `#[ResponseResource]` attribute or a
  `@return Paginator<Item>` PHPDoc generic.
```

- [ ] **Step 3: Run the full suite once more**

Run: `vendor/bin/pint && composer test`
Expected: Pint clean; the entire suite green.

- [ ] **Step 4: Commit**

```bash
git add docs/known-gaps.md CHANGELOG.md
git commit -m "docs: document paginator response support"
```

---

## Done criteria

- `composer test` is green with the four new feature tests and the three new unit-test files.
- `vendor/bin/pint` reports no violations.
- `composer analyse` introduces no *new* PHPStan findings beyond the known backlog (CLAUDE.md: PHPStan is non-blocking; do not add new findings).
- A controller method returning a paginator with a declared item type produces a `200` response with the flat paginator schema; an undeclared one falls back cleanly and logs a warning.

## Next plans in this program

2. `ApiResourcesPlugin`—`src/Plugins/ApiResources/`, `#[ResourceField]`, consuming the existing `#[ResponseResource]`, the `{data, links, meta}` resource envelope.
3. `QueryBuilderPlugin`.
4. `FractalPlugin`.
5. composer.json + config-defaults wiring.

Each gets its own plan written the same way, after this one is merged.
