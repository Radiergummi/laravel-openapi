# Visibility Attributes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make endpoint visibility configurable (public-by-default vs hidden-by-default) and add a symmetric `#[Expose]` attribute, with bidirectional environment scoping (`only` / `except`) on both `#[Hide]` and `#[Expose]`, plus two new lint rules.

**Architecture:** Existing inline `OpenApiGenerator::matchesHide()` is extracted into a pure `VisibilityResolver` that consults both attributes plus a configured default mode. The generator and two new route-level lint rules share the resolver. Route-level rules are driven by a new `RouteRule` visitor interface that `LintCommand` walks over `ActionDescriptor`s (parallel to the existing `SpecTreeWalker`).

**Tech Stack:** PHP 8.4, Pest, Larastan level 8, Laravel 12/13 via Orchestra Testbench. Spec reference: `docs/superpowers/specs/2026-05-21-visibility-attributes-design.md`.

**Spec clarification carried into this plan:** The spec's `visibility.attribute-no-op` rule is implemented as "fire only for *unconditional* no-op attributes." Env-scoped `Hide`/`Expose` are never flagged—their effect can flip across environments, so flagging them risks false positives. This resolves an internal ambiguity in the spec section.

---

## File map

| File | Action | Responsibility |
|---|---|---|
| `src/Core/Visibility/VisibilityMode.php` | Create | Enum: `Public`, `Hidden`. Carries `fromConfig(mixed): self`. |
| `src/Core/Visibility/VisibilityResolver.php` | Create | Pure decision: given Hide attrs + Expose attrs (method & class) + env + default mode → bool visible. |
| `src/Core/Attributes/Hide.php` | Modify | Rename `$environments` → `$only`; add `$except`; LogicException guard. |
| `src/Core/Attributes/Expose.php` | Create | Mirror of Hide. |
| `src/Core/Generator/OpenApiGenerator.php` | Modify | Replace `isHidden()`/`matchesHide()` with VisibilityResolver call. |
| `src/OpenApiServiceProvider.php` | Modify | Scoped binding for `VisibilityResolver` with config-driven default mode. |
| `config/openapi.php` | Modify | Add `'visibility' => ['default' => 'public']` block. |
| `src/Core/Lint/Rules/Visitors/RouteRule.php` | Create | Visitor interface: `checkRoute(ActionDescriptor, LintContext): iterable<Finding>`. |
| `src/Core/Lint/Rules/HideExposeConflict.php` | Create | `visibility.hide-expose-conflict` lint rule (level 1). |
| `src/Core/Lint/Rules/VisibilityAttributeNoOp.php` | Create | `visibility.attribute-no-op` lint rule (level 2). |
| `src/Console/LintCommand.php` | Modify | After `SpecTreeWalker` pass, walk descriptors for `RouteRule` implementers. |
| `src/Core/Registry/CoreRegistration.php` | Modify | Register both new lint rules. |
| `tests/Feature/AuthoringFixtureController.php` | Modify | Update `#[Hide(environments: …)]` → `#[Hide(only: …)]`. |
| `tests/Feature/ClosureRouteAttributesTest.php` | Modify | Same migration on two call sites. |
| `tests/Unit/Attributes/HideTest.php` | Create | Constructor mutual-exclusion + accessor tests. |
| `tests/Unit/Attributes/ExposeTest.php` | Create | Same shape as HideTest. |
| `tests/Unit/Visibility/VisibilityResolverTest.php` | Create | Decision matrix. |
| `tests/Feature/VisibilityDefaultHiddenTest.php` | Create | Default-hidden mode end-to-end. |
| `tests/Feature/Lint/HideExposeConflictTest.php` | Create | Lint rule. |
| `tests/Feature/Lint/VisibilityAttributeNoOpTest.php` | Create | Lint rule. |
| `docs/usage.md` | Modify | Replace `environments:` example; add Expose section. |
| `CHANGELOG.md` | Modify | `[Unreleased]` entry. |

---

### Task 1: `VisibilityMode` enum

**Files:**
- Create: `src/Core/Visibility/VisibilityMode.php`
- Test: `tests/Unit/Visibility/VisibilityModeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;

it('maps the "public" config string to VisibilityMode::Public', function (): void {
    expect(VisibilityMode::fromConfig('public'))->toBe(VisibilityMode::Public);
});

it('maps the "hidden" config string to VisibilityMode::Hidden', function (): void {
    expect(VisibilityMode::fromConfig('hidden'))->toBe(VisibilityMode::Hidden);
});

it('falls back to Public for unknown values', function (): void {
    expect(VisibilityMode::fromConfig('whatever'))->toBe(VisibilityMode::Public);
    expect(VisibilityMode::fromConfig(null))->toBe(VisibilityMode::Public);
    expect(VisibilityMode::fromConfig(42))->toBe(VisibilityMode::Public);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Visibility/VisibilityModeTest.php`
