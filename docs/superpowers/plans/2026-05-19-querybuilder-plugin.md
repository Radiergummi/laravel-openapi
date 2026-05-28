# QueryBuilder Plugin Implementation Plan

> **Read first:** `docs/superpowers/plans/plugin-suite-program.md`—the program tracker with shared ground rules, locked cross-cutting decisions, build order, and live status.
>
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Teach the OpenAPI core to document the `filter[...]`, `sort`, and `include` query-string parameters that `spatie/laravel-query-builder` endpoints accept, deriving them from method-level `#[AllowedFilter]`, `#[AllowedSort]`, and `#[AllowedInclude]` attributes.

**Architecture:** This is build step 3 of 5 in the plugin-suite program (spec: `docs/superpowers/specs/2026-05-18-plugin-suite-design.md`). It depends on build step 1 being merged. A query-builder call (`QueryBuilder::for(Model::class)->allowedFilters(...)`) defines the accepted parameters in a method body, which the generator never reads (OAPI-017); the plugin resolves them from attributes instead. The plugin registers one `QueryParameterResolver` (the first the package ships) and two lint rules. **No third-party dependency at generation time**—the plugin only reads its own attributes; `spatie/laravel-query-builder` is added to `require-dev` / `suggest` in build step 5. The plugin is **shipped commented-out** in `config/openapi.php`; users uncomment it after installing the package.

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
| `src/Plugins/QueryBuilder/Attributes/AllowedFilter.php` (create) | Repeatable method-level attribute—one `filter[name]` parameter. |
| `src/Plugins/QueryBuilder/Attributes/AllowedSort.php` (create) | Method-level attribute—the `sort` parameter and its allowed fields. |
| `src/Plugins/QueryBuilder/Attributes/AllowedInclude.php` (create) | Method-level attribute—the `include` parameter and its allowed relations. |
| `src/Plugins/QueryBuilder/QueryBuilderParameterResolver.php` (create) | `QueryParameterResolver` turning the attributes into `OA\Parameter`s. |
| `src/Plugins/QueryBuilder/QueryBuilderPlugin.php` (create) | `Plugin`—registers the resolver and lint rules. |
| `src/Plugins/QueryBuilder/Lint/Rules/QueryBuilderParamsUndeclared.php` (create) | Lint rule `query-builder.params-undeclared`. |
| `src/Plugins/QueryBuilder/Lint/Rules/QueryBuilderFilterTypeMissing.php` (create) | Lint rule `query-builder.filter-type-missing`. |
| `config/openapi.php` (modify) | Add a commented-out `QueryBuilderPlugin::class` entry. |
| `tests/Feature/Plugins/QueryBuilder/QueryBuilderParameterTest.php` (create) | End-to-end document generation. |
| `tests/Unit/Plugins/QueryBuilder/*` (create) | Unit tests for the resolver and lint rules. |
| `../../internal/known-gaps.md`, `CHANGELOG.md`, `docs/usage.md` (modify) | Per-change doc obligations. |

**No `OpenApiServiceProvider` change is needed:** `QueryBuilderParameterResolver`, the two lint rules, and `QueryBuilderPlugin` have no constructor dependencies and are autowired by the container.

---

## Task 1: `AllowedFilter` attribute

`#[AllowedFilter]` is repeatable and method-level. It extends the Core `FieldAttribute` base (the full JSON-Schema field surface), adding `name`. Each instance emits one `filter[name]` query parameter.

