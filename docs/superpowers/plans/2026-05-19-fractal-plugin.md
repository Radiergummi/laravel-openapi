# Fractal Plugin Implementation Plan

> **Read first:** `docs/superpowers/plans/plugin-suite-program.md`—the program tracker with shared ground rules, locked cross-cutting decisions, build order, and live status.
>
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Teach the OpenAPI core to document `league/fractal` / `spatie/laravel-fractal` transformer responses, deriving each transformer's output shape from class-level `#[TransformerField]` / `#[TransformerInclude]` attributes and binding an endpoint to its transformer with a method-level `#[FractalResponse]`.

**Architecture:** This is build step 4 of 5 in the plugin-suite program (spec: `docs/superpowers/specs/2026-05-18-plugin-suite-design.md`). It depends on build step 1 being merged. A Fractal transformer's `transform()` shape lives in a method body, which the generator never reads (OAPI-017); the plugin resolves the shape from attributes instead. The plugin registers a `PrimaryResponseResolver`, a `RefSchemaResolver`, and **four** lint rules.

**Serializer assumption.** The emitted envelope shapes—`{data}` for a single item, `{data: [...]}` for a collection, `{data: [...], meta: {pagination: {…}}}` for a paginated collection—model Fractal's default `DataArraySerializer` plus `IlluminatePaginatorAdapter`. Other serializers (`JsonApiSerializer`, custom) produce different shapes and are out of scope; this is recorded as an OAPI known-gap (Task 13).

**Package-free by design.** The plugin keys off **its own attributes only**—it never references `League\Fractal\TransformerAbstract`. A "transformer" is simply any class carrying `#[TransformerField]` attributes. This keeps the plugin (and its tests) independent of `league/fractal`; the package is added to `require-dev` / `suggest` in build step 5 for discoverability and realistic integration only. `FractalPlugin` is **shipped commented-out** in `config/openapi.php`.

**Container-cycle note.** As in the ApiResources plugin, `TransformerRefSchemaResolver` depends on `SchemaFromTransformer`, which needs the registered `RefSchemaResolver` list. `SchemaFromTransformer` recurses **directly** for nested transformer-shaped classes and receives a resolver list with `TransformerRefSchemaResolver` **filtered out**.

**Tech Stack:** PHP 8.4, Laravel 12/13, swagger-php (`OpenApi\Annotations`), Pest + Orchestra Testbench.

---

## Conventions every task must follow

- Every new PHP file starts with `<?php`, a blank line, the MIT/copyright docblock header copied verbatim from any existing `src/` file (the block in `src/Core/Generator/OperationBuilder.php` lines 3-8), a blank line, `declare(strict_types=1);`, a blank line, then the `namespace`. Code blocks below abbreviate it as `// <copyright header>`.
- Run `composer test`, `vendor/bin/pint`, and `composer analyse` before every commit. The suite must be green, Pint must report no violations, and PHPStan (level 8, CI-blocking) must report no errors.
- Commit messages: imperative mood, `feat:` / `test:` / `docs:` prefix, and the trailer `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.
- Work happens on the existing branch `feature/plugin-suite`.
- Lint-rule severity → `level()`: High = `1`, Medium = `2`, Low = `3`.

## File structure

| File | Responsibility |
|---|---|
| `src/Plugins/Fractal/Attributes/TransformerField.php` (create) | Repeatable class-level attribute—one transformer output key. |
| `src/Plugins/Fractal/Attributes/TransformerInclude.php` (create) | Repeatable class-level attribute—one `availableIncludes` / `defaultIncludes` entry. |
| `src/Plugins/Fractal/Attributes/FractalResponse.php` (create) | Method-level attribute binding an endpoint to its transformer; declares cardinality and pagination. |
| `src/Plugins/Fractal/SchemaFromTransformer.php` (create) | Builds the `OA\Schema` for a transformer from its attributes. |
| `src/Plugins/Fractal/TransformerRefSchemaResolver.php` (create) | `RefSchemaResolver` for transformer-shaped classes. |
| `src/Plugins/Fractal/FractalEnvelopeFactory.php` (create) | Builds the `{data}` / `{data: [...]}` / paginated envelope schemas. |
| `src/Plugins/Fractal/FractalResponseResolver.php` (create) | `PrimaryResponseResolver` for `#[FractalResponse]` endpoints. |
| `src/Plugins/Fractal/FractalPlugin.php` (create) | `Plugin`—registers resolvers and the four lint rules. |
| `src/Plugins/Fractal/Lint/Rules/FractalFieldsUndeclared.php` (create) | Lint rule `fractal.fields-undeclared`. |
| `src/Plugins/Fractal/Lint/Rules/FractalIncludeTransformerMissing.php` (create) | Lint rule `fractal.include-transformer-missing`. |
| `src/Plugins/Fractal/Lint/Rules/FractalResponseUnbound.php` (create) | Lint rule `fractal.response-unbound`. |
| `src/Plugins/Fractal/Lint/Rules/FractalDuplicateKey.php` (create) | Lint rule `fractal.duplicate-key`. |
| `src/OpenApiServiceProvider.php` (modify) | Add `registerFractalPlugin()` with `scoped` bindings. |
| `config/openapi.php` (modify) | Add a commented-out `FractalPlugin::class` entry. |
| `tests/Feature/Plugins/Fractal/FractalResponseTest.php` (create) | End-to-end document generation. |
| `tests/Unit/Plugins/Fractal/*` (create) | Unit tests for each class. |
| `../../internal/known-gaps.md`, `CHANGELOG.md`, `docs/usage.md` (modify) | Per-change doc obligations. |

---

## Task 1: `TransformerField` attribute

`#[TransformerField]` is repeatable and class-level on a transformer. It extends the Core `FieldAttribute` base, adding `name`. Same shape as the ApiResources `#[ResourceField]`: a class-string `type` is a nested `$ref`, otherwise a JSON-Schema scalar.