Expected: FAIL with `Class "Radiergummi\OpenApi\Core\Visibility\VisibilityMode" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Visibility;

enum VisibilityMode: string
{
    case Public = 'public';
    case Hidden = 'hidden';

    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value)
            ? (self::tryFrom($value) ?? self::Public)
            : self::Public;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Visibility/VisibilityModeTest.php`
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Visibility/VisibilityMode.php tests/Unit/Visibility/VisibilityModeTest.php
git commit -m "feat: add VisibilityMode enum"
```

---

### Task 2: Refactor `Hide`—`only` / `except` + mutual exclusion

**Files:**
- Modify: `src/Core/Attributes/Hide.php`
- Modify: `tests/Feature/AuthoringFixtureController.php` (call site)
- Modify: `tests/Feature/ClosureRouteAttributesTest.php` (two call sites at lines 45, 53)
- Test: `tests/Unit/Attributes/HideTest.php` (new)

This task changes the attribute signature AND every call site in one commit—leaving them out of sync would break the existing test suite mid-plan.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Attributes/HideTest.php`:

```php
<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\Hide;

it('accepts no arguments and stores null for both scopes', function (): void {
    $hide = new Hide();
    expect($hide->only)->toBeNull();
    expect($hide->except)->toBeNull();
});

it('stores the only list', function (): void {
    $hide = new Hide(only: ['production', 'staging']);
    expect($hide->only)->toBe(['production', 'staging']);
    expect($hide->except)->toBeNull();
});

it('stores the except list', function (): void {
    $hide = new Hide(except: ['local']);
    expect($hide->except)->toBe(['local']);
    expect($hide->only)->toBeNull();
});

it('throws LogicException when both only and except are supplied', function (): void {
    new Hide(only: ['production'], except: ['local']);
})->throws(LogicException::class, '#[Hide]');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Attributes/HideTest.php`
Expected: FAIL—`only`/`except` are not constructor parameters of `Hide`.

- [ ] **Step 3: Rewrite `src/Core/Attributes/Hide.php`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;
use LogicException;