**Files:**
- Create: `src/Plugins/QueryBuilder/Attributes/AllowedFilter.php`
- Test: `tests/Unit/Plugins/QueryBuilder/Attributes/AllowedFilterTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Attributes;

use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;

it('exposes its name and forwards schema fields to the descriptor', function (): void {
    $filter = new AllowedFilter('status', type: 'string', description: 'Filter by status.');

    expect($filter->name)->toBe('status')
        ->and($filter->type)->toBe('string')
        ->and($filter->descriptor()->description)->toBe('Filter by status.');
});

it('is repeatable and targets methods', function (): void {
    $attribute = (new \ReflectionClass(AllowedFilter::class))
        ->getAttributes(\Attribute::class)[0]->newInstance();

    expect($attribute->flags & \Attribute::IS_REPEATABLE)->toBe(\Attribute::IS_REPEATABLE)
        ->and($attribute->flags & \Attribute::TARGET_METHOD)->toBe(\Attribute::TARGET_METHOD);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/Attributes/AllowedFilterTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes;

/**
 * Declares one `spatie/laravel-query-builder` allowed filter—emitted as a
 * `filter[name]` query-string parameter. Repeatable and method-level.
 *
 * ```php
 * #[AllowedFilter('status', type: 'string')]
 * #[AllowedFilter('created_after', type: 'string', format: 'date')]
 * public function index(): JsonResponse { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class AllowedFilter extends FieldAttribute
{
    /**
     * @param string                           $name The filter key—becomes `filter[name]`.
     * @param null|list<BackedEnum|int|string> $enum
     */
    public function __construct(
        public string $name,
        ?string $title = null,
        ?string $description = null,
        mixed $example = null,
        ?string $type = null,
        ?string $format = null,
        ?array $enum = null,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
    ) {
        parent::__construct(
            title: $title,
            description: $description,
            example: $example,
            type: $type,
            format: $format,
            enum: $enum,
            minimum: $minimum,
            maximum: $maximum,
            minLength: $minLength,
            maxLength: $maxLength,
            pattern: $pattern,
        );
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/Attributes/AllowedFilterTest.php`
Expected: PASS—2 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/QueryBuilder/Attributes/AllowedFilter.php tests/Unit/Plugins/QueryBuilder/Attributes/AllowedFilterTest.php
git commit -m "feat: add AllowedFilter attribute for QueryBuilder plugin"
```

---

## Task 2: `AllowedSort` and `AllowedInclude` attributes

Both are method-level and **not** repeatable—each endpoint emits exactly one `sort` and one `include` parameter. Each carries a `list<string>` of allowed values; they do not need the JSON-Schema field surface, so they do not extend `FieldAttribute`.

**Files:**
- Create: `src/Plugins/QueryBuilder/Attributes/AllowedSort.php`
- Create: `src/Plugins/QueryBuilder/Attributes/AllowedInclude.php`
- Test: `tests/Unit/Plugins/QueryBuilder/Attributes/AllowedSortIncludeTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Attributes;

use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;

it('stores the allowed sort fields', function (): void {
    $sort = new AllowedSort(['name', 'created_at']);

    expect($sort->fields)->toBe(['name', 'created_at']);
});

it('stores the allowed include relations', function (): void {
    $include = new AllowedInclude(['owner', 'tags']);

    expect($include->names)->toBe(['owner', 'tags']);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/Attributes/AllowedSortIncludeTest.php`
Expected: FAIL—classes not found.

- [x] **Step 3: Write the implementations**

`src/Plugins/QueryBuilder/Attributes/AllowedSort.php`:

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes;

use Attribute;

/**
 * Declares the `spatie/laravel-query-builder` allowed sorts for an endpoint —
 * emitted as the `sort` query-string parameter. Method-level, not repeatable.
 *
 * ```php
 * #[AllowedSort(['name', 'created_at'])]
 * public function index(): JsonResponse { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class AllowedSort
{
    /**
     * @param list<string> $fields Sortable field names. The wire syntax allows a `-` prefix for descending order.
     */
    public function __construct(
        public array $fields,
    ) {}
}
```

`src/Plugins/QueryBuilder/Attributes/AllowedInclude.php`:

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes;

use Attribute;

/**
 * Declares the `spatie/laravel-query-builder` allowed includes for an endpoint
 *—emitted as the `include` query-string parameter. Method-level, not
 * repeatable.
 *
 * ```php
 * #[AllowedInclude(['owner', 'tags'])]
 * public function index(): JsonResponse { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class AllowedInclude
{
    /**
     * @param list<string> $names Includable relationship names.
     */
    public function __construct(
        public array $names,
    ) {}
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/Attributes/AllowedSortIncludeTest.php`
Expected: PASS—2 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/QueryBuilder/Attributes/AllowedSort.php src/Plugins/QueryBuilder/Attributes/AllowedInclude.php tests/Unit/Plugins/QueryBuilder/Attributes/AllowedSortIncludeTest.php
git commit -m "feat: add AllowedSort and AllowedInclude attributes"
```

---

## Task 3: `QueryBuilderParameterResolver`

A `QueryParameterResolver`: reads the three attribute families from the action reflector and returns `OA\Parameter`s.

- Each `#[AllowedFilter]` → a `filter[name]` parameter; schema from the attribute's `descriptor()`, defaulting to `type: string`.
- `#[AllowedSort]` → one `sort` parameter; a comma-separated list (`style: form`, `explode: false`) of strings whose `enum` is the allowed fields.
- `#[AllowedInclude]` → one `include` parameter; same shape, `enum` is the allowed relations.

All parameters are optional (`required: false`).

**Files:**
- Create: `src/Plugins/QueryBuilder/QueryBuilderParameterResolver.php`
- Test: `tests/Unit/Plugins/QueryBuilder/QueryBuilderParameterResolverTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder;

use Illuminate\Routing\Route;use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderParameterResolver;use Radiergummi\OpenApi\Routing\ActionDescriptor;use ReflectionClass;use ReflectionMethod;

class QbResolverController
{
    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('priority', type: 'integer')]
    #[AllowedSort(['name', 'created_at'])]
    #[AllowedInclude(['owner'])]
    public function index(): void {}

    public function bare(): void {}
}

function qbDescriptor(string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new ReflectionClass(QbResolverController::class),
        method: new ReflectionMethod(QbResolverController::class, $method),
        summary: null,
        description: null,
    );
}

/** @return list<string> */
function parameterNames(array $parameters): array
{
    return array_map(static fn(OA\Parameter $p): string => $p->name, $parameters);
}

it('emits a filter[...] parameter per #[AllowedFilter]', function (): void {
    $params = (new QueryBuilderParameterResolver())->resolveQueryParameters(qbDescriptor('index'));
    $names = parameterNames($params);

    expect($names)->toContain('filter[status]')
        ->and($names)->toContain('filter[priority]');
});

it('emits a single sort parameter with the allowed fields as enum', function (): void {
    $params = (new QueryBuilderParameterResolver())->resolveQueryParameters(qbDescriptor('index'));

    $sort = null;
    foreach ($params as $p) {
        if ($p->name === 'sort') {
            $sort = $p;
        }
    }

    expect($sort)->not->toBeNull()
        ->and($sort->in)->toBe('query')
        ->and($sort->schema->items->enum)->toBe(['name', 'created_at']);
});

it('emits a single include parameter', function (): void {
    $params = (new QueryBuilderParameterResolver())->resolveQueryParameters(qbDescriptor('index'));

    expect(parameterNames($params))->toContain('include');
});

it('returns an empty array when no query-builder attributes are present', function (): void {
    expect((new QueryBuilderParameterResolver())->resolveQueryParameters(qbDescriptor('bare')))
        ->toBe([]);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/QueryBuilderParameterResolverTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder;

use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;use Radiergummi\OpenApi\Routing\ActionDescriptor;use function sprintf;

/**
 * Turns the QueryBuilder plugin's `#[AllowedFilter]`, `#[AllowedSort]`, and
 * `#[AllowedInclude]` attributes into OpenAPI query parameters.
 */
final readonly class QueryBuilderParameterResolver implements QueryParameterResolver
{
    /**
     * @return list<OA\Parameter>
     */
    public function resolveQueryParameters(ActionDescriptor $descriptor): array
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return [];
        }

        $parameters = [];

        foreach ($reflector->getAttributes(AllowedFilter::class) as $attribute) {
            $parameters[] = $this->filterParameter($attribute->newInstance());
        }

        $sortAttributes = $reflector->getAttributes(AllowedSort::class);

        if ($sortAttributes !== []) {
            $parameters[] = $this->listParameter(
                name: 'sort',
                values: $sortAttributes[0]->newInstance()->fields,
                description: 'Comma-separated sort fields. Prefix a field with `-` for descending order.',
            );
        }

        $includeAttributes = $reflector->getAttributes(AllowedInclude::class);

        if ($includeAttributes !== []) {
            $parameters[] = $this->listParameter(
                name: 'include',
                values: $includeAttributes[0]->newInstance()->names,
                description: 'Comma-separated related resources to include.',
            );
        }

        return $parameters;
    }

    private function filterParameter(AllowedFilter $filter): OA\Parameter
    {
        $schemaProps = $filter->descriptor()->toOpenApi();

        if (!isset($schemaProps['type'])) {
            $schemaProps['type'] = 'string';
        }

        return new OA\Parameter([
            'name' => sprintf('filter[%s]', $filter->name),
            'in' => 'query',
            'required' => false,
            'schema' => new OA\Schema($schemaProps),
        ]);
    }

    /**
     * @param list<string> $values
     */
    private function listParameter(string $name, array $values, string $description): OA\Parameter
    {
        $itemProps = ['type' => 'string'];

        if ($values !== []) {
            $itemProps['enum'] = $values;
        }

        return new OA\Parameter([
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'style' => 'form',
            'explode' => false,
            'schema' => new OA\Schema([
                'type' => 'array',
                'items' => new OA\Items($itemProps),
            ]),
        ]);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/QueryBuilderParameterResolverTest.php`
Expected: PASS—4 tests.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/QueryBuilder/QueryBuilderParameterResolver.php tests/Unit/Plugins/QueryBuilder/QueryBuilderParameterResolverTest.php
git commit -m "feat: add QueryBuilderParameterResolver"
```

---

## Task 4: `QueryBuilderPlugin` + config

`QueryBuilderPlugin` registers the resolver and the two lint rules (created in Tasks 6–7—register all now; the suite goes green at the end of Task 7). The plugin ships **commented-out** in `config/openapi.php`.

**Files:**
- Create: `src/Plugins/QueryBuilder/QueryBuilderPlugin.php`
- Modify: `config/openapi.php`

- [x] **Step 1: Write `QueryBuilderPlugin`**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder;

use Radiergummi\OpenApi\Contracts\Registry\Plugin;use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderFilterTypeMissing;use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderParamsUndeclared;use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * Teaches the OpenAPI core to document `spatie/laravel-query-builder`
 * filter/sort/include query parameters.
 */
final class QueryBuilderPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addQueryParameterResolver(QueryBuilderParameterResolver::class);
        $registry->addRule(QueryBuilderParamsUndeclared::class);
        $registry->addRule(QueryBuilderFilterTypeMissing::class);
    }
}
```

- [x] **Step 2: Add the commented-out config entry**

In `config/openapi.php`, change the `plugins` array (which by build step 2 also contains `ApiResourcesPlugin::class`) to add a commented line. Final state:

```php
    'plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,

        // Requires `composer require spatie/laravel-query-builder`. Uncomment to enable:
        // \Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin::class,
    ],
```

(Use the fully-qualified class name in the comment so no unused `use` import is added for a disabled plugin.)

- [x] **Step 3: Run lint + analyse**

Run: `vendor/bin/pint && composer analyse`
Expected: Pint clean; PHPStan clean. (`composer test` fails until Tasks 6–7 create the two lint-rule classes—expected.)

- [x] **Step 4: Commit**

```bash
git add src/Plugins/QueryBuilder/QueryBuilderPlugin.php config/openapi.php
git commit -m "feat: add QueryBuilderPlugin (shipped disabled)"
```

---

## Task 5: Feature test—query-builder parameters end-to-end

The plugin is disabled by default, so the test enables it via a `config()` override in `beforeEach` (set before the `scoped` `OpenApiRegistry` is first resolved). Fixture controller carries the attributes; no `spatie/laravel-query-builder` package is needed.

**Files:**
- Create: `tests/Feature/Plugins/QueryBuilder/QueryBuilderParameterTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\QueryBuilder;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi', 'plugin:query-builder');

beforeEach(function (): void {
    config(['openapi.plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,
        QueryBuilderPlugin::class,
    ]]);
});

