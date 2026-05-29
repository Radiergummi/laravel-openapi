# Error-response inference stage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move error-response inference out of `OperationBuilder` (Support) into a `Core\Stages\ErrorResponseInferenceStage` driven by a registerable contributor chain. Closes the `Support\Generator\OperationBuilder → Core\Extraction\StandardResponsesExtractor` layering violation. Zero end-user behaviour change for the bundled flavours.

**Architecture:** Build the new pieces (contributor contract, three Core contributors, the stage) alongside the existing `StandardResponsesExtractor`. At cutover (task 9), drop the dependency from `OperationBuilder`, register the stage via `Core\Registration`, and delete the old class. Snapshot tests (`ExamplesTest`, `EnvelopePresetSnapshotTest`, `PluginSuiteIntegrationTest`, `MixedExceptionsAtSameStatusTest`) are the regression safety net — they must pass before and after cutover with no fixture edits.

**Tech Stack:** PHP 8.4+, Laravel 12/13, swagger-php (`OA\*` annotations), Pest tests on Testbench, PHPStan level 8, Laravel Pint.

---

## Project orientation (read first)

You are working in **`radiergummi/laravel-openapi`** — a Laravel package that generates an OpenAPI 3.1 document from route definitions (typed request DTOs, typed return values, PHPDoc, auth middleware) and ships a documentation linter (`openapi:lint`). No hand-written YAML. Requires PHP 8.4+ and Laravel 12 or 13.

Before doing anything else:

1. **Read `CLAUDE.md`** at the repo root. It states project conventions (strict types, MIT header, `Core` vs `Support` vs `Contracts` namespace split, "no speculative DI", "no back-compat content" — pre-1.0), plus how the lint subsystem and pipeline are organised. Some rules are non-obvious and silently break PR mergeability if violated.
2. **Read the design spec** for this plan: `docs/superpowers/specs/2026-05-28-error-response-inference-design.md`. Every architectural decision is recorded there with rationale; the plan is the executable form. When the plan says "do X", the spec says *why* — consult it when a decision needs to be made that isn't spelled out in a task step.

### Top-level namespace layout (post-restructure — important)

The repo recently went through a major restructure. The layout you'll work in is:

| Namespace | Contents | Treat as |
|---|---|---|
| `Attributes\` | Authoring attributes used in user controllers (`#[Operation]`, `#[QueryParam]`, …) | Public surface |
| `Contracts\` | Interfaces with potential consumer implementations (extension points only — no DTOs here) | Public surface |
| `Enums\`, `Errors\`, `Events\`, `Generator\`, `Registry\`, `Routing\` | Public DTOs and value objects that flow through `Contracts\` interfaces | Public surface |
| `Extensions\`, `Lint\` | Top-level public APIs for extending/linting | Public surface |
| `Core\` | The bundled "Core Plugin": concrete Laravel-convention strategies (FormRequest extractor, envelope strategies, route filters, paginator handling, Faker examples). Registers itself via `Core\Registration::register()`. | Internal but illustrative |
| `Support\` | Internal infrastructure used by Core and every plugin (generator pipeline, registry impl, spec resolution, inclusion evaluator, extraction primitives, route introspection). | `@internal`; not a stable extension point |
| `Plugins\` | Bundled third-party-convention plugins (SpatieData, ApiResources, Fractal, QueryBuilder) | Public examples; each registers via the `Plugin` interface |
| `Console\`, `Http\`, `PhpStan\` | CLI commands, docs controller, PHPStan extension | Internal |

**The hard rule this plan enforces:** `Support\` must not import from `Core\`. Verified by `tests/Arch/CoreBoundaryTest.php` after task 11.

### Composer scripts cheat sheet

- `composer test` — full Pest suite via Testbench (parallel, no coverage). 1358+ tests must pass.
- `composer analyse` (alias `composer lint`) — **PHPStan level 8**, CI-blocking. ⚠️ `composer lint` is *not* Laravel Pint despite the name.
- `vendor/bin/pint --test` — Pint style check (read-only). CI-relevant.
- `vendor/bin/pint` — apply Pint fixes.
- `composer dump-autoload` — refresh autoload after file moves; the test runner caches stale paths otherwise.
- Single test: `vendor/bin/pest tests/Path/To/Test.php --no-coverage` (the `--no-coverage` flag is needed because parallel runs the cache; without it you get an Xdebug warning).
- Filter: `vendor/bin/pest --filter "substring"`.

### Pest + Testbench specifics

- Pest tests live in `tests/Unit/` and `tests/Feature/`. `tests/Pest.php` registers `Tests\TestCase` for both. No PHPUnit-style classes — write `it('does X', function (): void { … });` at the top level.
- `tests/TestCase.php` extends Testbench's `Orchestra\Testbench\TestCase` and boots the package. Services resolve via `app(Class::class)`.
- Existing test helpers worth knowing:
  - `tests/Support/ActionDescriptorFactory.php` — builds `ActionDescriptor` fixtures with a placeholder route. Use this in contributor tests instead of hand-rolling reflection setup.
  - `tests/Support/OperationNodeFactory.php` — builds `OperationNode` fixtures for lint tests.
  - `tests/Pest.php` — has `reflectFunctionParameter()`, `renderSpec()`, and other global helpers. Skim it before writing fixtures from scratch.
- Test directory structure mirrors `src/`. A test for `src/Core/ErrorContributors/ThrowsErrorContributor.php` goes at `tests/Unit/Core/Inference/ThrowsErrorContributorTest.php` (no namespace declaration in the test file — Pest collects them via `tests/Pest.php`'s `uses(TestCase::class)->in('Unit', 'Feature')`).

### Finding existing patterns

Whenever the plan says "lift X from class Y" or "mirror the existing Z pattern":

- Open the referenced file side-by-side and copy idioms exactly — formatting, attribute use, PHPDoc shape. Style consistency matters.
- For `#[Scoped]` + DI-via-constructor-attributes (`#[Config(...)]`), look at `src/Core/Extraction/PaginatorResponseResolver.php` or `src/Core/Resolvers/CoreQueryParameterResolver.php` for a working pattern.
- For `addX()` / `xs()` registry pairs, `addErrorResponseResolver()` / `errorResponseResolvers()` at `src/Registry/OpenApiRegistry.php` is the canonical template.