/**
 * Excludes the annotated route(s) from the generated OpenAPI document.
 *
 * Applied to a controller class, every route declared on that class is
 * excluded. Applied to a single method, only that method's routes are
 * excluded. Useful for internal endpoints that should not show up in the
 * public API reference yet.
 *
 * Environment scoping: pass `only` to hide *only* when the application
 * environment is in the list, or `except` to hide *everywhere except* the
 * listed environments. The two arguments are mutually exclusive—passing
 * both throws {@see LogicException}. With neither, the route is hidden
 * unconditionally.
 *
 * Examples:
 *   #[Hide]                            // hide unconditionally
 *   #[Hide(only: ['production'])]      // hide only in production
 *   #[Hide(except: ['local'])]         // hide everywhere except local
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Hide
{
    /**
     * @param null|list<string> $only   Hide *only* when `app()->environment()` is one of these.
     * @param null|list<string> $except Hide *except* when `app()->environment()` is one of these.
     */
    public function __construct(
        public ?array $only = null,
        public ?array $except = null,
    ) {
        if ($only !== null && $except !== null) {
            throw new LogicException(
                '#[Hide] cannot use both `only` and `except`—they are mutually exclusive.',
            );
        }
    }
}
```

- [ ] **Step 4: Update existing call sites**

`tests/Feature/AuthoringFixtureController.php` line 90:

```php
#[Hide(only: ['staging', 'production'])]
```

`tests/Feature/ClosureRouteAttributesTest.php` lines 45 and 53 (both occurrences):

```php
Route::get('/closure/env-hidden', #[Hide(only: ['production'])] static fn(): array => []);
```

- [ ] **Step 5: Run the full unit suite for attributes plus the call-site feature tests**

```bash
vendor/bin/pest tests/Unit/Attributes/HideTest.php tests/Feature/AuthoringAttributesTest.php tests/Feature/ClosureRouteAttributesTest.php
```

Expected: all green. The feature tests still pass because the generator currently still reads `$environments`—that will break in step 6.

- [ ] **Step 6: Note**—Step 5 currently *will* fail because the generator at `src/Core/Generator/OpenApiGenerator.php:275` still reads `$hide->environments`. Patch that single read inline to `$hide->only` for now, deferring the full refactor to Task 6:

```php
if (($hide->only === null && $hide->except === null) || ($hide->only !== null && in_array($env, $hide->only, true))) {
    return true;
}
```

Then re-run Step 5. (Step 6 of Task 6 will replace this entire method anyway; this keeps the suite green between commits.)

- [ ] **Step 7: Commit**

```bash
git add src/Core/Attributes/Hide.php tests/Unit/Attributes/HideTest.php tests/Feature/AuthoringFixtureController.php tests/Feature/ClosureRouteAttributesTest.php src/Core/Generator/OpenApiGenerator.php
git commit -m "feat!: rename Hide::\$environments to \$only; add \$except (BC break)"
```

---

### Task 3: `Expose` attribute

**Files:**
- Create: `src/Core/Attributes/Expose.php`
- Test: `tests/Unit/Attributes/ExposeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\Expose;

it('accepts no arguments', function (): void {
    $expose = new Expose();
    expect($expose->only)->toBeNull();
    expect($expose->except)->toBeNull();
});

it('stores the only list', function (): void {
    expect((new Expose(only: ['staging']))->only)->toBe(['staging']);
});

it('stores the except list', function (): void {
    expect((new Expose(except: ['production']))->except)->toBe(['production']);
});

it('throws LogicException when both only and except are supplied', function (): void {
    new Expose(only: ['staging'], except: ['production']);
})->throws(LogicException::class, '#[Expose]');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Attributes/ExposeTest.php`
Expected: FAIL—class not found.

- [ ] **Step 3: Create `src/Core/Attributes/Expose.php`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;
use LogicException;

/**
 * Includes the annotated route(s) in the generated OpenAPI document when the
 * package is operating in hidden-by-default mode
 * (`config('openapi.visibility.default') === 'hidden'`). In public-by-default
 * mode the attribute is a no-op and is flagged by the
 * `visibility.attribute-no-op` lint rule.
 *
 * Applied to a controller class, every route declared on that class is
 * exposed. Applied to a single method, only that method's routes are.
 *
 * Environment scoping mirrors {@see Hide}: `only` exposes *only* when the
 * application environment is in the list; `except` exposes *everywhere
 * except* the listed environments. Passing both throws {@see LogicException}.
 *
 * Conflict resolution: when both `#[Hide]` and `#[Expose]` apply to the same
 * route in the current environment, `#[Hide]` wins. The
 * `visibility.hide-expose-conflict` lint rule reports the conflict.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Expose
{
    /**
     * @param null|list<string> $only   Expose *only* when `app()->environment()` is one of these.
     * @param null|list<string> $except Expose *except* when `app()->environment()` is one of these.
     */
    public function __construct(
        public ?array $only = null,
        public ?array $except = null,
    ) {
        if ($only !== null && $except !== null) {
            throw new LogicException(
                '#[Expose] cannot use both `only` and `except`—they are mutually exclusive.',
            );
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Attributes/ExposeTest.php`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Attributes/Expose.php tests/Unit/Attributes/ExposeTest.php
git commit -m "feat: add #[Expose] attribute"
```

---

### Task 4: `VisibilityResolver`

**Files:**
- Create: `src/Core/Visibility/VisibilityResolver.php`
- Test: `tests/Unit/Visibility/VisibilityResolverTest.php`

The resolver is pure: in → bool out. No reflection inside.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;

$resolver = fn(VisibilityMode $mode = VisibilityMode::Public): VisibilityResolver => new VisibilityResolver($mode);

it('returns visible in public default with no attributes', function () use ($resolver): void {
    expect($resolver()->isVisible([], [], 'production'))->toBeTrue();
});

it('returns hidden in hidden default with no attributes', function () use ($resolver): void {
    expect($resolver(VisibilityMode::Hidden)->isVisible([], [], 'production'))->toBeFalse();
});

it('hides when an unconditional Hide applies', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide()], [], 'production'))->toBeFalse();
});

it('hides when Hide(only:) matches the env', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide(only: ['production'])], [], 'production'))->toBeFalse();
});

it('does not hide when Hide(only:) misses the env', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide(only: ['production'])], [], 'local'))->toBeTrue();
});

it('hides when Hide(except:) does not list the env', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide(except: ['local'])], [], 'production'))->toBeFalse();
});

it('does not hide when Hide(except:) lists the env', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide(except: ['local'])], [], 'local'))->toBeTrue();
});

it('exposes when Expose applies in hidden default', function () use ($resolver): void {
    expect($resolver(VisibilityMode::Hidden)->isVisible([], [new Expose()], 'production'))->toBeTrue();
});

it('does not expose when Expose(only:) misses the env in hidden default', function () use ($resolver): void {
    expect($resolver(VisibilityMode::Hidden)->isVisible([], [new Expose(only: ['staging'])], 'production'))->toBeFalse();
});