class QbFixtureController extends Controller
{
    /** List widgets. */
    #[AllowedFilter('status', type: 'string')]
    #[AllowedSort(['name', 'created_at'])]
    #[AllowedInclude(['owner'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([]);
    }
}

it('documents filter, sort, and include query parameters', function (): void {
    Route::get('/qb-widgets', [QbFixtureController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $parameters = $spec['paths']['/qb-widgets']['get']['parameters'] ?? [];

    $names = array_map(static fn(array $p): string => $p['name'], $parameters);

    expect($names)->toContain('filter[status]')
        ->and($names)->toContain('sort')
        ->and($names)->toContain('include');

    foreach ($parameters as $parameter) {
        expect($parameter['in'])->toBe('query');
    }
});
```

- [x] **Step 2: Run the test**

Run: `vendor/bin/pest tests/Feature/Plugins/QueryBuilder/QueryBuilderParameterTest.php`
Expected: PASS—1 test. (If the two lint-rule classes are still missing, complete Tasks 6–7 first.)

- [x] **Step 3: Commit**

```bash
vendor/bin/pint
git add tests/Feature/Plugins/QueryBuilder/QueryBuilderParameterTest.php
git commit -m "test: cover query-builder parameter generation end-to-end"
```

---

## Task 6: Lint rule `query-builder.filter-type-missing`

Low severity (`level: 3`). Flags any `#[AllowedFilter]` on an operation's method whose `type` is `null`—the filter parameter is emitted with the default `string` type, which may be wrong.

**Files:**
- Create: `src/Plugins/QueryBuilder/Lint/Rules/QueryBuilderFilterTypeMissing.php`
- Test: `tests/Unit/Plugins/QueryBuilder/Lint/QueryBuilderFilterTypeMissingTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Lint;

use Illuminate\Routing\Route;use Radiergummi\OpenApi\Lint\LintContext;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderFilterTypeMissing;use Radiergummi\OpenApi\Routing\ActionDescriptor;use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:query-builder');

class FilterTypeLintController
{
    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('mystery')]
    public function index(): void {}
}

it('flags an #[AllowedFilter] declared without a type', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new \ReflectionClass(FilterTypeLintController::class),
        method: new \ReflectionMethod(FilterTypeLintController::class, 'index'),
        summary: null,
        description: null,
    );

    $rule = new QueryBuilderFilterTypeMissing();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor($descriptor),
        new LintContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('query-builder.filter-type-missing')
        ->and($findings[0]->message)->toContain('mystery');
});
```

> **Test helper:** `OperationNodeFactory::forDescriptor()` is the shared helper introduced in the ApiResources plan (Task 10). It already exists if that plan is complete; otherwise create `tests/Support/OperationNodeFactory.php` per that plan's description.

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/Lint/QueryBuilderFilterTypeMissingTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules;

use Override;use Radiergummi\OpenApi\Contracts\Lint\Rule;use Radiergummi\OpenApi\Lint\Finding;use Radiergummi\OpenApi\Lint\LintContext;use Radiergummi\OpenApi\Lint\Tree\OperationNode;use Radiergummi\OpenApi\Lint\Visitors\OperationRule;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;use function sprintf;

/**
 * Flags an `#[AllowedFilter]` declared with no `type`—the filter parameter
 * falls back to `string`, which may misrepresent the accepted value.
 */
final readonly class QueryBuilderFilterTypeMissing implements Rule, OperationRule
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

        foreach ($method->getAttributes(AllowedFilter::class) as $attribute) {
            $filter = $attribute->newInstance();

            if ($filter->type !== null) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '#[AllowedFilter(\'%s\')] on %s %s has no type—the filter parameter defaults to string',
                    $filter->name,
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Add a type: to #[AllowedFilter] (\'string\', \'integer\', \'boolean\', …).',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'query-builder.filter-type-missing';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'An #[AllowedFilter] is declared without an explicit value type.';
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/Lint/QueryBuilderFilterTypeMissingTest.php`
Expected: PASS—1 test.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint
git add src/Plugins/QueryBuilder/Lint/Rules/QueryBuilderFilterTypeMissing.php tests/Unit/Plugins/QueryBuilder/Lint/QueryBuilderFilterTypeMissingTest.php
git commit -m "feat: add query-builder.filter-type-missing lint rule"
```

---

## Task 7: Lint rule `query-builder.params-undeclared`

Medium severity (`level: 2`). Flags an operation whose controller method **injects a `spatie/laravel-query-builder` `QueryBuilder`** (a method parameter typed `Spatie\QueryBuilder\QueryBuilder`) yet declares **none** of `#[AllowedFilter]` / `#[AllowedSort]` / `#[AllowedInclude]`.

**Design decision—conservative detection.** Without method-body inference the generator cannot see `QueryBuilder::for(...)` calls inside a body. The one body-free signal of query-builder intent is an injected `QueryBuilder` parameter, so the rule keys off exactly that. The package class name is compared as a **string** (`$type->getName() === 'Spatie\\QueryBuilder\\QueryBuilder'`) so the rule needs neither the package installed nor the class loaded. The rule is intentionally narrow (few false positives) rather than heuristic; Medium severity keeps it off at the default lint level.

**Files:**
- Create: `src/Plugins/QueryBuilder/Lint/Rules/QueryBuilderParamsUndeclared.php`
- Test: `tests/Unit/Plugins/QueryBuilder/Lint/QueryBuilderParamsUndeclaredTest.php`

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder\Lint;

use Illuminate\Routing\Route;use Radiergummi\OpenApi\Lint\LintContext;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;use Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules\QueryBuilderParamsUndeclared;use Radiergummi\OpenApi\Routing\ActionDescriptor;use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'plugin:query-builder');

/**
 * A stand-in for `Spatie\QueryBuilder\QueryBuilder`. The rule matches the type
 * name as a string, so the fixture method below declares the real FQCN via a
 * class_alias so the test does not require the package.
 */
if (!class_exists('Spatie\\QueryBuilder\\QueryBuilder')) {
    class_alias(\stdClass::class, 'Spatie\\QueryBuilder\\QueryBuilder');
}

class ParamsUndeclaredController
{
    public function undeclared(\Spatie\QueryBuilder\QueryBuilder $query): void {}

    #[AllowedFilter('status', type: 'string')]
    public function declared(\Spatie\QueryBuilder\QueryBuilder $query): void {}
}

function paramsUndeclaredDescriptor(string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new \ReflectionClass(ParamsUndeclaredController::class),
        method: new \ReflectionMethod(ParamsUndeclaredController::class, $method),
        summary: null,
        description: null,
    );
}

it('flags a method injecting QueryBuilder with no query-builder attributes', function (): void {
    $rule = new QueryBuilderParamsUndeclared();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor(paramsUndeclaredDescriptor('undeclared')),
        new LintContext(),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('query-builder.params-undeclared');
});

it('does not flag a method that declares query-builder attributes', function (): void {
    $rule = new QueryBuilderParamsUndeclared();
    $findings = iterator_to_array($rule->checkOperation(
        OperationNodeFactory::forDescriptor(paramsUndeclaredDescriptor('declared')),
        new LintContext(),
    ));

    expect($findings)->toBe([]);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/Lint/QueryBuilderParamsUndeclaredTest.php`
Expected: FAIL—class not found.

- [x] **Step 3: Write minimal implementation**

```php
<?php

// <copyright header>

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules;

use Override;use Radiergummi\OpenApi\Contracts\Lint\Rule;use Radiergummi\OpenApi\Lint\Finding;use Radiergummi\OpenApi\Lint\LintContext;use Radiergummi\OpenApi\Lint\Tree\OperationNode;use Radiergummi\OpenApi\Lint\Visitors\OperationRule;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;use ReflectionMethod;use ReflectionNamedType;use function sprintf;

/**
 * Flags a controller method that injects a `spatie/laravel-query-builder`
 * `QueryBuilder` but declares none of `#[AllowedFilter]`, `#[AllowedSort]`, or
 * `#[AllowedInclude]`—the endpoint accepts filter/sort/include parameters
 * that the generated document does not describe.
 *
 * Detection is deliberately conservative: it keys off an injected `QueryBuilder`
 * parameter (matched by FQCN string, so the package need not be installed),
 * not a body-inference heuristic.
 */
final readonly class QueryBuilderParamsUndeclared implements Rule, OperationRule
{
    private const string QUERY_BUILDER_CLASS = 'Spatie\\QueryBuilder\\QueryBuilder';

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

        if (!$this->injectsQueryBuilder($method)) {
            return;
        }

        $hasAttributes = $method->getAttributes(AllowedFilter::class) !== []
            || $method->getAttributes(AllowedSort::class) !== []
            || $method->getAttributes(AllowedInclude::class) !== [];

        if ($hasAttributes) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s injects a QueryBuilder but declares no #[AllowedFilter]/#[AllowedSort]/#[AllowedInclude]',
                $operation->method,
                $operation->pathUri,
            ),
            fixHint: 'Declare the accepted parameters with #[AllowedFilter], #[AllowedSort], and #[AllowedInclude].',
        );
    }

    private function injectsQueryBuilder(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === self::QUERY_BUILDER_CLASS) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function id(): string
    {
        return 'query-builder.params-undeclared';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A method injects a QueryBuilder but declares no allowed filter/sort/include attributes.';
    }
}
```

- [x] **Step 4: Run the full suite, lint, and analysis**

Run: `vendor/bin/pest tests/Unit/Plugins/QueryBuilder/Lint/QueryBuilderParamsUndeclaredTest.php && composer test && vendor/bin/pint --test && composer analyse`
Expected: the new test passes; the full suite is green; Pint clean; PHPStan clean.

- [x] **Step 5: Commit**

```bash
git add src/Plugins/QueryBuilder/Lint/Rules/QueryBuilderParamsUndeclared.php tests/Unit/Plugins/QueryBuilder/Lint/QueryBuilderParamsUndeclaredTest.php
git commit -m "feat: add query-builder.params-undeclared lint rule"
```

---

## Task 8: Documentation

**Files:**
- Modify: `CHANGELOG.md`, `docs/usage.md`, `../../internal/known-gaps.md`

- [x] **Step 1: Add a `CHANGELOG.md` entry**

Under `## [Unreleased]` → `### Added`, append:

```markdown
- `spatie/laravel-query-builder` filter/sort/include query parameters are now
  documented via the optional `QueryBuilderPlugin` (shipped disabled—uncomment
  it in `config/openapi.php` after installing the package). Endpoints declare
  parameters with `#[AllowedFilter]`, `#[AllowedSort]`, and `#[AllowedInclude]`.
  Two lint rules (`query-builder.params-undeclared`,
  `query-builder.filter-type-missing`) report incomplete declarations.
```

- [x] **Step 2: Update `docs/usage.md`**

Add a short subsection: how to enable `QueryBuilderPlugin` (uncomment in config + `composer require spatie/laravel-query-builder`), and how to declare the three attributes. Keep it to the minimal observable-behaviour description CLAUDE.md mandates.

- [x] **Step 3: Update `../../internal/known-gaps.md`**

In the OAPI-017 section, note that query-builder parameters are derived from `#[AllowedFilter]` / `#[AllowedSort]` / `#[AllowedInclude]` attributes rather than from `QueryBuilder::for(...)` calls in method bodies.

- [x] **Step 4: Run the full suite once more**

Run: `vendor/bin/pint && composer test && composer analyse`
Expected: Pint clean; suite green; PHPStan clean.

- [x] **Step 5: Commit**

```bash
git add CHANGELOG.md docs/usage.md docs/known-gaps.md
git commit -m "docs: document the QueryBuilder plugin"
```