**Files:**
- Create: `src/Plugins/Fractal/Attributes/TransformerField.php`
- Test: `tests/Unit/Plugins/Fractal/Attributes/TransformerFieldTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Attributes;

use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;

it('exposes its name and forwards schema fields to the descriptor', function (): void {
    $field = new TransformerField('title', type: 'string', maxLength: 120);

    expect($field->name)->toBe('title')
        ->and($field->type)->toBe('string')
        ->and($field->descriptor()->maxLength)->toBe(120);
});

it('is repeatable and targets classes', function (): void {
    $attribute = (new \ReflectionClass(TransformerField::class))
        ->getAttributes(\Attribute::class)[0]->newInstance();

    expect($attribute->flags & \Attribute::IS_REPEATABLE)->toBe(\Attribute::IS_REPEATABLE)
        ->and($attribute->flags & \Attribute::TARGET_CLASS)->toBe(\Attribute::TARGET_CLASS);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Attributes/TransformerFieldTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Attributes;

use Attribute;
use BackedEnum;
use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;

/**
 * Declares one output key of a Fractal transformer.
 *
 * Repeatable and class-level: a transformer's `transform()` return array is not
 * a set of typed class properties, so each key is declared with its own
 * attribute on the transformer class.
 *
 * When `type` is a class-string the field is emitted as a `$ref`; otherwise it
 * is a JSON-Schema scalar type.
 *
 * ```php
 * #[TransformerField('id', type: 'integer')]
 * #[TransformerField('author', type: AuthorTransformer::class)]
 * final class BookTransformer extends TransformerAbstract { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class TransformerField extends FieldAttribute
{
    /**
     * @param string                           $name        The output key.
     * @param null|class-string|string         $type        A JSON-Schema scalar type, or a class-string for a nested `$ref`.
     * @param bool                             $conditional When true, the key is kept in `properties` but omitted from `required`.
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

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Attributes/TransformerFieldTest.php`
Expected: PASS—2 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/Fractal/Attributes/TransformerField.php tests/Unit/Plugins/Fractal/Attributes/TransformerFieldTest.php
git commit -m "feat: add TransformerField attribute for Fractal plugin"
```

---

## Task 2: `TransformerInclude` and `FractalResponse` attributes

`#[TransformerInclude]` is repeatable and class-level on a transformer—it models one `availableIncludes` / `defaultIncludes` entry (`default: true` ⇒ in the response by default). `#[FractalResponse]` is method-level and not repeatable—it binds an endpoint to its transformer and declares cardinality plus pagination.

**Target audit.** `#[FractalResponse]` targets **`TARGET_METHOD` only**: the rest of the codebase's endpoint attributes are method-level, and closure-route support is out of scope for this plan. Drop `TARGET_FUNCTION` versus an earlier draft.

**The `paginated` flag.** `paginated: true` selects the paginated envelope `{data: [...], meta: {pagination: {…}}}` (Fractal's `IlluminatePaginatorAdapter` shape). It implies `collection: true`; if a caller writes `paginated: true, collection: false`, the resolver treats it as a collection anyway. A non-paginated collection (`collection: true, paginated: false`) is still supported and emits the flat `{data: [...]}` shape.

**Files:**
- Create: `src/Plugins/Fractal/Attributes/TransformerInclude.php`
- Create: `src/Plugins/Fractal/Attributes/FractalResponse.php`
- Test: `tests/Unit/Plugins/Fractal/Attributes/TransformerIncludeResponseTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Attributes;

use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;

it('stores an include name, transformer, and default flag', function (): void {
    $include = new TransformerInclude('author', transformer: \stdClass::class, default: true);

    expect($include->name)->toBe('author')
        ->and($include->transformer)->toBe(\stdClass::class)
        ->and($include->default)->toBeTrue();
});

it('defaults an include to non-default with no transformer', function (): void {
    $include = new TransformerInclude('comments');

    expect($include->transformer)->toBeNull()
        ->and($include->default)->toBeFalse();
});

it('binds an endpoint to a transformer with cardinality and pagination flags', function (): void {
    $response = new FractalResponse(transformer: \stdClass::class, collection: true);

    expect($response->transformer)->toBe(\stdClass::class)
        ->and($response->collection)->toBeTrue()
        ->and($response->paginated)->toBeFalse();
});

it('flags a paginated response', function (): void {
    $response = new FractalResponse(transformer: \stdClass::class, paginated: true);

    expect($response->paginated)->toBeTrue();
});

it('targets methods only', function (): void {
    $attribute = (new \ReflectionClass(FractalResponse::class))
        ->getAttributes(\Attribute::class)[0]->newInstance();

    expect($attribute->flags & \Attribute::TARGET_METHOD)->toBe(\Attribute::TARGET_METHOD)
        ->and($attribute->flags & \Attribute::TARGET_FUNCTION)->toBe(0);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Attributes/TransformerIncludeResponseTest.php`
Expected: FAIL—classes not found.

- [x] **Step 3: Write the implementations**

`src/Plugins/Fractal/Attributes/TransformerInclude.php`:

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Attributes;

use Attribute;

/**
 * Declares one Fractal include—an `availableIncludes` entry, or a
 * `defaultIncludes` entry when `default` is true. Repeatable, class-level on
 * the transformer.
 *
 * ```php
 * #[TransformerInclude('author', transformer: AuthorTransformer::class, default: true)]
 * #[TransformerInclude('comments', transformer: CommentTransformer::class)]
 * final class BookTransformer extends TransformerAbstract { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class TransformerInclude
{
    /**
     * @param string            $name        The include name (the response key it adds).
     * @param null|class-string $transformer The transformer producing the included resource's schema.
     * @param bool              $default     True for a `defaultIncludes` entry (present unless excluded).
     */
    public function __construct(
        public string $name,
        public ?string $transformer = null,
        public bool $default = false,
    ) {}
}
```

`src/Plugins/Fractal/Attributes/FractalResponse.php`:

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Attributes;

use Attribute;

/**
 * Binds an endpoint to the Fractal transformer that shapes its response.
 *
 * Method-level: a Fractal transformer is applied inside a method body, which
 * the generator never reads, so the binding is declared explicitly.
 *
 * `paginated: true` implies a paginated collection—the envelope gains
 * `meta.pagination` matching Fractal's `IlluminatePaginatorAdapter` shape, and
 * the resolver treats the response as a collection regardless of the
 * `collection` flag.
 *
 * ```php
 * #[FractalResponse(transformer: BookTransformer::class)]                       // single
 * #[FractalResponse(transformer: BookTransformer::class, collection: true)]    // flat collection
 * #[FractalResponse(transformer: BookTransformer::class, paginated: true)]     // paginated collection
 * public function index(): JsonResponse { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class FractalResponse
{
    /**
     * @param class-string $transformer The transformer class shaping the response.
     * @param bool         $collection  True when the endpoint returns a (non-paginated) collection.
     * @param bool         $paginated   True for a paginated collection—implies `collection: true`.
     */
    public function __construct(
        public string $transformer,
        public bool $collection = false,
        public bool $paginated = false,
    ) {}
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Attributes/TransformerIncludeResponseTest.php`
Expected: PASS—5 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/Fractal/Attributes/TransformerInclude.php src/Plugins/Fractal/Attributes/FractalResponse.php tests/Unit/Plugins/Fractal/Attributes/TransformerIncludeResponseTest.php
git commit -m "feat: add TransformerInclude and FractalResponse attributes"
```

---

## Task 3: `SchemaFromTransformer`

Builds the `OA\Schema` (type: object) for a transformer from its `#[TransformerField]` and `#[TransformerInclude]` attributes, registers it as a component, and returns the key. Mirrors `SchemaFromResource`:

- Use the registry's **public** `buildOnce(class, factory)` API—the manual `markInProgress` / `markComplete` calls used in earlier drafts hit private methods.
- Provide `buildRef(class): string` returning a `#/components/schemas/<key>` ref (thin wrapper over `build()` + `qualifyKey()`).
- For scalar `#[TransformerField]` values, use `$field->descriptor()->applyTo($property)` so `NullableSchema` handling and `toOpenApi()` spreading happen in one call (matches `SchemaFromResource::buildProperty()`).
- For class-string `#[TransformerField]` values, propagate the field's `description` onto the wrapping property—same as `SchemaFromResource` does for `JsonResource`-typed fields.

Per-field semantics:
- `#[TransformerField]` with a class-string `type` → a transformer-shaped class (carries `#[TransformerField]`) recurses via `build()`; any other class resolves via the injected resolver list; an unresolved class → `type: object`. A scalar `type` → `descriptor()->applyTo()`.
- `#[TransformerInclude]` → a property `name` whose schema is a `$ref` to `transformer`'s schema (or `type: object` when `transformer` is null). A `default: true` include is added to `required`; `#[TransformerField]` keys are required unless `conditional`.

**Duplicate keys are not deduplicated here.** Matching `SchemaFromResource`'s behavior, the builder iterates attributes in source order without dedup. The `fractal.duplicate-key` lint rule (Task 12) reports collisions; users fix them at the source.

**Files:**
- Create: `src/Plugins/Fractal/SchemaFromTransformer.php`
- Test: `tests/Unit/Plugins/Fractal/SchemaFromTransformerTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use Radiergummi\OpenApi\Plugins\Fractal\SchemaFromTransformer;

#[TransformerField('id', type: 'integer')]
#[TransformerField('title', type: 'string', maxLength: 120)]
#[TransformerInclude('author', transformer: SchemaAuthorTransformer::class, default: true)]
class SchemaBookTransformer {}

#[TransformerField('name', type: 'string')]
class SchemaAuthorTransformer {}

/** @return array<string, OA\Property> */
function transformerPropertiesByName(OA\Schema $schema): array
{
    $out = [];

    foreach ($schema->properties as $property) {
        $out[$property->property] = $property;
    }

    return $out;
}

it('builds an object schema from transformer attributes', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = (new SchemaFromTransformer($registry, []))->build(SchemaBookTransformer::class);

    $schema = null;
    foreach ($registry->all() as $candidate) {
        if ($candidate->schema === $key) {
            $schema = $candidate;
        }
    }

    $props = transformerPropertiesByName($schema);

    expect($schema->type)->toBe('object')
        ->and($props)->toHaveKeys(['id', 'title', 'author']);
});

it('applies scalar descriptor fields onto the property', function (): void {
    $registry = new ComponentSchemaRegistry();
    (new SchemaFromTransformer($registry, []))->build(SchemaBookTransformer::class);

    $book = null;
    foreach ($registry->all() as $candidate) {
        if ($candidate->schema === 'SchemaBookTransformer') {
            $book = $candidate;
        }
    }

    expect(transformerPropertiesByName($book)['title']->maxLength)->toBe(120);
});

it('emits an include as a $ref and registers the included transformer', function (): void {
    $registry = new ComponentSchemaRegistry();
    (new SchemaFromTransformer($registry, []))->build(SchemaBookTransformer::class);

    $keys = array_map(static fn(OA\Schema $s): string => $s->schema, $registry->all());
    expect($keys)->toContain('SchemaAuthorTransformer');
});

it('marks default includes as required and non-default as optional', function (): void {
    $registry = new ComponentSchemaRegistry();
    $key = (new SchemaFromTransformer($registry, []))->build(SchemaBookTransformer::class);

    $schema = null;
    foreach ($registry->all() as $candidate) {
        if ($candidate->schema === $key) {
            $schema = $candidate;
        }
    }

    expect($schema->required)->toContain('author');
});

it('exposes buildRef returning a qualified components ref', function (): void {
    $registry = new ComponentSchemaRegistry();
    $ref = (new SchemaFromTransformer($registry, []))->buildRef(SchemaBookTransformer::class);

    expect($ref)->toBe('#/components/schemas/SchemaBookTransformer');
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/SchemaFromTransformerTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use ReflectionClass;

use function class_exists;

/**
 * Builds the `OA\Schema` (type: object) for a Fractal transformer from its
 * class-level `#[TransformerField]` and `#[TransformerInclude]` attributes and
 * registers it as a component.
 *
 * Nested transformer-shaped field types (classes carrying `#[TransformerField]`)
 * recurse through {@see build()} directly; other class-string types resolve via
 * the injected resolver list (which excludes {@see TransformerRefSchemaResolver}
 *—see the plan's architecture note).
 */
final readonly class SchemaFromTransformer
{
    /**
     * @param list<RefSchemaResolver> $refSchemaResolvers Registered ref resolvers, minus this plugin's own.
     */
    public function __construct(
        private ComponentSchemaRegistry $registry,
        private array $refSchemaResolvers = [],
    ) {}

    /**
     * Registers the transformer as a component schema and returns its key.
     *
     * @param class-string $transformerClass
     */
    public function build(string $transformerClass): string
    {
        return $this->registry->buildOnce(
            $transformerClass,
            fn(): OA\Schema => $this->buildSchema($transformerClass),
        );
    }

    /**
     * Registers the transformer and returns its qualified `$ref` string.
     *
     * @param class-string $transformerClass
     */
    public function buildRef(string $transformerClass): string
    {
        return $this->registry->qualifyKey($this->build($transformerClass));
    }

    /**
     * @param class-string $transformerClass
     */
    private function buildSchema(string $transformerClass): OA\Schema
    {
        $reflection = new ReflectionClass($transformerClass);

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        foreach ($reflection->getAttributes(TransformerField::class) as $attribute) {
            $field = $attribute->newInstance();
            $properties[] = $this->buildFieldProperty($field);

            if (!$field->conditional) {
                $required[] = $field->name;
            }
        }

        foreach ($reflection->getAttributes(TransformerInclude::class) as $attribute) {
            $include = $attribute->newInstance();
            $properties[] = $this->buildIncludeProperty($include);

            if ($include->default) {
                $required[] = $include->name;
            }
        }

        $props = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $props['required'] = $required;
        }

        return new OA\Schema($props);
    }

    private function buildFieldProperty(TransformerField $field): OA\Property
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

        $property = new OA\Property(['property' => $field->name]);
        $field->descriptor()->applyTo($property);

        return $property;
    }

    private function buildIncludeProperty(TransformerInclude $include): OA\Property
    {
        $ref = $include->transformer !== null && class_exists($include->transformer)
            ? $this->resolveClassRef($include->transformer)
            : null;

        return $ref !== null
            ? new OA\Property(['property' => $include->name, 'ref' => $ref])
            : new OA\Property(['property' => $include->name, 'type' => 'object']);
    }

    /**
     * @param class-string $class
     */
    private function resolveClassRef(string $class): ?string
    {
        if ((new ReflectionClass($class))->getAttributes(TransformerField::class) !== []) {
            return $this->buildRef($class);
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

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/SchemaFromTransformerTest.php`
Expected: PASS—5 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/Fractal/SchemaFromTransformer.php tests/Unit/Plugins/Fractal/SchemaFromTransformerTest.php
git commit -m "feat: add SchemaFromTransformer schema builder"
```

---

## Task 4: `TransformerRefSchemaResolver`

A `RefSchemaResolver`: claims any **transformer-shaped class**—one carrying at least one `#[TransformerField]` attribute—and delegates to `SchemaFromTransformer::buildRef()`. This is what lets a transformer compose as a `$ref` when nested in another transformer or a resource.

**Files:**
- Create: `src/Plugins/Fractal/TransformerRefSchemaResolver.php`
- Test: `tests/Unit/Plugins/Fractal/TransformerRefSchemaResolverTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\SchemaFromTransformer;
use Radiergummi\OpenApi\Plugins\Fractal\TransformerRefSchemaResolver;

#[TransformerField('id', type: 'integer')]
class RefFixtureTransformer {}

class NotATransformer {}

function makeTransformerRefResolver(): TransformerRefSchemaResolver
{
    $registry = new ComponentSchemaRegistry();

    return new TransformerRefSchemaResolver(new SchemaFromTransformer($registry, []));
}

it('resolves a transformer-shaped class to a components ref', function (): void {
    expect(makeTransformerRefResolver()->resolveRef(RefFixtureTransformer::class))
        ->toBe('#/components/schemas/RefFixtureTransformer');
});

it('returns null for a class with no #[TransformerField]', function (): void {
    expect(makeTransformerRefResolver()->resolveRef(NotATransformer::class))->toBeNull();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/TransformerRefSchemaResolverTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use ReflectionClass;

use function class_exists;

/**
 * Resolves a Fractal transformer class to a `#/components/schemas/…` ref. A
 * "transformer" is any class carrying at least one `#[TransformerField]`
 * attribute—the plugin never references `league/fractal` directly.
 */
final readonly class TransformerRefSchemaResolver implements RefSchemaResolver
{
    public function __construct(
        private SchemaFromTransformer $schemaFromTransformer,
    ) {}

    public function resolveRef(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }

        if ((new ReflectionClass($class))->getAttributes(TransformerField::class) === []) {
            return null;
        }

        return $this->schemaFromTransformer->buildRef($class);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/TransformerRefSchemaResolverTest.php`
Expected: PASS—2 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/Fractal/TransformerRefSchemaResolver.php tests/Unit/Plugins/Fractal/TransformerRefSchemaResolverTest.php
git commit -m "feat: add TransformerRefSchemaResolver"
```

---

## Task 5: `FractalEnvelopeFactory`

A small factory for the three envelope shapes the response resolver needs. Mirrors `ResourceEnvelopeFactory`'s split between factory and resolver—keeps shape logic out of the resolver and unit-testable in isolation.

- `single($ref)` → `{data: $ref}`
- `collection($ref)` → `{data: [$ref…]}`
- `paginated($ref)` → `{data: [$ref…], meta: {pagination: {…}}}`

The paginated `meta.pagination` shape models Fractal's `IlluminatePaginatorAdapter`: `total`, `count`, `per_page`, `current_page`, `total_pages`, and a nested `links: { previous, next }`. (Note: Fractal uses `total_pages`, not the `last_page` Laravel's bare paginator emits.)

**Files:**
- Create: `src/Plugins/Fractal/FractalEnvelopeFactory.php`
- Test: `tests/Unit/Plugins/Fractal/FractalEnvelopeFactoryTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use Radiergummi\OpenApi\Plugins\Fractal\FractalEnvelopeFactory;

/** @return list<string> */
function envelopePropertyNames(\OpenApi\Annotations\Schema $schema): array
{
    return array_map(static fn($p) => $p->property, $schema->properties);
}

it('builds a single-item envelope with a data ref', function (): void {
    $schema = (new FractalEnvelopeFactory())->single('#/components/schemas/Book');

    expect($schema->type)->toBe('object')
        ->and(envelopePropertyNames($schema))->toBe(['data'])
        ->and($schema->properties[0]->ref)->toBe('#/components/schemas/Book');
});

it('builds a collection envelope with a data array', function (): void {
    $schema = (new FractalEnvelopeFactory())->collection('#/components/schemas/Book');

    expect(envelopePropertyNames($schema))->toBe(['data'])
        ->and($schema->properties[0]->type)->toBe('array');
});

it('builds a paginated envelope with pagination meta', function (): void {
    $schema = (new FractalEnvelopeFactory())->paginated('#/components/schemas/Book');
    $names = envelopePropertyNames($schema);

    expect($names)->toBe(['data', 'meta'])
        ->and($schema->properties[0]->type)->toBe('array');

    $meta = $schema->properties[1];
    $metaNames = array_map(static fn($p) => $p->property, $meta->properties);
    expect($metaNames)->toBe(['pagination']);

    $paginationKeys = array_map(static fn($p) => $p->property, $meta->properties[0]->properties);
    expect($paginationKeys)->toContain('total', 'count', 'per_page', 'current_page', 'total_pages');
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/FractalEnvelopeFactoryTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use OpenApi\Annotations as OA;

/**
 * Builds the response envelope shapes Fractal's default `DataArraySerializer`
 * serializes into:
 *
 * - {@see single()}    —`{data}`
 * - {@see collection()}—`{data: [...]}`
 * - {@see paginated()} —`{data: [...], meta: {pagination: {…}}}`,
 *                         matching `IlluminatePaginatorAdapter`.
 *
 * Other Fractal serializers (`JsonApiSerializer`, custom) produce different
 * shapes and are out of scope (see OAPI-052 in `docs/known-gaps.md`).
 */
final readonly class FractalEnvelopeFactory
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
            ],
        ]);
    }

    public function paginated(string $ref): OA\Schema
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
                    'property' => 'meta',
                    'type' => 'object',
                    'properties' => [
                        new OA\Property([
                            'property' => 'pagination',
                            'type' => 'object',
                            'properties' => [
                                new OA\Property(['property' => 'total', 'type' => 'integer']),
                                new OA\Property(['property' => 'count', 'type' => 'integer']),
                                new OA\Property(['property' => 'per_page', 'type' => 'integer']),
                                new OA\Property(['property' => 'current_page', 'type' => 'integer']),
                                new OA\Property(['property' => 'total_pages', 'type' => 'integer']),
                                new OA\Property([
                                    'property' => 'links',
                                    'type' => 'object',
                                    'properties' => [
                                        new OA\Property(['property' => 'previous', 'type' => 'string']),
                                        new OA\Property(['property' => 'next', 'type' => 'string']),
                                    ],
                                ]),
                            ],
                        ]),
                    ],
                ]),
            ],
        ]);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/FractalEnvelopeFactoryTest.php`
Expected: PASS—3 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/Fractal/FractalEnvelopeFactory.php tests/Unit/Plugins/Fractal/FractalEnvelopeFactoryTest.php
git commit -m "feat: add FractalEnvelopeFactory for single/collection/paginated shapes"
```

---

## Task 6: `FractalResponseResolver`

The `PrimaryResponseResolver`. Reads `#[FractalResponse]` off the action; defers (`null`) when absent. Builds the transformer `$ref` via `SchemaFromTransformer::buildRef()` and delegates envelope construction to `FractalEnvelopeFactory`. Degrades gracefully.

Envelope selection (in priority order):
1. `paginated: true` → `paginated($ref)`—also accepted when the caller forgot `collection: true`.
2. `collection: true` → `collection($ref)`.
3. otherwise → `single($ref)`.

**Files:**
- Create: `src/Plugins/Fractal/FractalResponseResolver.php`
- Test: covered by the Task 8 feature test.

- [x] **Step 1: Write the implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Core\Enums\MediaType;
use Radiergummi\OpenApi\Core\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Throwable;

use function class_exists;
use function sprintf;

/**
 * Resolves a `#[FractalResponse]`-bound endpoint into its `200 OK` response.
 *
 * Defers (returns null) when the action carries no `#[FractalResponse]`. The
 * transformer's schema is wrapped in the Fractal `data` envelope produced by
 * {@see FractalEnvelopeFactory}—paginated, collection, or single depending on
 * the attribute flags.
 */
final readonly class FractalResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private SchemaFromTransformer $schemaFromTransformer,
        private FractalEnvelopeFactory $envelopeFactory,
        private LoggerInterface $logger,
    ) {}

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        try {
            $reflector = $descriptor->actionReflector;

            $attribute = $reflector?->getAttributes(FractalResponse::class)[0] ?? null;

            if ($attribute === null) {
                return null;
            }

            $fractalResponse = $attribute->newInstance();

            if (!class_exists($fractalResponse->transformer)) {
                $this->logger->warning(sprintf(
                    '#[FractalResponse] on route %s names unknown transformer %s',
                    $descriptor->route->uri(),
                    $fractalResponse->transformer,
                ));

                return null;
            }

            $ref = $this->schemaFromTransformer->buildRef($fractalResponse->transformer);
            $envelope = $this->envelopeFor($fractalResponse, $ref);

            return new OA\Response([
                'response' => '200',
                'description' => 'OK',
                'content' => [MediaType::Json->schema($envelope)],
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning(sprintf(
                'FractalResponseResolver failed for route %s: %s',
                $descriptor->route->uri(),
                $exception->getMessage(),
            ));

            return null;
        }
    }

    private function envelopeFor(FractalResponse $response, string $ref): OA\Schema
    {
        if ($response->paginated) {
            return $this->envelopeFactory->paginated($ref);
        }

        if ($response->collection) {
            return $this->envelopeFactory->collection($ref);
        }

        return $this->envelopeFactory->single($ref);
    }
}
```

- [x] **Step 2: Run lint + analyse**

Run: `vendor/bin/pint && composer analyse`
Expected: Pint clean; PHPStan clean (the resolver is not yet registered).

- [x] **Step 3: Commit**

```bash
git add src/Plugins/Fractal/FractalResponseResolver.php
git commit -m "feat: add FractalResponseResolver primary-response resolver"
```

---

## Task 7: `FractalPlugin` + service-provider wiring + config

`FractalPlugin` registers the resolvers and **four** lint rules (created in Tasks 9–12—register all now). The plugin ships **commented-out** in `config/openapi.php`.

**Files:**
- Create: `src/Plugins/Fractal/FractalPlugin.php`
- Modify: `src/OpenApiServiceProvider.php`
- Modify: `config/openapi.php`

- [x] **Step 1: Write `FractalPlugin`**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalDuplicateKey;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalFieldsUndeclared;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalIncludeTransformerMissing;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalResponseUnbound;

/**
 * Teaches the OpenAPI core to document `league/fractal` transformer responses.
 */
final class FractalPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRefSchemaResolver(TransformerRefSchemaResolver::class);
        $registry->addPrimaryResponseResolver(FractalResponseResolver::class);
        $registry->addRule(FractalResponseUnbound::class);
        $registry->addRule(FractalFieldsUndeclared::class);
        $registry->addRule(FractalIncludeTransformerMissing::class);
        $registry->addRule(FractalDuplicateKey::class);
    }
}
```

- [x] **Step 2: Add `registerFractalPlugin()` to `OpenApiServiceProvider`**

In `register()`, add the call after `registerApiResourcesPlugin();`:

```php
        $this->registerApiResourcesPlugin();
        $this->registerFractalPlugin();
        $this->registerGenerator();
```

Add this method after `registerApiResourcesPlugin()`. As in the ApiResources plugin, `SchemaFromTransformer` receives every ref resolver except this plugin's own `TransformerRefSchemaResolver`. The new `FractalEnvelopeFactory` is dependency-free:

```php
    /**
     * Binds the Fractal plugin services.
     *
     * `SchemaFromTransformer` receives every ref resolver except this plugin's
     * own `TransformerRefSchemaResolver`—it recurses for nested transformers
     * directly, and injecting its own resolver would form a construction cycle.
     */
    private function registerFractalPlugin(): void
    {
        $this->app->scoped(
            Plugins\Fractal\SchemaFromTransformer::class,
            static function (Container $app): Plugins\Fractal\SchemaFromTransformer {
                $registry = $app->make(OpenApiRegistry::class);

                $resolvers = [];

                foreach ($registry->refSchemaResolvers() as $class) {
                    if ($class === Plugins\Fractal\TransformerRefSchemaResolver::class) {
                        continue;
                    }

                    $resolvers[] = $app->make($class);
                }

                return new Plugins\Fractal\SchemaFromTransformer(
                    registry: $app->make(ComponentSchemaRegistry::class),
                    refSchemaResolvers: $resolvers,
                );
            },
        );

        $this->app->scoped(
            Plugins\Fractal\FractalEnvelopeFactory::class,
            static fn(): Plugins\Fractal\FractalEnvelopeFactory => new Plugins\Fractal\FractalEnvelopeFactory(),
        );

        $this->app->scoped(
            Plugins\Fractal\TransformerRefSchemaResolver::class,
            static fn(Container $app) => new Plugins\Fractal\TransformerRefSchemaResolver(
                schemaFromTransformer: $app->make(Plugins\Fractal\SchemaFromTransformer::class),
            ),
        );

        $this->app->scoped(
            Plugins\Fractal\FractalResponseResolver::class,
            static fn(Container $app) => new Plugins\Fractal\FractalResponseResolver(
                schemaFromTransformer: $app->make(Plugins\Fractal\SchemaFromTransformer::class),
                envelopeFactory: $app->make(Plugins\Fractal\FractalEnvelopeFactory::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }
```

The four lint rules have no constructor dependencies—they are autowired; no explicit binding is needed.

- [x] **Step 3: Add the commented-out config entry**

In `config/openapi.php`, extend the `plugins` array (which by build steps 2–3 contains `SpatieDataPlugin`, `ApiResourcesPlugin`, and the commented `QueryBuilderPlugin`). Final state:

```php
    'plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,

        // Requires `composer require spatie/laravel-query-builder`. Uncomment to enable:
        // \Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin::class,

        // Requires `composer require league/fractal`. Uncomment to enable:
        // \Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin::class,
    ],
```

- [x] **Step 4: Run lint + analyse**

Run: `vendor/bin/pint && composer analyse`
Expected: Pint clean; PHPStan clean. (`composer test` fails until Tasks 9–12 create the four lint-rule classes—expected.)

- [x] **Step 5: Commit**

```bash
git add src/Plugins/Fractal/FractalPlugin.php src/OpenApiServiceProvider.php config/openapi.php
git commit -m "feat: register and wire the Fractal plugin (shipped disabled)"
```

---

## Task 8: Feature test—Fractal transformer endpoints end-to-end

The plugin is disabled by default; the test enables it via a `config()` override in `beforeEach`. Fixture transformers are plain classes carrying `#[TransformerField]`—no `league/fractal` package is needed.

**Namespacing.** The test file declares `namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Fractal;` at the top. Every fixture class declared in this file lives in that namespace, even when written as a bare `class FooTransformer {}` further down—that's how PHP namespaces work, but state it explicitly so reviewers don't second-guess collisions with fixtures in other test files.

**Files:**
- Create: `tests/Feature/Plugins/Fractal/FractalResponseTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi', 'plugin:fractal');

beforeEach(function (): void {
    config(['openapi.plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,
        FractalPlugin::class,
    ]]);
});

#[TransformerField('id', type: 'integer')]
#[TransformerField('title', type: 'string')]
class BookTransformer {}

class FractalFixtureController extends Controller
{
    /** Show a book. */
    #[FractalResponse(transformer: BookTransformer::class)]
    public function show(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** List books. */
    #[FractalResponse(transformer: BookTransformer::class, collection: true)]
    public function index(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** Paginated books. */
    #[FractalResponse(transformer: BookTransformer::class, paginated: true)]
    public function paginated(): JsonResponse
    {
        return new JsonResponse([]);
    }
}

it('documents a single Fractal response wrapped in data', function (): void {
    Route::get('/books/{book}', [FractalFixtureController::class, 'show']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/books/{book}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('data');
});

it('documents a collection Fractal response as a data array', function (): void {
    Route::get('/books', [FractalFixtureController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/books']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties'])->not->toHaveKey('meta');
});

it('documents a paginated Fractal response with pagination meta', function (): void {
    Route::get('/books/page', [FractalFixtureController::class, 'paginated']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/books/page']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['meta']['properties']['pagination']['properties'])
            ->toHaveKeys(['total', 'count', 'per_page', 'current_page', 'total_pages']);
});

it('registers the transformer as a reusable component schema', function (): void {
    Route::get('/books/{book}', [FractalFixtureController::class, 'show']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    expect($spec['components']['schemas'] ?? [])->toHaveKey('BookTransformer');
});
```

- [x] **Step 2: Run the test**

Run: `vendor/bin/pest tests/Feature/Plugins/Fractal/FractalResponseTest.php`
Expected: PASS—4 tests. (If the four lint-rule classes are still missing, complete Tasks 9–12 first.)

- [x] **Step 3: Commit**

```bash
vendor/bin/pint
git add tests/Feature/Plugins/Fractal/FractalResponseTest.php
git commit -m "test: cover Fractal response resolution end-to-end"
```

---

## Task 9: Lint rule `fractal.fields-undeclared`

High severity (`level: 1`). Flags an operation bound via `#[FractalResponse]` whose transformer class declares **zero** `#[TransformerField]` attributes.

**Files:**
- Create: `src/Plugins/Fractal/Lint/Rules/FractalFieldsUndeclared.php`
- Test: `tests/Unit/Plugins/Fractal/Lint/FractalFieldsUndeclaredTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalFieldsUndeclared;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:fractal');

class BareFractalTransformer {}

class BareFractalController
{
    #[FractalResponse(transformer: BareFractalTransformer::class)]
    public function show(): void {}
}

it('flags a #[FractalResponse] transformer that declares no #[TransformerField]', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new \ReflectionClass(BareFractalController::class),
        method: new \ReflectionMethod(BareFractalController::class, 'show'),
        summary: null,
        description: null,
    );

    $rule = new FractalFieldsUndeclared();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        new LintContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.fields-undeclared');
});
```

> **Test helper:** `OperationNodeFactory::forDescriptor()` is the shared helper introduced in the ApiResources plan (Task 10). Reuse it; create `tests/Support/OperationNodeFactory.php` per that plan's description if it does not yet exist.

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Lint/FractalFieldsUndeclaredTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use ReflectionClass;

use function class_exists;
use function sprintf;

/**
 * Flags an operation bound via `#[FractalResponse]` whose transformer declares
 * no `#[TransformerField]`—the response shape is unknown.
 */
final readonly class FractalFieldsUndeclared implements Rule, OperationRule
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $method = $operation->descriptor?->method;

        if ($operation->webhook || $method === null) {
            return;
        }

        $attribute = $method->getAttributes(FractalResponse::class)[0] ?? null;

        if ($attribute === null) {
            return;
        }

        $transformer = $attribute->newInstance()->transformer;

        if (!class_exists($transformer)) {
            return;
        }

        if ((new ReflectionClass($transformer))->getAttributes(TransformerField::class) !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s is bound to %s, which declares no #[TransformerField]—the response schema is empty',
                $operation->method,
                $operation->pathUri,
                $transformer,
            ),
            fixHint: 'Declare each output key with a class-level #[TransformerField] on the transformer.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'fractal.fields-undeclared';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A transformer bound via #[FractalResponse] declares no #[TransformerField] attributes.';
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Lint/FractalFieldsUndeclaredTest.php`
Expected: PASS—1 test.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/Fractal/Lint/Rules/FractalFieldsUndeclared.php tests/Unit/Plugins/Fractal/Lint/FractalFieldsUndeclaredTest.php
git commit -m "feat: add fractal.fields-undeclared lint rule"
```

---

## Task 10: Lint rule `fractal.include-transformer-missing`

Medium severity (`level: 2`). Flags any `#[TransformerInclude]`—on the transformer bound by an operation's `#[FractalResponse]`—whose `transformer` is `null`. Such an include is emitted as an opaque `type: object`.

**Files:**
- Create: `src/Plugins/Fractal/Lint/Rules/FractalIncludeTransformerMissing.php`
- Test: `tests/Unit/Plugins/Fractal/Lint/FractalIncludeTransformerMissingTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalIncludeTransformerMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:fractal');

#[TransformerField('id', type: 'integer')]
#[TransformerInclude('comments')]
class IncludelessTransformer {}

class IncludeLintController
{
    #[FractalResponse(transformer: IncludelessTransformer::class)]
    public function show(): void {}
}

it('flags a #[TransformerInclude] with no transformer class', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new \ReflectionClass(IncludeLintController::class),
        method: new \ReflectionMethod(IncludeLintController::class, 'show'),
        summary: null,
        description: null,
    );

    $rule = new FractalIncludeTransformerMissing();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        new LintContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.include-transformer-missing')
        ->and($findings[0]->message)->toContain('comments');
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Lint/FractalIncludeTransformerMissingTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use ReflectionClass;

use function class_exists;
use function sprintf;

/**
 * Flags a `#[TransformerInclude]` declared with no `transformer` class—the
 * included resource is emitted as an opaque `type: object`.
 */
final readonly class FractalIncludeTransformerMissing implements Rule, OperationRule
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $method = $operation->descriptor?->method;

        if ($operation->webhook || $method === null) {
            return;
        }

        $attribute = $method->getAttributes(FractalResponse::class)[0] ?? null;

        if ($attribute === null) {
            return;
        }

        $transformer = $attribute->newInstance()->transformer;

        if (!class_exists($transformer)) {
            return;
        }

        foreach ((new ReflectionClass($transformer))->getAttributes(TransformerInclude::class) as $includeAttribute) {
            $include = $includeAttribute->newInstance();

            if ($include->transformer !== null) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '#[TransformerInclude(\'%s\')] on %s has no transformer—the include is emitted as an opaque object',
                    $include->name,
                    $transformer,
                ),
                fixHint: 'Add transformer: to #[TransformerInclude] naming the included resource\'s transformer.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'fractal.include-transformer-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A #[TransformerInclude] is declared without a transformer class.';
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Lint/FractalIncludeTransformerMissingTest.php`
Expected: PASS—1 test.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/Fractal/Lint/Rules/FractalIncludeTransformerMissing.php tests/Unit/Plugins/Fractal/Lint/FractalIncludeTransformerMissingTest.php
git commit -m "feat: add fractal.include-transformer-missing lint rule"
```

---

## Task 11: Lint rule `fractal.response-unbound`

High severity (`level: 1`). Flags a controller method that **injects a `league/fractal` `Manager`** (a parameter typed `League\Fractal\Manager`) yet carries no `#[FractalResponse]`—the endpoint produces Fractal output the document does not describe.

**Design decision—conservative detection (and what it misses).** As with the QueryBuilder plugin's `query-builder.params-undeclared`, the only body-free signal of Fractal intent is an injected dependency. The rule keys off a `League\Fractal\Manager` parameter, matched by FQCN **string** so neither the package nor the loaded class is required. This is narrow rather than heuristic—and worth being honest about: the rule will **not** fire for the common patterns of using the `fractal()` helper or the `Spatie\Fractalistic\Fractal` facade inside the method body, which are the dominant usage patterns in Laravel codebases. The rule's `description()` and the usage doc (Task 13) state this explicitly so users don't read silence as endorsement.

**Files:**
- Create: `src/Plugins/Fractal/Lint/Rules/FractalResponseUnbound.php`
- Test: `tests/Unit/Plugins/Fractal/Lint/FractalResponseUnboundTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalResponseUnbound;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:fractal');

if (!class_exists('League\\Fractal\\Manager')) {
    class_alias(\stdClass::class, 'League\\Fractal\\Manager');
}

class UnboundFractalTransformer {}

class FractalUnboundController
{
    public function unbound(\League\Fractal\Manager $fractal): void {}

    #[FractalResponse(transformer: UnboundFractalTransformer::class)]
    public function bound(\League\Fractal\Manager $fractal): void {}
}

function fractalUnboundDescriptor(string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new \ReflectionClass(FractalUnboundController::class),
        method: new \ReflectionMethod(FractalUnboundController::class, $method),
        summary: null,
        description: null,
    );
}

it('flags a method injecting Fractal Manager with no #[FractalResponse]', function (): void {
    $rule = new FractalResponseUnbound();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor(fractalUnboundDescriptor('unbound')),
        new LintContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.response-unbound');
});

it('does not flag a method that declares #[FractalResponse]', function (): void {
    $rule = new FractalResponseUnbound();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor(fractalUnboundDescriptor('bound')),
        new LintContext(),
    ));

    expect($findings)->toBe([]);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Lint/FractalResponseUnboundTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use ReflectionMethod;
use ReflectionNamedType;

use function sprintf;

/**
 * Flags a controller method that injects a `league/fractal` `Manager` but
 * carries no `#[FractalResponse]`—it produces Fractal output the generated
 * document does not describe.
 *
 * Detection is deliberately conservative: it keys off an injected `Manager`
 * parameter (matched by FQCN string, so the package need not be installed),
 * not a body-inference heuristic. The rule will NOT fire for the common
 * patterns of using the `fractal()` helper or the `Spatie\Fractalistic\Fractal`
 * facade inside the method body—under OAPI-017 the generator does not read
 * method bodies. See `docs/known-gaps.md` (OAPI-053).
 */
final readonly class FractalResponseUnbound implements Rule, OperationRule
{
    private const string MANAGER_CLASS = 'League\\Fractal\\Manager';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $method = $operation->descriptor?->method;

        if ($operation->webhook || $method === null) {
            return;
        }

        if (!$this->injectsFractalManager($method)) {
            return;
        }

        if ($method->getAttributes(FractalResponse::class) !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s injects a Fractal Manager but declares no #[FractalResponse]',
                $operation->method,
                $operation->pathUri,
            ),
            fixHint: 'Add #[FractalResponse(transformer: SomeTransformer::class)] to the action.',
        );
    }

    private function injectsFractalManager(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === self::MANAGER_CLASS) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function id(): string
    {
        return 'fractal.response-unbound';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A method injects a Fractal Manager but declares no #[FractalResponse]. '
            . 'Does not fire for fractal() helper or Fractal facade usage inside method bodies '
            . '(method-body inference is out of scope under OAPI-017).';
    }
}
```

- [x] **Step 4: Run the test**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Lint/FractalResponseUnboundTest.php`
Expected: PASS—2 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/Fractal/Lint/Rules/FractalResponseUnbound.php tests/Unit/Plugins/Fractal/Lint/FractalResponseUnboundTest.php
git commit -m "feat: add fractal.response-unbound lint rule"
```

---

## Task 12: Lint rule `fractal.duplicate-key`

High severity (`level: 1`). Flags a transformer that declares two or more output keys with the same name—across `#[TransformerField]` and/or `#[TransformerInclude]`. swagger-php would happily emit both properties; the result is an invalid OpenAPI schema. Operation-level rule, fires once per duplicated name on the transformer bound by `#[FractalResponse]`.

The `SchemaFromTransformer` builder intentionally does not deduplicate (matches `SchemaFromResource`'s behavior). This rule is the single source of truth for catching the mistake.

**Files:**
- Create: `src/Plugins/Fractal/Lint/Rules/FractalDuplicateKey.php`
- Test: `tests/Unit/Plugins/Fractal/Lint/FractalDuplicateKeyTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Lint;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalDuplicateKey;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:fractal');

#[TransformerField('id', type: 'integer')]
#[TransformerField('author', type: 'string')]
#[TransformerInclude('author', transformer: \stdClass::class)]
class DuplicateKeyTransformer {}

class DuplicateKeyController
{
    #[FractalResponse(transformer: DuplicateKeyTransformer::class)]
    public function show(): void {}
}

function duplicateKeyDescriptor(): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new \ReflectionClass(DuplicateKeyController::class),
        method: new \ReflectionMethod(DuplicateKeyController::class, 'show'),
        summary: null,
        description: null,
    );
}

it('flags a transformer with a name shared between #[TransformerField] and #[TransformerInclude]', function (): void {
    $rule = new FractalDuplicateKey();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor(duplicateKeyDescriptor()),
        new LintContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('fractal.duplicate-key')
        ->and($findings[0]->message)->toContain("'author'");
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Lint/FractalDuplicateKeyTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use ReflectionClass;

use function class_exists;
use function sprintf;

/**
 * Flags a transformer that declares the same output key more than once across
 * `#[TransformerField]` and/or `#[TransformerInclude]`. swagger-php emits both
 * properties; the resulting OpenAPI schema is invalid.
 */
final readonly class FractalDuplicateKey implements Rule, OperationRule
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $method = $operation->descriptor?->method;

        if ($operation->webhook || $method === null) {
            return;
        }

        $attribute = $method->getAttributes(FractalResponse::class)[0] ?? null;

        if ($attribute === null) {
            return;
        }

        $transformer = $attribute->newInstance()->transformer;

        if (!class_exists($transformer)) {
            return;
        }

        $reflection = new ReflectionClass($transformer);

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($reflection->getAttributes(TransformerField::class) as $fieldAttribute) {
            $name = $fieldAttribute->newInstance()->name;
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        foreach ($reflection->getAttributes(TransformerInclude::class) as $includeAttribute) {
            $name = $includeAttribute->newInstance()->name;
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        foreach ($counts as $name => $count) {
            if ($count < 2) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '%s declares the key \'%s\' %d times across #[TransformerField]/#[TransformerInclude]',
                    $transformer,
                    $name,
                    $count,
                ),
                fixHint: 'Remove the duplicate declaration; each output key must be declared exactly once.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'fractal.duplicate-key';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A transformer declares the same output key in more than one #[TransformerField]/#[TransformerInclude].';
    }
}
```

- [x] **Step 4: Run the full suite, lint, and analysis**

Run: `vendor/bin/pest tests/Unit/Plugins/Fractal/Lint/FractalDuplicateKeyTest.php && composer test && vendor/bin/pint --test && composer analyse`
Expected: the new test passes; the full suite is green; Pint clean; PHPStan clean.

- [x] **Step 5: Commit**

```bash
git add src/Plugins/Fractal/Lint/Rules/FractalDuplicateKey.php tests/Unit/Plugins/Fractal/Lint/FractalDuplicateKeyTest.php
git commit -m "feat: add fractal.duplicate-key lint rule"
```

---

## Task 13: Documentation

**Files:**
- Modify: `CHANGELOG.md`, `docs/usage.md`, `../../internal/known-gaps.md`

- [x] **Step 1: Add a `CHANGELOG.md` entry**

Under `## [Unreleased]` → `### Added`, append:

```markdown
- `league/fractal` transformer responses are now documented via the optional
  `FractalPlugin` (shipped disabled—uncomment it in `config/openapi.php` after
  installing the package). Transformers declare output keys with
  `#[TransformerField]` and includes with `#[TransformerInclude]`; endpoints bind
  to a transformer with `#[FractalResponse]`, which accepts `collection: true`
  for a flat collection and `paginated: true` for a paginated one (envelope
  includes `meta.pagination` matching Fractal's `IlluminatePaginatorAdapter`).
  Four lint rules (`fractal.response-unbound`, `fractal.fields-undeclared`,
  `fractal.include-transformer-missing`, `fractal.duplicate-key`) report
  incomplete or invalid declarations.
```

- [x] **Step 2: Update `docs/usage.md`**

Add a short subsection: how to enable `FractalPlugin` (uncomment in config + `composer require league/fractal`), how to declare the three attributes (`#[TransformerField]`, `#[TransformerInclude]`, `#[FractalResponse]` with its `collection` / `paginated` flags), and a one-line note that `fractal.response-unbound` only fires for `Manager`-injected methods—not for the `fractal()` helper or facade. Keep it to the minimal observable-behaviour description CLAUDE.md mandates.

- [x] **Step 3: Update `../../internal/known-gaps.md`**

In the OAPI-017 section, note that Fractal response shapes are derived from `#[TransformerField]` / `#[TransformerInclude]` / `#[FractalResponse]` attributes rather than from `transform()` method bodies or Fractal manager calls.

Add two new gap entries under their own headings, formatted to match existing entries:

- **OAPI-052: Fractal serializer assumed to be `DataArraySerializer`.** The emitted envelopes—`{data}`, `{data: [...]}`, `{data: [...], meta: {pagination: {…}}}`—model Fractal's default `DataArraySerializer` + `IlluminatePaginatorAdapter`. Other serializers (`JsonApiSerializer`, `ArraySerializer`, custom) produce different shapes and are not modelled; the generated document will not match the runtime output for endpoints using them. Workaround: use `#[Response]` to override the schema for those endpoints.
- **OAPI-053: `fractal.response-unbound` does not detect `fractal()` helper / facade usage.** The lint rule keys off an injected `League\Fractal\Manager` parameter (a body-free signal). It does not flag methods that use the `fractal()` helper or `Spatie\Fractalistic\Fractal` facade inside the method body, because the generator does not read method bodies (OAPI-017). Endpoints using those patterns will silently produce undocumented Fractal output.

- [x] **Step 4: Run the full suite once more**

Run: `vendor/bin/pint && composer test && composer analyse`
Expected: Pint clean; suite green; PHPStan clean.

- [x] **Step 5: Commit**

```bash
git add CHANGELOG.md docs/usage.md docs/known-gaps.md
git commit -m "docs: document the Fractal plugin"
```

---

## Self-Review

**Spec coverage:** `FractalPlugin` under `src/Plugins/Fractal/` (Task 7); `#[TransformerField]` repeatable class-level (Task 1); `#[TransformerInclude]` repeatable class-level with `transformer` + `default`, modelling `availableIncludes` / `defaultIncludes` (Tasks 2, 3); `#[FractalResponse]` method-only binding with `transformer` + `collection` + `paginated` (Tasks 2, 6); primary-response resolver (Task 6) and ref-schema resolver (Task 4); envelope shapes split into `FractalEnvelopeFactory` (Task 5); nested class-string shorthand via the ref resolvers (Task 3); the four lint rules with the spec's IDs and severities (Tasks 9–12); shipped commented-out in config (Task 7); unit + feature tests (every task); `CHANGELOG.md` + `docs/usage.md` + `../../internal/known-gaps.md` per-change updates (Task 13).

**Type consistency:** `TransformerField` exposes `name` + `type` + `descriptor()` + `conditional` + `nullable`, consumed identically in Tasks 3, 9, 12. `TransformerInclude` exposes `name` + `?transformer` + `default`, consumed in Tasks 3, 10, 12. `FractalResponse` exposes `transformer` + `collection` + `paginated`, consumed in Tasks 6, 9, 10, 11, 12. `SchemaFromTransformer::build(): string` returns a bare component key; `buildRef(): string` returns the qualified `#/components/schemas/<key>` ref—Tasks 4 and 6 use `buildRef()`. A "transformer" is consistently defined as "a class carrying `#[TransformerField]`" across Tasks 3, 4, 9, 12.

**Codebase alignment:** Task 3 uses the public `ComponentSchemaRegistry::buildOnce()` API (not the now-private `markInProgress`/`markComplete` calls earlier drafts named) and `SchemaDescriptor::applyTo()` for scalar field properties—matching `SchemaFromResource`. Task 5 splits envelope shape into a factory, mirroring `ResourceEnvelopeFactory`. Task 2 drops `TARGET_FUNCTION` from `#[FractalResponse]`'s attribute target to match the codebase's method-only endpoint-attribute convention.

**Design decisions documented:** the plugin never references `League\Fractal\TransformerAbstract`; `fractal.response-unbound` keys off an injected `League\Fractal\Manager` parameter and is honest about what it misses (Task 11, OAPI-053). The envelope shapes assume `DataArraySerializer` + `IlluminatePaginatorAdapter` (OAPI-052). Duplicate key detection lives in `fractal.duplicate-key` rather than as silent dedup in the builder, matching the codebase's lint-as-warning pattern (Task 12).

**Done criteria:**
- `composer test` is green with the new unit + feature tests.
- `vendor/bin/pint` reports no violations; `composer analyse` reports no errors.
- A controller method with `#[FractalResponse]` produces a `{data}` (single), `{data: [...]}` (collection), or `{data: [...], meta: {pagination: {…}}}` (paginated) response once `FractalPlugin` is enabled; the transformer is registered as a component schema.

## Next plan in this program

5. composer.json + config-defaults wiring—`docs/superpowers/plans/2026-05-19-plugin-suite-wiring.md`.