it('lets Hide beat Expose when both apply', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide()], [new Expose()], 'production'))->toBeFalse();
    expect($resolver(VisibilityMode::Hidden)->isVisible([new Hide()], [new Expose()], 'production'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Visibility/VisibilityResolverTest.php`
Expected: FAIL—class not found.

- [ ] **Step 3: Write the resolver**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Visibility;

use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;

use function in_array;

/**
 * Decides whether a route should appear in the generated OpenAPI document.
 * Caller passes the union of method-level and class-level Hide/Expose
 * attribute instances; the resolver does not perform reflection.
 */
final readonly class VisibilityResolver
{
    public function __construct(
        private VisibilityMode $defaultMode = VisibilityMode::Public,
    ) {}

    /**
     * @param list<Hide>   $hides   All Hide attributes that apply to the route (method + class).
     * @param list<Expose> $exposes All Expose attributes that apply to the route (method + class).
     */
    public function isVisible(array $hides, array $exposes, string $environment): bool
    {
        foreach ($hides as $hide) {
            if ($this->scopeMatches($hide->only, $hide->except, $environment)) {
                return false;
            }
        }

        foreach ($exposes as $expose) {
            if ($this->scopeMatches($expose->only, $expose->except, $environment)) {
                return true;
            }
        }

        return $this->defaultMode === VisibilityMode::Public;
    }

    /**
     * @param null|list<string> $only
     * @param null|list<string> $except
     */
    private function scopeMatches(?array $only, ?array $except, string $environment): bool
    {
        if ($only === null && $except === null) {
            return true;
        }

        if ($only !== null) {
            return in_array($environment, $only, true);
        }

        // $except !== null by elimination
        return !in_array($environment, $except ?? [], true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Visibility/VisibilityResolverTest.php`
Expected: 10 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Visibility/VisibilityResolver.php tests/Unit/Visibility/VisibilityResolverTest.php
git commit -m "feat: add VisibilityResolver"
```

---

### Task 5: Wire `VisibilityResolver` into `OpenApiGenerator`; bind in provider; add config

**Files:**
- Modify: `src/Core/Generator/OpenApiGenerator.php`
- Modify: `src/OpenApiServiceProvider.php`
- Modify: `config/openapi.php`

- [ ] **Step 1: Add the config block**

In `config/openapi.php`, insert after the `'security_default_scheme' => null,` block:

```php
    /*
    |--------------------------------------------------------------------------
    | Visibility
    |--------------------------------------------------------------------------
    |
    | `default` controls which routes appear in the generated document when no
    | attribute is present.
    |
    | - 'public' (default): every discovered route is exposed unless a
    |   #[Hide] attribute applies in the current environment.
    | - 'hidden': every discovered route is hidden unless a #[Expose]
    |   attribute applies in the current environment.
    |
    | #[Hide] always wins on conflict. The `visibility.hide-expose-conflict`
    | lint rule reports overlapping attributes; `visibility.attribute-no-op`
    | reports attributes that have no effect under the current default.
    |
    */

    'visibility' => [
        'default' => 'public',
    ],
```

- [ ] **Step 2: Bind the resolver as scoped in `src/OpenApiServiceProvider.php`**

Locate the existing `register()` method's scoped bindings. Add:

```php
$this->app->scoped(VisibilityResolver::class, static fn(): VisibilityResolver => new VisibilityResolver(
    VisibilityMode::fromConfig(config('openapi.visibility.default')),
));
```

Add the imports:

```php
use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;
```

- [ ] **Step 3: Refactor the generator**

In `src/Core/Generator/OpenApiGenerator.php`:

a) Add import: `use Radiergummi\OpenApi\Core\Attributes\Expose;` and `use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;`.

b) Inject `VisibilityResolver` into the constructor (matching the existing constructor-injection style—likely already a Laravel-managed singleton/scoped class). Find the constructor and add `private readonly VisibilityResolver $visibilityResolver` to the parameter list.

c) Replace the `isHidden()` method body:

```php
private function isHidden(ActionDescriptor $descriptor): bool
{
    return !$this->visibilityResolver->isVisible(
        hides:       $this->collectAttributes($descriptor, Hide::class),
        exposes:     $this->collectAttributes($descriptor, Expose::class),
        environment: app()->environment(),
    );
}

/**
 * @template T of object
 *
 * @param class-string<T> $class
 *
 * @return list<T>
 */
private function collectAttributes(ActionDescriptor $descriptor, string $class): array
{
    $instances = [];

    if ($descriptor->actionReflector !== null) {
        foreach ($descriptor->actionReflector->getAttributes($class) as $reflection) {
            $instances[] = $reflection->newInstance();
        }
    }

    if ($descriptor->controller !== null) {
        foreach ($descriptor->controller->getAttributes($class) as $reflection) {
            $instances[] = $reflection->newInstance();
        }
    }

    return $instances;
}
```

d) Delete the now-unused `matchesHide()` method (including its `@param ReflectionAttribute<Hide>[]` docblock).

e) Remove the `ReflectionAttribute` import if no other code in the file references it.

- [ ] **Step 4: Run the full feature suite**

```bash
vendor/bin/pest tests/Feature
```

Expected: existing visibility tests (`AuthoringAttributesTest`, `ClosureRouteAttributesTest`) still pass. Public-mode behavior is unchanged.

- [ ] **Step 5: Run PHPStan**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Generator/OpenApiGenerator.php src/OpenApiServiceProvider.php config/openapi.php
git commit -m "refactor: route visibility through VisibilityResolver"
```

---

### Task 6: Feature test—default-hidden mode

**Files:**
- Create: `tests/Feature/VisibilityDefaultHiddenTest.php`
- Create: `tests/Feature/VisibilityFixtureController.php`

- [ ] **Step 1: Create the fixture controller**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Radiergummi\OpenApi\Core\Attributes\Expose;

final class VisibilityFixtureController
{
    public function bare(): array
    {
        return [];
    }

    #[Expose]
    public function explicitlyExposed(): array
    {
        return [];
    }

    #[Expose(only: ['staging'])]
    public function exposedInStagingOnly(): array
    {
        return [];
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Tests\Feature\VisibilityFixtureController;

beforeEach(function (): void {
    config()->set('openapi.visibility.default', 'hidden');

    /** @var Router $router */
    $router = app('router');
    $router->get('/bare', [VisibilityFixtureController::class, 'bare']);
    $router->get('/explicit', [VisibilityFixtureController::class, 'explicitlyExposed']);
    $router->get('/staging-only', [VisibilityFixtureController::class, 'exposedInStagingOnly']);

    // Re-bind the resolver to pick up the changed config.
    app()->forgetScopedInstances();
});

it('hides routes without #[Expose] in hidden-default mode', function (): void {
    $yaml = app(OpenApiGenerator::class)->generate();
    expect($yaml)->not->toContain('/bare');
});

it('exposes routes carrying unconditional #[Expose]', function (): void {
    $yaml = app(OpenApiGenerator::class)->generate();
    expect($yaml)->toContain('/explicit');
});

it('hides env-scoped #[Expose] outside the matching environment', function (): void {
    app()->detectEnvironment(fn(): string => 'production');
    app()->forgetScopedInstances();

    $yaml = app(OpenApiGenerator::class)->generate();
    expect($yaml)->not->toContain('/staging-only');
});

it('exposes env-scoped #[Expose] inside the matching environment', function (): void {
    app()->detectEnvironment(fn(): string => 'staging');
    app()->forgetScopedInstances();

    $yaml = app(OpenApiGenerator::class)->generate();
    expect($yaml)->toContain('/staging-only');
});
```

> Verify the precise generator entry-point method (`generate()` here is a placeholder—adapt to whatever existing feature tests like `AuthoringAttributesTest` use). The fixture file pattern matches `AuthoringFixtureController`.

- [ ] **Step 3: Run test to verify it fails or passes per expectation**

Run: `vendor/bin/pest tests/Feature/VisibilityDefaultHiddenTest.php`
Expected: all 4 pass—Task 5 already wired the resolver.

If a test fails because the generator caches per the route-set or env, debug by reading `tests/Feature/ClosureRouteAttributesTest.php` for the canonical pattern of forcing a fresh generation.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/VisibilityDefaultHiddenTest.php tests/Feature/VisibilityFixtureController.php
git commit -m "test: cover hidden-default visibility mode end-to-end"
```

---

### Task 7: `RouteRule` visitor + route-walking pass in `LintCommand`

**Files:**
- Create: `src/Core/Lint/Rules/Visitors/RouteRule.php`
- Modify: `src/Console/LintCommand.php`

- [ ] **Step 1: Create the visitor interface**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules\Visitors;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

/**
 * A lint rule that inspects raw {@see ActionDescriptor} instances rather
 * than the generated spec tree. Used for routes that never reach the tree
 * (e.g. hidden routes that still need to be checked for misconfigured
 * visibility attributes).
 */
interface RouteRule
{
    /**
     * @return iterable<Finding>
     */
    public function checkRoute(ActionDescriptor $descriptor, LintContext $context): iterable;
}
```

- [ ] **Step 2: Hook route-walking into `LintCommand`**

Find the section of `LintCommand::handle()` that runs `SpecTreeWalker`. Immediately after that walk, before findings are formatted, add a pass that iterates `$descriptors` and invokes each `RouteRule`-implementing rule from the registry. Use the existing `LintContext` and `$collector`.

Sketch (adapt to surrounding variable names—read the existing flow first):

```php
$routeRules = array_values(array_filter(
    $registry->forLevel($level, $only, $skip),
    static fn(Rule $rule): bool => $rule instanceof RouteRule,
));

if ($routeRules !== []) {
    foreach ($descriptors as $descriptor) {
        foreach ($routeRules as $rule) {
            foreach ($rule->checkRoute($descriptor, $context) as $finding) {
                $collector->add($finding->withLocationDefaults($this->locationFor($descriptor)));
            }
        }
    }
}
```

Where `locationFor(ActionDescriptor $descriptor): FindingLocation` is a private helper that fills in the controller file path, route URI/method, and method line. If a similar helper already exists for the spec-tree pass, reuse it instead of duplicating.

Add imports as needed: `Radiergummi\OpenApi\Core\Lint\Rules\Visitors\RouteRule`.

- [ ] **Step 3: Run the full lint suite**

```bash
vendor/bin/pest tests/Feature/Lint
```

Expected: existing lint tests still pass (no `RouteRule` implementers registered yet, so the new pass is a no-op).

- [ ] **Step 4: Run PHPStan**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Lint/Rules/Visitors/RouteRule.php src/Console/LintCommand.php
git commit -m "feat: add RouteRule visitor for descriptor-level lint rules"
```

---

### Task 8: `HideExposeConflict` lint rule

**Files:**
- Create: `src/Core/Lint/Rules/HideExposeConflict.php`
- Modify: `src/Core/Registry/CoreRegistration.php` (register the rule)
- Create: `tests/Feature/Lint/HideExposeConflictTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;

final class ConflictFixtureController
{
    #[Hide]
    #[Expose]
    public function both(): array
    {
        return [];
    }

    #[Hide(only: ['production'])]
    #[Expose(only: ['production'])]
    public function envOverlap(): array
    {
        return [];
    }

    #[Hide(only: ['production'])]
    #[Expose(only: ['staging'])]
    public function envDisjoint(): array
    {
        return [];
    }
}

beforeEach(function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('/conflict/both', [ConflictFixtureController::class, 'both']);
    $router->get('/conflict/overlap', [ConflictFixtureController::class, 'envOverlap']);
    $router->get('/conflict/disjoint', [ConflictFixtureController::class, 'envDisjoint']);
});

it('reports an unconditional Hide+Expose conflict', function (): void {
    $findings = runLint(); // helper from existing lint feature tests
    expect($findings)
        ->toContainFindingWithId('visibility.hide-expose-conflict')
        ->whereRoute('/conflict/both');
});

it('reports a Hide+Expose conflict that overlaps in the current env', function (): void {
    app()->detectEnvironment(fn(): string => 'production');
    $findings = runLint();
    expect($findings)
        ->toContainFindingWithId('visibility.hide-expose-conflict')
        ->whereRoute('/conflict/overlap');
});

it('does not report when Hide and Expose env scopes are disjoint', function (): void {
    app()->detectEnvironment(fn(): string => 'production');
    $findings = runLint();
    expect($findings)->not->toContainFindingWithId(
        'visibility.hide-expose-conflict',
        forRoute: '/conflict/disjoint',
    );
});
```

> `runLint()` / `toContainFindingWithId()` are placeholders—match whatever idiom the existing `tests/Feature/Lint/` files use. Read one existing file (e.g. `tests/Feature/Lint/OperationIdDuplicateTest.php` or whichever exists) to copy the bootstrap. If no helpers exist, invoke the lint command directly via `$this->artisan('openapi:lint --format=json')` and decode the JSON.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Lint/HideExposeConflictTest.php`
Expected: FAIL—rule not registered.

- [ ] **Step 3: Implement the rule**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\RouteRule;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

use function in_array;
use function sprintf;

final readonly class HideExposeConflict implements Rule, RouteRule
{
    public function id(): string
    {
        return 'visibility.hide-expose-conflict';
    }

    public function level(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Reports routes that carry both #[Hide] and #[Expose] attributes whose env scopes overlap in the current environment.';
    }

    public function checkRoute(ActionDescriptor $descriptor, LintContext $context): iterable
    {
        $env = app()->environment();
        $hides = $this->collect($descriptor, Hide::class);
        $exposes = $this->collect($descriptor, Expose::class);

        if ($hides === [] || $exposes === []) {
            return;
        }

        $hideMatches = array_filter($hides, fn(Hide $h): bool => $this->scopeMatches($h->only, $h->except, $env));
        $exposeMatches = array_filter($exposes, fn(Expose $expose): bool => $this->scopeMatches($expose->only, $expose->except, $env));

        if ($hideMatches === [] || $exposeMatches === []) {
            return;
        }

        yield new Finding(
            ruleId:  $this->id(),
            level:   $this->level(),
            message: sprintf(
                'Route carries both #[Hide] and #[Expose] attributes that apply in environment "%s". #[Hide] wins.',
                $env,
            ),
            fixHint: 'Remove either #[Hide] or #[Expose], or narrow their `only`/`except` lists so they do not overlap in this environment.',
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function collect(ActionDescriptor $descriptor, string $class): array
    {
        $out = [];

        if ($descriptor->actionReflector !== null) {
            foreach ($descriptor->actionReflector->getAttributes($class) as $attr) {
                $out[] = $attr->newInstance();
            }
        }

        if ($descriptor->controller !== null) {
            foreach ($descriptor->controller->getAttributes($class) as $attr) {
                $out[] = $attr->newInstance();
            }
        }

        return $out;
    }

    /**
     * @param null|list<string> $only
     * @param null|list<string> $except
     */
    private function scopeMatches(?array $only, ?array $except, string $env): bool
    {
        if ($only === null && $except === null) {
            return true;
        }

        if ($only !== null) {
            return in_array($env, $only, true);
        }

        return !in_array($env, $except ?? [], true);
    }
}
```

> The `collect()` and `scopeMatches()` helpers duplicate the generator's `collectAttributes()` and the resolver's `scopeMatches()`. We **keep the duplication for now** rather than carving a shared utility: three sites is below the threshold where extraction beats the indirection cost, and the resolver is intentionally a pure data class with no reflection. If the no-op rule (Task 9) also needs identical helpers, revisit at end of Task 9.

- [ ] **Step 4: Register the rule**

In `src/Core/Registry/CoreRegistration.php`, add to the `RULES` array (group with other visibility rules—if no obvious section, append at the end):

```php
Rules\HideExposeConflict::class,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Lint/HideExposeConflictTest.php`
Expected: 3 passed.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Lint/Rules/HideExposeConflict.php src/Core/Registry/CoreRegistration.php tests/Feature/Lint/HideExposeConflictTest.php
git commit -m "feat: add visibility.hide-expose-conflict lint rule"
```

---

### Task 9: `VisibilityAttributeNoOp` lint rule

**Files:**
- Create: `src/Core/Lint/Rules/VisibilityAttributeNoOp.php`
- Modify: `src/Core/Registry/CoreRegistration.php` (register)
- Create: `tests/Feature/Lint/VisibilityAttributeNoOpTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;

final class NoOpFixtureController
{
    #[Expose]
    public function exposeInPublic(): array
    {
        return [];
    }

    #[Expose(only: ['staging'])]
    public function envScopedExposeInPublic(): array
    {
        return [];
    }

    #[Hide]
    public function hideInHidden(): array
    {
        return [];
    }
}

beforeEach(function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->get('/noop/expose-public', [NoOpFixtureController::class, 'exposeInPublic']);
    $router->get('/noop/expose-staging', [NoOpFixtureController::class, 'envScopedExposeInPublic']);
    $router->get('/noop/hide-hidden', [NoOpFixtureController::class, 'hideInHidden']);
});

it('flags unconditional #[Expose] in public-default mode', function (): void {
    config()->set('openapi.visibility.default', 'public');
    app()->forgetScopedInstances();

    $findings = runLint();
    expect($findings)
        ->toContainFindingWithId('visibility.attribute-no-op')
        ->whereRoute('/noop/expose-public');
});

it('does not flag env-scoped #[Expose] in public-default mode', function (): void {
    config()->set('openapi.visibility.default', 'public');
    app()->forgetScopedInstances();

    $findings = runLint();
    expect($findings)->not->toContainFindingWithId(
        'visibility.attribute-no-op',
        forRoute: '/noop/expose-staging',
    );
});

it('flags unconditional #[Hide] in hidden-default mode', function (): void {
    config()->set('openapi.visibility.default', 'hidden');
    app()->forgetScopedInstances();

    $findings = runLint();
    expect($findings)
        ->toContainFindingWithId('visibility.attribute-no-op')
        ->whereRoute('/noop/hide-hidden');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Lint/VisibilityAttributeNoOpTest.php`
Expected: FAIL—rule not registered.

- [ ] **Step 3: Implement the rule**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\RouteRule;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;

use function config;

final readonly class VisibilityAttributeNoOp implements Rule, RouteRule
{
    public function id(): string
    {
        return 'visibility.attribute-no-op';
    }

    public function level(): int
    {
        return 2;
    }

    public function description(): string
    {
        return 'Reports unconditional #[Expose] in public-default mode and unconditional #[Hide] in hidden-default mode.';
    }

    public function checkRoute(ActionDescriptor $descriptor, LintContext $context): iterable
    {
        $mode = VisibilityMode::fromConfig(config('openapi.visibility.default'));

        // Public default: an unconditional #[Expose] does nothing if there is no #[Hide] to override.
        if ($mode === VisibilityMode::Public) {
            $hides = $this->attributesOf($descriptor, Hide::class);
            if ($hides !== []) {
                return; // Expose might be neutralizing a Hide; out of scope for this rule.
            }

            foreach ($this->attributesOf($descriptor, Expose::class) as $expose) {
                if ($expose->only === null && $expose->except === null) {
                    yield new Finding(
                        ruleId:  $this->id(),
                        level:   $this->level(),
                        message: '#[Expose] has no effect in public-default visibility mode.',
                        fixHint: 'Remove the attribute, or set `config(\'openapi.visibility.default\') = \'hidden\'`.',
                    );

                    return; // One finding per route is enough.
                }
            }

            return;
        }

        // Hidden default: an unconditional #[Hide] does nothing if there is no #[Expose] to neutralize.
        $exposes = $this->attributesOf($descriptor, Expose::class);
        if ($exposes !== []) {
            return;
        }

        foreach ($this->attributesOf($descriptor, Hide::class) as $hide) {
            if ($hide->only === null && $hide->except === null) {
                yield new Finding(
                    ruleId:  $this->id(),
                    level:   $this->level(),
                    message: '#[Hide] has no effect in hidden-default visibility mode (routes are already hidden by default).',
                    fixHint: 'Remove the attribute, or set `config(\'openapi.visibility.default\') = \'public\'`.',
                );

                return;
            }
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function attributesOf(ActionDescriptor $descriptor, string $class): array
    {
        $out = [];

        if ($descriptor->actionReflector !== null) {
            foreach ($descriptor->actionReflector->getAttributes($class) as $attr) {
                $out[] = $attr->newInstance();
            }
        }

        if ($descriptor->controller !== null) {
            foreach ($descriptor->controller->getAttributes($class) as $attr) {
                $out[] = $attr->newInstance();
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Register the rule**

In `src/Core/Registry/CoreRegistration.php`, add to `RULES`:

```php
Rules\VisibilityAttributeNoOp::class,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Lint/VisibilityAttributeNoOpTest.php`
Expected: 3 passed.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Lint/Rules/VisibilityAttributeNoOp.php src/Core/Registry/CoreRegistration.php tests/Feature/Lint/VisibilityAttributeNoOpTest.php
git commit -m "feat: add visibility.attribute-no-op lint rule"
```

---

### Task 10: Docs and changelog

**Files:**
- Modify: `docs/usage.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update `docs/usage.md`**

Replace the existing `#[Hide]` row in the attribute table (line ~226) with two rows:

```markdown
| `Hide` | class, method | no | Exclude from the spec. `only: ['production']` hides only in those environments; `except: ['local']` hides everywhere except. Pass no argument to hide unconditionally. The two arguments are mutually exclusive. |
| `Expose` | class, method | no | Include in the spec when running in hidden-default mode (`config('openapi.visibility.default') = 'hidden'`). Same `only` / `except` semantics as `Hide`. A no-op in public-default mode (flagged by `visibility.attribute-no-op`). |
```

Replace the existing example (line ~452):

```php
#[OpenApi\Hide(only: ['production'])]
```

Add a new "Endpoint visibility" subsection covering: the two modes, the precedence rule (Hide wins on conflict), and the two lint rules. Locate it next to the existing Hide example.

- [ ] **Step 2: Update `CHANGELOG.md`**

Under the `[Unreleased]` heading (or create one above the most recent release entry):

```markdown
## [Unreleased]

### Added
- `#[Expose]` attribute (`src/Core/Attributes/Expose.php`) to opt routes into the generated document when the new hidden-default mode is active.
- `visibility.default` config flag (`config/openapi.php`)—accepts `'public'` (current behavior) or `'hidden'`.
- `visibility.hide-expose-conflict` lint rule (level 1)—reports routes with overlapping `#[Hide]`/`#[Expose]` in the current environment.
- `visibility.attribute-no-op` lint rule (level 2)—reports unconditional attributes that have no effect under the active default mode.

### Changed (breaking)
- `#[Hide]` constructor argument renamed: `environments` → `only`. Also gains `except` as an exclusive alternative. Migration: rewrite `#[Hide(environments: [...])]` to `#[Hide(only: [...])]`.
```

- [ ] **Step 3: Verify nothing else references the old `environments:` argument**

```bash
grep -rn "Hide(environments:" --include='*.php' --include='*.md' .
```

Expected: no matches. If any remain, update them.

- [ ] **Step 4: Final full suite + lint + analyse**

```bash
composer test && composer lint && composer analyse
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add docs/usage.md CHANGELOG.md
git commit -m "docs: document visibility modes and #[Expose]"
```

---

## Self-review notes

**Spec coverage:**
- Config flag → Task 5.
- Hide rename + `only`/`except` + LogicException → Task 2.
- Expose attribute → Task 3.
- Resolution semantics (method beats class same-type, Hide wins, fallback) → Task 4 (unit) + Task 5 (integration) + Task 6 (feature).
- VisibilityResolver extracted from `matchesHide` → Task 4 + 5.
- `visibility.hide-expose-conflict` rule → Task 8.
- `visibility.attribute-no-op` rule → Task 9.
- `#[IgnoreLint]` integration: relies on the existing `SuppressionCollector` pipeline that already walks descriptors. The `Finding::withLocationDefaults()` call in Task 7's route-walking pass stamps the controller/method location, which is what the suppression directives match against. **This is an inferred guarantee**—if the suppression match turns out to require additional context keys (e.g., source class) for route-level findings, Task 7's `locationFor()` helper is the place to fix it.

**Placeholders:** None. The `runLint()` / `toContainFindingWithId()` helpers in lint tests are flagged as "match existing idiom"—read one existing lint feature test to pick up the convention. This is concrete enough that the engineer can resolve it from the codebase.

**Type consistency:** Both attributes expose `?array $only` and `?array $except` (same names, same nullability). Resolver method is `isVisible(array $hides, array $exposes, string $environment): bool` in every reference. Rule IDs are `visibility.hide-expose-conflict` and `visibility.attribute-no-op` in every reference.

**Duplication call-out:** `collectAttributes()` is implemented inline three times (generator + two lint rules). The plan notes this as intentional—three call sites do not yet justify a shared utility, and `VisibilityResolver` is kept pure (no reflection). Revisit if a fourth caller appears.