### Workflow conventions

- **One commit per task** (the plan's tasks each end with a `git commit` step). Don't squash.
- **Pre-1.0, no migration shims.** This codebase is unreleased. When you delete `StandardResponsesExtractor` in task 10, no deprecation layer is needed; the CHANGELOG entry is the only artefact.
- **No defensive code beyond what the plan specifies.** "No fallbacks/validation for impossible scenarios" is a CLAUDE.md rule; if a method takes a non-null parameter, don't add a null-check.
- **Comments are rare.** Only when the *why* is non-obvious. Don't narrate what the code does.
- **If something in the plan is wrong or ambiguous,** stop and surface it before working around it. The spec is the source of truth for design; the plan is the implementation translation. Mismatches between them are bugs, not opportunities for invention.

### One repo-specific gotcha

`composer test` runs Pest in parallel mode by default and caches results under `.cache/pest`. If you've just moved files (e.g. after a `git mv`), refresh the autoloader and clear the cache:

```bash
composer dump-autoload && rm -rf .cache/pest
```

Otherwise you'll see ghost `Class not found` errors from cached stale paths.

---

## Background context for the implementer

Spec: `docs/superpowers/specs/2026-05-28-error-response-inference-design.md`. Read it first.

Key existing classes you will interact with:

- `src/Core/Extraction/StandardResponsesExtractor.php` — the class being decomposed. Its `extract()` body splits across three contributors + the stage. `resolveBody()`, `buildResponse()`, and `STATUS_COMPONENT_NAMES` lift verbatim into the stage. The `throws.unmapped` finding emission lifts into `ThrowsErrorContributor`. The `DetectsAuthMiddleware` trait stays in `Support\Extraction\` (used by `MiddlewareErrorContributor`).
- `src/Support/Generator/OperationBuilder.php:36, 68, 143` — the layering violation. After this work, `use Radiergummi\OpenApi\Core\Extraction\StandardResponsesExtractor;` is gone, `private StandardResponsesExtractor $standardResponsesExtractor` is gone, and the `$standardResponses = $this->standardResponsesExtractor->extract($action);` call site is removed (along with the merge that consumed `$standardResponses`).
- `src/Generator/GenerationContext.php` — currently `final readonly class` with two fields. The class declaration drops `readonly` (fields keep it); an internal `SplObjectStorage` plus `bindAction()` / `actionFor()` are added.
- `src/Support/Generator/Stages/PathsStage.php:134-160` (`attachOperation`) — adds one line to bind each produced `OA\Operation` to its `ActionDescriptor` via the context.
- `src/Registry/OpenApiRegistry.php:64, 129, 227` — existing `addErrorResponseResolver()` / `errorResponseResolvers()` pattern is the exact template for `addErrorResponseContributor()` / `errorResponseContributors()`.
- `src/Core/Registration.php` — `register()` is where the three new contributors and the stage register.
- `src/OpenApiServiceProvider.php` — drops the `StandardResponsesExtractor` binding and its argument on the `OperationBuilder` binding; adds bindings for the three contributors and the stage.

Test conventions (Pest):

- Use `it('does X', function (): void { … })`.
- Tests resolve services via `app(...)`. Testbench bootstraps Laravel; no extra setup needed for new unit tests.
- Existing tests group as `uses()->group('openapi');` near the top of the file (where they group).
- Fixtures live under `tests/Unit/.../Fixtures/`.

Style rules (do not skip):

- Every PHP file starts with the strict-types declaration and the MIT/copyright docblock header (copy from any existing `src/Core/Extraction/*.php` file).
- `final readonly class …` for value-style classes; `#[Scoped]` attribute for container-scoped services.
- Section folding regions use `// region <title>` / `// endregion`.
- PHPStan level 8 must pass. `composer analyse` is CI-blocking.
- Laravel Pint must report no violations. `vendor/bin/pint --test` to check, `vendor/bin/pint` to fix.

Snapshot regression safety net (run after task 9 and again at the end):

```bash
vendor/bin/pest tests/Feature/Errors tests/Feature/Examples tests/Feature/Plugins/PluginSuiteIntegrationTest.php
```

These compare generated YAML against checked-in fixtures. Zero fixture edits permitted by this work.

---

## File structure

**Create:**

- `src/Contracts/Registry/ErrorResponseContributor.php` — single-method interface.
- `src/Core/ErrorContributors/ThrowsErrorContributor.php` — lifts `@throws` walking + `throws.unmapped` emission from `StandardResponsesExtractor`.
- `src/Core/ErrorContributors/MiddlewareErrorContributor.php` — lifts middleware walking (uses `DetectsAuthMiddleware` trait).
- `src/Core/ErrorContributors/ValidationErrorContributor.php` — NEW logic: detect FormRequest subclass in action params.
- `src/Core/Stages/ErrorResponseInferenceStage.php` — orchestrates contributor chain + envelope resolution + dedupe.
- `tests/Unit/Core/Inference/ThrowsErrorContributorTest.php`
- `tests/Unit/Core/Inference/MiddlewareErrorContributorTest.php`
- `tests/Unit/Core/Inference/ValidationErrorContributorTest.php`
- `tests/Unit/Core/Stages/ErrorResponseInferenceStageTest.php`

**Modify:**

- `src/Generator/GenerationContext.php` — drop `readonly` on class, add `SplObjectStorage` + `bindAction()` / `actionFor()`.
- `src/Support/Generator/Stages/PathsStage.php` — call `$context->bindAction($operationSchema, $action)` after operation construction.
- `src/Support/Generator/OperationBuilder.php` — drop `StandardResponsesExtractor` dependency + merge.
- `src/Registry/OpenApiRegistry.php` — add `addErrorResponseContributor()` / `errorResponseContributors()`.
- `src/Core/Registration.php` — register three contributors + stage.
- `src/OpenApiServiceProvider.php` — bind new classes; drop `StandardResponsesExtractor` binding + arg on `OperationBuilder` binding.
- `tests/Arch/CoreBoundaryTest.php` — assert `Support\` has no `Core\` imports.
- `CHANGELOG.md` — `[Unreleased]` entry.
- `docs/plugin-authoring.md` — document `addErrorResponseContributor()` next to other registry methods.

**Delete (at cutover, task 9):**

- `src/Core/Extraction/StandardResponsesExtractor.php`
- `tests/Unit/Core/Extractors/StandardResponsesExtractorRobustnessTest.php` (cases redistributed across the new contributor tests during task 4–6).

---

## Task 1: Contributor contract + registry surface

**Files:**
- Create: `src/Contracts/Registry/ErrorResponseContributor.php`
- Modify: `src/Registry/OpenApiRegistry.php`

- [ ] **Step 1: Create the contract**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * Inspects an action and declares any error responses implied by it.
 *
 * Contributors return {@see ErrorDescriptor}s, not full `OA\Response`s — body resolution
 * via the {@see \Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver} chain and
 * `OA\Response` construction stay in the stage that drives the chain.
 *
 * Registered via {@see \Radiergummi\OpenApi\Registry\OpenApiRegistry::addErrorResponseContributor()}.
 */
interface ErrorResponseContributor
{
    /**
     * @return list<ErrorDescriptor>
     */
    public function contribute(ActionDescriptor $descriptor): array;
}
```

- [ ] **Step 2: Add registry surface**

Mirror the existing `addErrorResponseResolver()` / `errorResponseResolvers()` pair exactly. Add to `src/Registry/OpenApiRegistry.php`:

- A private `array $errorResponseContributors = [];` field (next to `$errorResponseResolvers`).
- `public function addErrorResponseContributor(string $class): void` — same body shape as `addErrorResponseResolver()`.
- `public function errorResponseContributors(): array` — same body shape as `errorResponseResolvers()`.

PHPDoc the class-string with `class-string<ErrorResponseContributor>`. Add the matching `use` line.

- [ ] **Step 3: Registry test**

Path: `tests/Unit/Registry/OpenApiRegistryTest.php` (exists). Add:

```php
final class FakeContributorA implements \Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor
{
    public function contribute(\Radiergummi\OpenApi\Routing\ActionDescriptor $descriptor): array
    {
        return [];
    }
}

final class FakeContributorB implements \Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor
{
    public function contribute(\Radiergummi\OpenApi\Routing\ActionDescriptor $descriptor): array
    {
        return [];
    }
}

it('registers and returns error response contributors', function (): void {
    $registry = new OpenApiRegistry();

    $registry->addErrorResponseContributor(FakeContributorA::class);
    $registry->addErrorResponseContributor(FakeContributorB::class);
    // Duplicate is ignored, matching the resolver behaviour:
    $registry->addErrorResponseContributor(FakeContributorA::class);

    expect($registry->errorResponseContributors())
        ->toBe([FakeContributorA::class, FakeContributorB::class]);
});
```

The fakes **must be declared as real, named classes** (not anonymous) — the registry's `class-string<ErrorResponseContributor>` typing requires resolvable class-strings. Pest test files allow top-level class declarations, so put them at the top of the test file alongside any existing fakes. Use FQNs in `implements`/`use` to avoid colliding with other fakes in the file.

- [ ] **Step 4: Verify**

```bash
vendor/bin/pint --test
composer analyse
vendor/bin/pest tests/Unit/Registry/OpenApiRegistryTest.php
```

Expected: pint clean, phpstan clean, registry test passes.

- [ ] **Step 5: Commit**

```bash
git add src/Contracts/Registry/ErrorResponseContributor.php src/Registry/OpenApiRegistry.php tests/Unit/Registry/OpenApiRegistryTest.php
git commit -m "feat(registry): add ErrorResponseContributor contract + registry surface"
```

---

## Task 2: Per-operation action lookup on `GenerationContext`

**Files:**
- Modify: `src/Generator/GenerationContext.php`

- [ ] **Step 1: Drop `readonly` on the class declaration, add the lookup**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Generator;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use SplObjectStorage;

/**
 * Per-run inputs shared across every stage in a single SpecPipeline::run() invocation.
 *
 * The `$spec` and `$environment` fields are immutable per run. The per-operation
 * action lookup populated by PathsStage and read by later stages is the only mutable
 * piece of state on the context.
 */
final class GenerationContext
{
    /** @var SplObjectStorage<OA\Operation, ActionDescriptor> */
    private SplObjectStorage $actions;

    public function __construct(
        public readonly SpecDefinition $spec,
        public readonly string $environment,
    ) {
        $this->actions = new SplObjectStorage();
    }

    public function bindAction(OA\Operation $operation, ActionDescriptor $descriptor): void
    {
        $this->actions[$operation] = $descriptor;
    }

    public function actionFor(OA\Operation $operation): ?ActionDescriptor
    {
        /** @var ActionDescriptor|null */
        return $this->actions[$operation] ?? null;
    }
}
```

- [ ] **Step 2: Test the new methods in isolation**

Path: `tests/Unit/Generator/GenerationContextTest.php` (new file).

For the `ActionDescriptor` instance, use `Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory` (already exists, builds a descriptor against a placeholder `GET /x` route). For `SpecDefinition`, instantiate inline — there's no shared builder for it, and other tests do the same (see `tests/Unit/Support/Spec/SpecDefinitionTest.php` for a working call). Its constructor takes `(string $name, OA\Info $info, array $servers, array $tags, array $match, string $outputPath, ?string $routeUri, ?string $playgroundUri)`.

```php
<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

function spec(): SpecDefinition
{
    return new SpecDefinition(
        name: 'default',
        info: new OA\Info(['title' => 'Test', 'version' => '0.0.0']),
        servers: [],
        tags: [],
        match: [],
        outputPath: 'openapi.yaml',
        routeUri: null,
        playgroundUri: null,
    );
}

it('returns null when no action is bound for an operation', function (): void {
    $ctx = new GenerationContext(spec(), 'testing');

    expect($ctx->actionFor(new OA\Operation([])))->toBeNull();
});

it('looks up the bound action descriptor by operation identity', function (): void {
    $ctx = new GenerationContext(spec(), 'testing');
    $op = new OA\Operation([]);
    $action = ActionDescriptorFactory::make();

    $ctx->bindAction($op, $action);

    expect($ctx->actionFor($op))->toBe($action);
});

it('distinguishes operations by object identity, not by data', function (): void {
    $ctx = new GenerationContext(spec(), 'testing');
    $opA = new OA\Operation(['operationId' => 'shared']);
    $opB = new OA\Operation(['operationId' => 'shared']);
    $action = ActionDescriptorFactory::make();

    $ctx->bindAction($opA, $action);

    expect($ctx->actionFor($opA))->toBe($action);
    expect($ctx->actionFor($opB))->toBeNull();
});
```

If `ActionDescriptorFactory::make()` doesn't exist or has a different signature, open `tests/Support/ActionDescriptorFactory.php` and use whatever public static builder it does expose — don't introduce a new builder.

- [ ] **Step 3: Verify**

```bash
vendor/bin/pint --test
composer analyse
vendor/bin/pest tests/Unit/Generator/GenerationContextTest.php
```

- [ ] **Step 4: Commit**

```bash
git add src/Generator/GenerationContext.php tests/Unit/Generator/GenerationContextTest.php
git commit -m "feat(generator): add per-operation action lookup to GenerationContext"
```

---

## Task 3: `PathsStage` binds operations to action descriptors

**Files:**
- Modify: `src/Support/Generator/Stages/PathsStage.php`

This task populates the lookup but doesn't *read* it yet — the stage that reads it lands in task 7. Pure additive change.

- [ ] **Step 1: Modify `attachOperation()`**

In `attachOperation()`, after `$operationSchema = $resolved->toOpenApi($method);` and the `null` check, but before the `OpenApiExtensions::applyOperationTransformers(...)` call, add:

```php
$context->bindAction($operationSchema, $action);
```

`attachOperation()` doesn't currently receive `$context`. Thread it through from the call site:

- `PathsStage::apply(OA\OpenApi $doc, GenerationContext $context)` already has `$context` in scope. Pass it down to `attachOperation()`:
  - Change the method signature to `private function attachOperation(OA\PathItem $pathItem, ActionDescriptor $action, GenerationContext $context): void`.
  - Update the call site inside `apply()` to pass `$context`.

- [ ] **Step 2: Verify binding happens in an integration test**

Add a focused test under `tests/Unit/Support/Generator/Pipeline/PathsStageBindingTest.php` (new file). Build a minimal pipeline run; assert that `$ctx->actionFor($operation)` returns the expected descriptor for at least one route. The existing end-to-end tests give regression cover; this is the targeted assertion that binding occurs.

- [ ] **Step 3: Verify**

```bash
vendor/bin/pint --test
composer analyse
vendor/bin/pest
```

The full test run must stay green (no behaviour change yet).

- [ ] **Step 4: Commit**

```bash
git add src/Support/Generator/Stages/PathsStage.php tests/Unit/Support/Generator/Pipeline/PathsStageBindingTest.php
git commit -m "feat(generator): PathsStage binds OA\Operation -> ActionDescriptor in context"
```

---

## Task 4: `ThrowsErrorContributor`

**Files:**
- Create: `src/Core/ErrorContributors/ThrowsErrorContributor.php`
- Create: `tests/Unit/Core/Inference/ThrowsErrorContributorTest.php`

**Why a new `Core\Inference\` directory rather than putting these in `Core\Extraction\` alongside `FormRequestRequestSchemaResolver` etc.:** the existing `Core\Extraction\` classes *resolve known shapes* (FormRequest → schema, paginator → response). Contributors *infer implied responses* from indirect signals (`@throws` annotations, middleware presence, payload type). Different semantic — own namespace.

Lift the `@throws` walking logic from `StandardResponsesExtractor::extract()` lines that iterate `$descriptor->throws`, including the `resolveFromAttribute()` + `matchException()` helpers and the `throws.unmapped` finding emission.

- [ ] **Step 1: Write the contributor**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Inference;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Attributes\ExceptionResponse;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionAttribute;
use ReflectionClass;
use Throwable;

#[Scoped]
final readonly class ThrowsErrorContributor implements ErrorResponseContributor
{
    /**
     * @param array<string, array{status: int, description: string}> $exceptionMap
     */
    public function __construct(
        private FindingsCollector $findings,
        #[Config('openapi.exception_responses', default: [])]
        private array $exceptionMap = [],
    ) {}

    /**
     * @return list<ErrorDescriptor>
     */
    #[Override]
    public function contribute(ActionDescriptor $descriptor): array
    {
        // region @throws walk
        $descriptors = [];

        foreach ($descriptor->throws as $throw) {
            $entry = $this->resolveFromAttribute($throw)
                ?? $this->matchException($throw, $this->exceptionMap);

            if ($entry === null) {
                $this->emitUnmapped($descriptor, $throw);

                continue;
            }

            $exceptionClass = (class_exists($throw) || interface_exists($throw))
                && is_a($throw, Throwable::class, true)
                    ? $throw
                    : null;

            $descriptors[] = new ErrorDescriptor(
                status: (int) $entry['status'],
                exceptionClass: $exceptionClass,
                description: (string) $entry['description'],
            );
        }

        return $descriptors;
        // endregion
    }

    // resolveFromAttribute(), matchException(), emitUnmapped(), buildThrowsUnmappedHint()
    // are private helpers lifted verbatim from StandardResponsesExtractor.
}
```

The private helpers (`resolveFromAttribute`, `matchException`, `buildThrowsUnmappedHint`) lift unchanged from `StandardResponsesExtractor`. Pull the file open side-by-side and copy them across; don't rewrite. Adjust visibility to private.

`emitUnmapped()` wraps the `$this->findings->emit(new Finding(...))` block from the original `extract()`.

- [ ] **Step 2: Write the contributor test**

Path: `tests/Unit/Core/Inference/ThrowsErrorContributorTest.php`. Cover:

1. Empty `throws` list returns `[]`, no findings.
2. `@throws X` where `X` is in the config map → returns one `ErrorDescriptor` with the configured status + description.
3. `@throws X` where `X` has a `#[ExceptionResponse]` attribute → attribute wins over config.
4. `@throws X` where `X` is unmapped → returns `[]` AND emits a `throws.unmapped` finding via the collector.
5. `@throws X` where `X` is a `Throwable` subclass → returned `ErrorDescriptor->exceptionClass` is populated.
6. `@throws X` where `X` is not loadable (no such class) → `exceptionClass` is null but description still resolved if mapped.

For (4), inject a real `ArrayFindingsCollector` (already exists in `src/Lint/`) and assert on its collected findings.

The cases existing in `tests/Unit/Core/Extractors/StandardResponsesExtractorRobustnessTest.php` that exercise these scenarios should be ported (not duplicated) — read that file alongside while writing.

- [ ] **Step 3: Verify**

```bash
composer dump-autoload   # first time creating src/Core/Inference/ — composer must learn it
vendor/bin/pint --test
composer analyse
vendor/bin/pest tests/Unit/Core/Inference/ThrowsErrorContributorTest.php
```

If you see `Class "Radiergummi\OpenApi\Core\Inference\ThrowsErrorContributor" not found` from Pest, rerun `composer dump-autoload` and clear the Pest cache: `rm -rf .cache/pest`. PSR-4 picks up new files automatically once the autoloader has indexed the directory, but a stale `.cache/pest` will hide that. The same caveat applies to Tasks 5–7 when adding new files into new directories.

- [ ] **Step 4: Commit**

```bash
git add src/Core/Inference/ThrowsErrorContributor.php tests/Unit/Core/Inference/ThrowsErrorContributorTest.php
git commit -m "feat(core): add ThrowsErrorContributor"
```

---

## Task 5: `MiddlewareErrorContributor`

**Files:**
- Create: `src/Core/ErrorContributors/MiddlewareErrorContributor.php`
- Create: `tests/Unit/Core/Inference/MiddlewareErrorContributorTest.php`

Lift the middleware walking logic from `StandardResponsesExtractor::extract()` — the block that reads `$descriptor->route->gatherMiddleware()` and checks `auth` / `scope` / `throttle`.

- [ ] **Step 1: Write the contributor**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Inference;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\DetectsAuthMiddleware;
use Throwable;

#[Scoped]
final readonly class MiddlewareErrorContributor implements ErrorResponseContributor
{
    use DetectsAuthMiddleware;

    /**
     * @param array<string, array{status: int, description: string, exception?: class-string<Throwable>}> $middlewareMap
     */
    public function __construct(
        #[Config('openapi.middleware_responses', default: [])]
        private array $middlewareMap = [],
    ) {}

    /**
     * @return list<ErrorDescriptor>
     */
    #[Override]
    public function contribute(ActionDescriptor $descriptor): array
    {
        $descriptors = [];
        $middleware = array_values($descriptor->route->gatherMiddleware());

        foreach (['auth', 'scope', 'throttle'] as $kind) {
            if (!isset($this->middlewareMap[$kind])) {
                continue;
            }

            $detected = match ($kind) {
                'auth' => $this->hasAuthMiddleware($middleware),
                'scope' => $this->hasScopeMiddleware($middleware),
                'throttle' => $this->hasThrottleMiddleware($middleware),
            };

            if (!$detected) {
                continue;
            }

            $entry = $this->middlewareMap[$kind];
            $descriptors[] = new ErrorDescriptor(
                status: (int) $entry['status'],
                exceptionClass: $entry['exception'] ?? null,
                description: (string) $entry['description'],
            );
        }

        return $descriptors;
    }
}
```

- [ ] **Step 2: Write the contributor test**

Path: `tests/Unit/Core/Inference/MiddlewareErrorContributorTest.php`. Cover:

1. Empty middleware list → `[]`.
2. Route with `auth` middleware + `auth` configured → returns one `ErrorDescriptor` with 401.
3. Route with `scope:read` middleware + `scope` configured → returns 403.
4. Route with `throttle:60,1` + `throttle` configured → returns 429.
5. All three middleware present + all configured → returns three descriptors (auth, scope, throttle), order from the iteration above.
6. Middleware present but kind not in config → no descriptor for that kind.

Stub the route via `\Illuminate\Routing\Route` with `gatherMiddleware()` returning the desired list (Testbench's container gives you the dispatcher to build routes from).

Port the corresponding cases from `StandardResponsesExtractorRobustnessTest`.

- [ ] **Step 3: Verify**

```bash
vendor/bin/pint --test
composer analyse
vendor/bin/pest tests/Unit/Core/Inference/MiddlewareErrorContributorTest.php
```

- [ ] **Step 4: Commit**

```bash
git add src/Core/Inference/MiddlewareErrorContributor.php tests/Unit/Core/Inference/MiddlewareErrorContributorTest.php
git commit -m "feat(core): add MiddlewareErrorContributor"
```

---

## Task 6: `ValidationErrorContributor`

**Files:**
- Create: `src/Core/ErrorContributors/ValidationErrorContributor.php`
- Create: `tests/Unit/Core/Inference/ValidationErrorContributorTest.php`

NEW logic. Walk the action's method parameters; if any is a subclass of `Illuminate\Foundation\Http\FormRequest`, emit a 422 descriptor from `config('openapi.exception_responses')[ValidationException::class]`.

- [ ] **Step 1: Write the contributor**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Inference;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionNamedType;

#[Scoped]
final readonly class ValidationErrorContributor implements ErrorResponseContributor
{
    /**
     * @param array<string, array{status: int, description: string}> $exceptionMap
     */
    public function __construct(
        #[Config('openapi.exception_responses', default: [])]
        private array $exceptionMap = [],
    ) {}

    /**
     * @return list<ErrorDescriptor>
     */
    #[Override]
    public function contribute(ActionDescriptor $descriptor): array
    {
        if (!$this->hasFormRequestParameter($descriptor)) {
            return [];
        }

        $entry = $this->exceptionMap[ValidationException::class] ?? null;

        if ($entry === null) {
            return [];
        }

        return [
            new ErrorDescriptor(
                status: (int) $entry['status'],
                exceptionClass: ValidationException::class,
                description: (string) $entry['description'],
            ),
        ];
    }

    private function hasFormRequestParameter(ActionDescriptor $descriptor): bool
    {
        if ($descriptor->method === null) {
            return false;
        }

        foreach ($descriptor->method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (class_exists($className) && is_subclass_of($className, FormRequest::class)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 2: Write the contributor test**

Path: `tests/Unit/Core/Inference/ValidationErrorContributorTest.php`. Cover:

1. Action with no FormRequest parameter → `[]`.
2. Action with one FormRequest parameter (use an existing fixture like `tests/Fixtures/SimpleFormRequest.php`) → returns one 422 descriptor.
3. Action with multiple parameters one of which is a FormRequest subclass → returns one descriptor (not multiple).
4. Action with a FormRequest *interface* type-hint (not subclass) → `[]` (we check subclass, not instanceof for the class-string).
5. `ValidationException::class` not in config → `[]` (early bail).
6. Action descriptor with `method === null` (closure route) → `[]`.

- [ ] **Step 3: Verify**

```bash
vendor/bin/pint --test
composer analyse
vendor/bin/pest tests/Unit/Core/Inference/ValidationErrorContributorTest.php
```

- [ ] **Step 4: Commit**

```bash
git add src/Core/Inference/ValidationErrorContributor.php tests/Unit/Core/Inference/ValidationErrorContributorTest.php
git commit -m "feat(core): add ValidationErrorContributor for FormRequest 422 inference"
```

---

## Task 7: `ErrorResponseInferenceStage`

**Files:**
- Create: `src/Core/Stages/ErrorResponseInferenceStage.php`
- Create: `tests/Unit/Core/Stages/ErrorResponseInferenceStageTest.php`

Lifts `resolveBody()`, `buildResponse()`, and `STATUS_COMPONENT_NAMES` from `StandardResponsesExtractor` into the stage. Adds the contributor-loop and the two precedence rules from the spec.

- [ ] **Step 1: Write the stage**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Enums\ComponentType;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

#[Scoped]
final readonly class ErrorResponseInferenceStage implements SpecStage
{
    private const array STATUS_COMPONENT_NAMES = [
        400 => 'BadRequest',
        401 => 'Unauthorized',
        402 => 'PaymentRequired',
        403 => 'Forbidden',
        404 => 'NotFound',
        405 => 'MethodNotAllowed',
        409 => 'Conflict',
        422 => 'ValidationFailed',
        429 => 'TooManyRequests',
        500 => 'InternalServerError',
    ];

    private const array HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'trace'];

    /**
     * The `$errorResponseResolvers` field name matches the original `StandardResponsesExtractor`
     * field — the lifted `resolveBody()` references it by that name, so don't rename.
     *
     * @param list<ErrorResponseContributor> $contributors
     * @param list<ErrorResponseResolver>    $errorResponseResolvers
     */
    public function __construct(
        private array $contributors,
        private array $errorResponseResolvers,
        private ComponentSchemaRegistry $registry,
        private FindingsCollector $findings,
    ) {}

    #[Override]
    public function apply(OA\OpenApi $doc, GenerationContext $context): void
    {
        if (!is_array($doc->paths)) {
            return;
        }

        foreach ($doc->paths as $pathItem) {
            foreach (self::HTTP_METHODS as $verb) {
                $operation = $pathItem->{$verb} ?? Generator::UNDEFINED;

                if ($operation === Generator::UNDEFINED || !$operation instanceof OA\Operation) {
                    continue;
                }

                $this->decorate($operation, $context);
            }
        }
    }

    private function decorate(OA\Operation $operation, GenerationContext $context): void
    {
        $action = $context->actionFor($operation);
        if ($action === null) {
            return;
        }

        // 1. Collect descriptors from every contributor (first wins per status; see spec
        //    section "Precedence", rule 2).
        $byStatus = [];
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->contribute($action) as $descriptor) {
                $byStatus[$descriptor->status] ??= $descriptor;
            }
        }

        // 2. Drop statuses already declared explicitly on the operation (see spec section
        //    "Precedence", rule 1).
        $existing = is_array($operation->responses) ? $operation->responses : [];
        foreach ($existing as $resp) {
            if (is_object($resp) && property_exists($resp, 'response')) {
                unset($byStatus[(int) $resp->response]);
            }
        }

        if ($byStatus === []) {
            return;
        }

        ksort($byStatus);

        // 3. Resolve body via envelope chain and append.
        $additions = [];
        foreach ($byStatus as $descriptor) {
            $body = $this->resolveBody($descriptor);
            $componentName = self::STATUS_COMPONENT_NAMES[$descriptor->status] ?? null;
            $additions[] = $this->buildResponse($descriptor, $body, $componentName);
        }
        $operation->responses = [...$existing, ...$additions];
    }

    // resolveBody() and buildResponse() lift verbatim from StandardResponsesExtractor —
    // they reference $this->errorResponseResolvers, $this->registry, $this->findings, and
    // self::STATUS_COMPONENT_NAMES; all four are present here under those exact names so
    // the lift requires no renaming. ComponentType (used inside buildResponse at the
    // `qualifyKey()` call) must be imported alongside.
}
```

`resolveBody()` and `buildResponse()` move across unchanged. The `try`/`catch` around `ErrorResponseResolver::resolveErrorResponse()` that emits the `errors.resolver-failed` finding stays inside `resolveBody()`.

- [ ] **Step 2: Write the stage test (full precedence coverage)**

Path: `tests/Unit/Core/Stages/ErrorResponseInferenceStageTest.php`. Cover:

1. **Bind-action absent** — operation with no bound `ActionDescriptor`: stage skips it untouched.
2. **Single contributor, single status** — fake contributor returning one descriptor: operation ends up with the matching `OA\Response`.
3. **Dedupe across contributors (first wins, by description)** — two fake contributors both return 422 with **different** descriptions; assert the first contributor's description survives. ← *Precedence rule 2.*
4. **Explicit `#[Response]` wins over inferred** — pre-seed `$operation->responses` with a 422; contributor also returns 422; assert the operation's responses contain exactly the pre-seeded 422, untouched. ← *Precedence rule 1.*
5. **Envelope chain invoked** — fake `ErrorResponseResolver` returns a body; assert the produced `OA\Response` content matches.
6. **Multiple statuses sorted ascending** — contributor returns [500, 422, 401]; assert appended responses are in [401, 422, 500] order.

Use plain anonymous-class fakes for `ErrorResponseContributor` and `ErrorResponseResolver`. Real `ComponentSchemaRegistry` (cheap to construct). Real `ArrayFindingsCollector`.

- [ ] **Step 3: Verify**

```bash
vendor/bin/pint --test
composer analyse
vendor/bin/pest tests/Unit/Core/Stages/ErrorResponseInferenceStageTest.php
```

- [ ] **Step 4: Commit**

```bash
git add src/Core/Stages/ErrorResponseInferenceStage.php tests/Unit/Core/Stages/ErrorResponseInferenceStageTest.php
git commit -m "feat(core): add ErrorResponseInferenceStage with contributor chain"
```

---

## Task 8: Service-provider bindings

**Files:**
- Modify: `src/OpenApiServiceProvider.php`

The new classes are bound but **not yet wired into the pipeline**. `OperationBuilder` still imports and calls `StandardResponsesExtractor` at this point. Behaviour is unchanged.

- [ ] **Step 1: Add bindings**

In `OpenApiServiceProvider::register()`:

```php
$this->app->scoped(
    ErrorResponseInferenceStage::class,
    static function (Container $app): ErrorResponseInferenceStage {
        $registry = $app->make(OpenApiRegistry::class);

        return new ErrorResponseInferenceStage(
            contributors: array_map(
                static fn (string $class) => $app->make($class),
                $registry->errorResponseContributors(),
            ),
            errorResponseResolvers: array_map(
                static fn (string $class) => $app->make($class),
                $registry->errorResponseResolvers(),
            ),
            registry: $app->make(ComponentSchemaRegistry::class),
            findings: $app->make(FindingsCollector::class),
        );
    },
);
```

The three contributors carry `#[Scoped]` so they auto-bind; no explicit bindings needed for them.

- [ ] **Step 2: Verify**

```bash
vendor/bin/pint --test
composer analyse
vendor/bin/pest
```

Expected: full suite green, no behaviour change.

- [ ] **Step 3: Commit**

```bash
git add src/OpenApiServiceProvider.php
git commit -m "feat(provider): bind ErrorResponseInferenceStage"
```

---

## Task 9: Cutover — register stage, drop OperationBuilder dependency

**Files:**
- Modify: `src/Core/Registration.php`
- Modify: `src/Support/Generator/OperationBuilder.php`
- Modify: `src/OpenApiServiceProvider.php`

This task is the **single atomic switch**. All previous tasks built new code without changing behaviour; this task swaps the implementation.

> ⚠️ **Do not commit or run the test suite between Steps 1 and 3.** Between Step 1 (stage registered, runs alongside the old extractor) and Step 3 (old extractor removed from `OperationBuilder`), both mechanisms emit error responses and the operation receives duplicates. The intermediate working tree is intentionally broken. Run the regression suite only at Step 4, after all three edits are made. All edits land in **one** commit (Step 5).

- [ ] **Step 1: Register contributors + stage in `Core\Registration::register()`**

Add (in this exact order — registration order is load-bearing per spec section **"Precedence"**, rule 2; rationale spelled out there):

```php
$registry->addErrorResponseContributor(ThrowsErrorContributor::class);
$registry->addErrorResponseContributor(MiddlewareErrorContributor::class);
$registry->addErrorResponseContributor(ValidationErrorContributor::class);
$registry->addStage(ErrorResponseInferenceStage::class);
```

Add the matching `use` lines.

- [ ] **Step 2: Drop the dependency from `OperationBuilder`**

In `src/Support/Generator/OperationBuilder.php`:

- Remove `use Radiergummi\OpenApi\Core\Extraction\StandardResponsesExtractor;`
- Remove `private StandardResponsesExtractor $standardResponsesExtractor,` from the constructor.
- Remove the `$standardResponses = $this->standardResponsesExtractor->extract($action);` line and the merge of `$standardResponses` into the responses array. Track every variable touching it; remove dead bits.

- [ ] **Step 3: Update the `OperationBuilder` binding**

In `src/OpenApiServiceProvider.php`, find the `OperationBuilder` `scoped()` binding and remove the `standardResponsesExtractor:` argument. Remove the `StandardResponsesExtractor` binding itself (the standalone `scoped()` entry).

- [ ] **Step 4: Run the regression safety net**

```bash
vendor/bin/pest tests/Feature/Errors tests/Feature/Examples tests/Feature/Plugins/PluginSuiteIntegrationTest.php
```

**Expected: all snapshot tests pass with zero fixture edits.** If any snapshot diffs, stop — there's a precedence or ordering bug. Common causes:

- Contributors registered in wrong order in `Core\Registration` (must be Throws → Middleware → Validation).
- Stage iterating operations in a different order than the old extractor; this is fine for swagger-php output (which serialises by `path → http verb → response keys`) but check that the per-operation response list is correctly sorted by status (`ksort($byStatus)` in the stage).
- Existing `$operation->responses` check in the stage missing — explicit `#[Response]`s being clobbered.

Full suite:

```bash
composer test
```

- [ ] **Step 5: Commit**

```bash
git add src/Core/Registration.php src/Support/Generator/OperationBuilder.php src/OpenApiServiceProvider.php
git commit -m "refactor(core): replace StandardResponsesExtractor with inference stage

OperationBuilder no longer imports Core. Error responses are now inferred by
Core\Stages\ErrorResponseInferenceStage running after PathsStage, driven by
the ErrorResponseContributor chain registered in Core\Registration."
```

---

## Task 10: Delete `StandardResponsesExtractor`, redistribute tests

**Files:**
- Delete: `src/Core/Extraction/StandardResponsesExtractor.php`
- Delete: `tests/Unit/Core/Extractors/StandardResponsesExtractorRobustnessTest.php`

- [ ] **Step 1: Verify the class has no remaining code references**

```bash
grep -rn "StandardResponsesExtractor" src/ tests/
```

Expected: no hits in `src/` or `tests/` (the file itself excluded — it's about to be deleted). Hits in `docs/superpowers/plans/*` and `docs/superpowers/specs/*` (historical plans/specs) are allowed and should be left alone.

- [ ] **Step 2: Update stale docblock references**

The Core lint-rule registration stubs reference `StandardResponsesExtractor` in their `{@see …}` tags — these go dead when the file is deleted. Find them:

```bash
grep -rn "StandardResponsesExtractor" src/Core/Lint/
```

Known hits to update:

- `src/Core/Lint/ThrowsUnmapped.php` — two `{@see StandardResponsesExtractor}` references. Replace with `{@see \Radiergummi\OpenApi\Core\Inference\ThrowsErrorContributor}` (the new emitter for `throws.unmapped`).

If the grep surfaces other rule files referencing the old class, update each to point at whichever new contributor or stage now emits its finding. Pattern by finding ID:

- `throws.unmapped` → `ThrowsErrorContributor`
- `errors.resolver-failed` → `ErrorResponseInferenceStage` (the `try`/`catch` around the resolver chain moved into the stage's `resolveBody()`)

- [ ] **Step 3: Delete the class and its dedicated robustness test**

```bash
git rm src/Core/Extraction/StandardResponsesExtractor.php
git rm tests/Unit/Core/Extractors/StandardResponsesExtractorRobustnessTest.php
```

The robustness-test cases were ported to the new contributor tests during tasks 4–5. If any case wasn't ported (the redistribution sometimes misses an edge case), port it now into the matching contributor test before deleting the file.

- [ ] **Step 4: Verify**

```bash
vendor/bin/pint --test
composer analyse
composer test
```

- [ ] **Step 5: Commit**

```bash
git add -A   # picks up docblock edits from Step 2 in addition to the deletions
git commit -m "refactor(core): remove StandardResponsesExtractor

Logic split across ThrowsErrorContributor, MiddlewareErrorContributor,
ValidationErrorContributor, and ErrorResponseInferenceStage. Stub-rule
docblocks point at the new emitters."
```

---

## Task 11: Architectural guard

**Files:**
- Modify: `tests/Arch/CoreBoundaryTest.php`

- [ ] **Step 1: Add the Support → Core assertion**

In `tests/Arch/CoreBoundaryTest.php`, after the existing `Core must not depend on any plugin` assertion, add:

```php
arch('Support must not depend on Core')
    ->expect('Radiergummi\OpenApi\Support')
    ->not->toUse('Radiergummi\OpenApi\Core');
```

- [ ] **Step 2: Verify**

```bash
vendor/bin/pest tests/Arch/CoreBoundaryTest.php
```

Expected: passes (the cutover in task 9 already eliminated the only known violation).

If it fails, grep for the remaining import:

```bash
grep -rn "use Radiergummi\\\\OpenApi\\\\Core\\\\" src/Support/
```

- [ ] **Step 3: Commit**

```bash
git add tests/Arch/CoreBoundaryTest.php
git commit -m "test(arch): assert Support has no Core imports"
```

---

## Task 12: Docs + CHANGELOG

**Files:**
- Modify: `docs/plugin-authoring.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Document `addErrorResponseContributor()` in `docs/plugin-authoring.md`**

In the registry table (around the `addErrorResponseResolver` row), add:

| `addErrorResponseContributor(string $class)` | An inference source for error responses; called per operation, returns `ErrorDescriptor`s | `ErrorResponseContributor` |

Below the table, add a short prose paragraph next to the existing resolver-interfaces section:

> **`ErrorResponseContributor`**: given an `ActionDescriptor`, return any
> error responses implied by it (`@throws` annotations, middleware, payload
> type, etc.). Bundled by Core: `ThrowsErrorContributor`,
> `MiddlewareErrorContributor`, `ValidationErrorContributor`. The
> `ErrorResponseInferenceStage` runs the chain in registration order and
> dedupes by status (first wins). Explicit `#[Response(status: X)]`
> attributes on the action always override inferred responses for that
> status.

Update the getters list to include `errorResponseContributors()`.

- [ ] **Step 2: Add the `[Unreleased]` changelog entry**

Under `## [Unreleased]` → `### Added`:

```markdown
- New plugin extension point: `ErrorResponseContributor`. Plugins can now
  contribute inferred error responses (e.g. a validation-driven 422 from
  their payload type) via `OpenApiRegistry::addErrorResponseContributor()`.
  Core ships three contributors covering `@throws` annotations, route
  middleware, and FormRequest validation; the
  `Core\Stages\ErrorResponseInferenceStage` runs the chain after
  `PathsStage` and dedupes by status (first contributor wins; explicit
  `#[Response]` attributes always override inferred responses).
- Per-operation `ActionDescriptor` lookup on `GenerationContext`
  (`bindAction()` / `actionFor()`) so stages can find the action that
  produced an `OA\Operation`. Populated by `PathsStage`.
```

Under `### Changed`:

```markdown
- Error-response inference moved out of `Support\Generator\OperationBuilder`
  into `Core\Stages\ErrorResponseInferenceStage`. Closes the
  `Support → Core` layering violation; `OperationBuilder` no longer imports
  from `Core\`. Bundled-flavour spec output is unchanged (snapshot tests
  pass without fixture edits).
- `Core\Extraction\StandardResponsesExtractor` removed. Its logic is split
  across `Core\Inference\ThrowsErrorContributor`,
  `Core\Inference\MiddlewareErrorContributor`, and the new
  `ErrorResponseInferenceStage`. Pre-1.0; no migration shim. Third-party
  code depending directly on the class must migrate to the contributor
  chain or to subclassing the stage.
```

- [ ] **Step 3: Verify**

```bash
vendor/bin/pint --test
composer analyse
composer test
```

Final regression pass; everything must be green.

- [ ] **Step 4: Commit**

```bash
git add docs/plugin-authoring.md CHANGELOG.md
git commit -m "docs: document ErrorResponseContributor extension point"
```

---

## Done criteria

- [ ] All 12 tasks committed.
- [ ] `composer test` green (1358+ tests).
- [ ] `composer analyse` green.
- [ ] `vendor/bin/pint --test` clean.
- [ ] Snapshot tests pass with **zero** fixture edits — `tests/Feature/Errors/EnvelopePresetSnapshotTest.php`, `tests/Feature/Examples/`, `tests/Feature/Plugins/PluginSuiteIntegrationTest.php`.
- [ ] `tests/Arch/CoreBoundaryTest.php` enforces `Support not->toUse Core`.
- [ ] `grep -rn "StandardResponsesExtractor" src/ tests/` returns no hits.
- [ ] `grep -n "use Radiergummi\\\\OpenApi\\\\Core\\\\" src/Support/Generator/OperationBuilder.php` returns no hits.
- [ ] `CHANGELOG.md` has the `[Unreleased]` entry.
- [ ] `docs/plugin-authoring.md` documents `addErrorResponseContributor()`.

## Rollback

If snapshot tests fail at task 9 and the cause can't be quickly isolated:

```bash
git reset --hard HEAD~1   # back out the cutover commit only
```

Tasks 1–8 are pure additions; reverting task 9 leaves the new classes in place but with the old extractor still driving behaviour. Re-attempt task 9 after diagnosing.