---

## Self-Review

**Spec coverage:** `QueryBuilderPlugin` under `src/Plugins/QueryBuilder/` (Task 4); `#[AllowedFilter]` repeatable method-level → `filter[name]` (Tasks 1, 3); `#[AllowedSort]` method-level → `sort` (Tasks 2, 3); `#[AllowedInclude]` method-level → `include` (Tasks 2, 3); `QueryParameterResolver` (Task 3, the package's first); the two spec lint rules with the spec's IDs and severities (Tasks 6–7); shipped commented-out in config (Task 4); unit + feature tests (every task); `CHANGELOG.md` + `docs/usage.md` per-change updates (Task 8).

**Type consistency:** `AllowedFilter` exposes `name` + `type` + `descriptor()` (from `FieldAttribute`), used identically in Tasks 3 and 6. `AllowedSort::$fields` and `AllowedInclude::$names` are `list<string>`, consumed by `QueryBuilderParameterResolver::listParameter()` (Task 3). `QueryBuilderParameterResolver::resolveQueryParameters(): list<OA\Parameter>` matches the `QueryParameterResolver` interface.

**Design decision noted:** `query-builder.params-undeclared` keys off an injected `QueryBuilder` parameter rather than a body-inference heuristic—documented in Task 7. This is conservative (low false positives) at the cost of missing the common `QueryBuilder::for(...)`-in-body pattern; acceptable given the no-method-body-inference rule.

**Done criteria:**
- `composer test` is green with the new unit + feature tests.
- `vendor/bin/pint` reports no violations; `composer analyse` reports no errors.
- A controller method carrying the three attributes produces `filter[...]`, `sort`, and `include` query parameters once `QueryBuilderPlugin` is enabled.

## Next plans in this program

4. `FractalPlugin`—`docs/superpowers/plans/2026-05-19-fractal-plugin.md`.
5. composer.json + config-defaults wiring—`docs/superpowers/plans/2026-05-19-plugin-suite-wiring.md`.
