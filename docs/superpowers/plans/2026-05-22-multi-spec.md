# Multi-Spec Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let one Laravel application produce several independent OpenAPI documents — `default`, plus any number of named specs partitioned by URL prefix, middleware, namespace, or `#[Spec]` attribute.

**Architecture:** A new `SpecRegistry` (config-derived, `scoped`) materialises a list of `SpecDefinition` value objects. An `InclusionEvaluator` is the single source of truth for "does route R belong to spec X?" — used by the generator, by `openapi:why`, and by `openapi:generate --explain`. The existing `OpenApiGenerator::generate()` becomes spec-parameterised; a thin `OpenApiGenerationOrchestrator` exposes `generateOne()` / `generateAll()`. Lint gains a `PreBuildRule` interface for config-level rules and loops per-spec for the existing visitor rules. HTTP serving mounts one `spec` + one `playground` route per spec with non-null `route_uri` / `playground_uri`.

**Tech Stack:** PHP 8.4 strict-typed, Laravel 12/13, Pest (Testbench), Larastan level 8, swagger-php (OpenAPI 3.1).

**Spec:** `docs/superpowers/specs/2026-05-22-multi-spec-design.md` — implementation MUST match the design verbatim. When the plan and spec disagree, the spec wins; revise the plan rather than drift.

---

## File map

**New (production):**
- `src/Core/Spec/SpecDefinition.php` — immutable value object
- `src/Core/Spec/SpecRegistry.php` — config-derived registry
- `src/Core/Spec/SpecMatcher.php` — `prefix` / `middleware` / `namespace` evaluator
- `src/Core/Spec/SpecResolver.php` — reads `#[Spec]` from an `ActionDescriptor`
- `src/Core/Attributes/Spec.php` — the new attribute
- `src/Core/Inclusion/InclusionEvaluator.php` — the 4-rule decision
- `src/Core/Inclusion/InclusionDecision.php` — result value object
- `src/Core/Inclusion/TraceEntry.php` — debug trace line
- `src/Core/Generator/OpenApiGenerationOrchestrator.php` — `generateOne` / `generateAll`
- `src/Console/WhyCommand.php` — `openapi:why`
- `src/Core/Lint/Rules/Visitors/PreBuildRule.php` — pre-build rule contract
- `src/Core/Lint/Rules/SpecUnknownReference.php`
- `src/Core/Lint/Rules/SpecRouteOrphaned.php`
- `src/Core/Lint/Rules/SpecConfigOrphaned.php`
- `docs/multi-spec.md`

**New (tests):**
- `tests/Unit/Spec/SpecDefinitionTest.php`
- `tests/Unit/Spec/SpecMatcherTest.php`
- `tests/Unit/Spec/SpecRegistryTest.php`
- `tests/Unit/Spec/SpecResolverTest.php`
- `tests/Unit/Attributes/SpecTest.php`
- `tests/Unit/Inclusion/InclusionEvaluatorTest.php`
- `tests/Unit/Core/Generator/OpenApiGenerationOrchestratorTest.php`
- `tests/Unit/Console/WhyCommandTest.php`
- `tests/Unit/Lint/Rules/SpecUnknownReferenceTest.php`
- `tests/Unit/Lint/Rules/SpecRouteOrphanedTest.php`
- `tests/Unit/Lint/Rules/SpecConfigOrphanedTest.php`
- `tests/Feature/MultiSpecGenerationTest.php`
- `tests/Feature/MultiSpecRoutesTest.php`
- `tests/Feature/NoEagerResolutionTest.php`

**Modified (production):**
- `src/Core/Generator/ComponentSchemaRegistry.php` — add `reset()`
- `src/Core/Generator/ExampleFileLoader.php` — add `reset()`
- `src/Core/Generator/OpenApiGenerator.php` — `generate(SpecDefinition $spec, …)` signature; reads info/servers/tags from spec; routes through `InclusionEvaluator`
- `src/OpenApiServiceProvider.php` — bind new services; mount per-spec routes
- `src/Http/DocsController.php` — accept `?string $spec` route binding
- `src/Console/GenerateCommand.php` — optional positional `spec`; `--explain` flag; loops over orchestrator
- `src/Console/ClearCommand.php` — optional positional `spec`
- `src/Console/LintCommand.php` — `--spec=` option
- `src/Core/Lint/LintRunner.php` — pre-build phase; per-spec generation loop; spec-tagged findings
- `src/Core/Lint/LintOptions.php` — `?string $spec` field
- `src/Core/Lint/LintResult.php` — `spec` key on per-finding grouping (already in `Finding`)
- `src/Core/Lint/Finding.php` — add `?string $spec` field
- `src/Core/Lint/Formatters/CliFormatter.php` — group by spec
- `src/Core/Lint/Formatters/GithubFormatter.php` — add `spec` to annotation context
- `src/Core/Lint/Formatters/JsonFormatter.php` — passes through (`spec` already on Finding)
- `config/openapi.php` — commented `'specs'` example
- `tests/Pest.php` — update `generateSpec()` helper
- `README.md`, `docs/usage.md`, `docs/lint-rules.md`, `CHANGELOG.md`

---

## Phase A — Foundations

### Task A1: Add `reset()` to per-run-stateful services

`OpenApiGenerationOrchestrator` will need to clear `ComponentSchemaRegistry` and `ExampleFileLoader` between specs in a single process. The classes carry mutable state but currently have no reset hook.

**Files:**
- Modify: `src/Core/Generator/ComponentSchemaRegistry.php`
- Modify: `src/Core/Generator/ExampleFileLoader.php`
- Test: `tests/Unit/ComponentSchemaRegistryTest.php`
- Test: `tests/Feature/ExampleFileLoaderTest.php`

- [ ] **Step 1: Write a failing test for `ComponentSchemaRegistry::reset()`**

Add to `tests/Unit/ComponentSchemaRegistryTest.php`:

```php
it('reset() clears registered schemas, responses, and per-class caches', function (): void {
    $registry = new ComponentSchemaRegistry();
    $schema = new OA\Schema(['type' => 'object']);
    $registry->register(SomeRegisteredFixtureData::class, $schema);
    $registry->registerNamedResponse('Error', new OA\Response(['description' => 'x']));
    $registry->setCompiledFields(SomeRegisteredFixtureData::class, []);

    $registry->reset();

    expect($registry->all())->toBe([])
        ->and($registry->allResponses())->toBe([])
        ->and($registry->keyFor(SomeRegisteredFixtureData::class))->toBeNull()
        ->and($registry->compiledFields(SomeRegisteredFixtureData::class))->toBeNull();
});
```

Use any existing fixture class with `SomeRegisteredFixtureData` swapped to a real test fixture (check `tests/Fixtures/`).

- [ ] **Step 2: Run the test and confirm failure**

```
vendor/bin/pest tests/Unit/ComponentSchemaRegistryTest.php --filter "reset"
```

Expected: error — `Method reset does not exist`.

- [ ] **Step 3: Add `reset()` to `ComponentSchemaRegistry`**

Insert at the bottom of the class body (before the closing brace), inside a new region marker for clarity:

```php
    // region Lifecycle

    /**
     * Clears all registered schemas, responses, key reservations and per-class caches.
     *
     * Invoked by {@see OpenApiGenerationOrchestrator} between spec generations in a
     * single process so that multi-spec runs don't leak components between specs.
     */
    public function reset(): void
    {
        $this->schemas = [];
        $this->responses = [];
        $this->classToKey = [];
        $this->keyToClass = [];
        $this->inProgress = [];
        $this->compiledFields = [];
        $this->compiledItemsFields = [];
        $this->hasFileFields = [];
    }

    // endregion
```

- [ ] **Step 4: Run the test, confirm pass**

```
vendor/bin/pest tests/Unit/ComponentSchemaRegistryTest.php --filter "reset"
```

Expected: PASS.

- [ ] **Step 5: Add an equivalent `reset()` to `ExampleFileLoader`**

Open `src/Core/Generator/ExampleFileLoader.php`, identify all mutable instance state (cache arrays), and add a `reset()` method that empties each one. Pattern is identical to A1's step 3 — use a `// region Lifecycle` marker. Write a small unit test under `tests/Feature/ExampleFileLoaderTest.php` (or a new `tests/Unit/Core/Generator/ExampleFileLoaderTest.php` if more natural) following the same shape: prime some state, call `reset()`, assert empty.

- [ ] **Step 6: Run the full unit suite**

```
vendor/bin/pest tests/Unit/
```

Expected: green.

- [ ] **Step 7: Commit**

```
git add src/Core/Generator/ComponentSchemaRegistry.php src/Core/Generator/ExampleFileLoader.php tests/Unit/ComponentSchemaRegistryTest.php tests/Feature/ExampleFileLoaderTest.php
git commit -m "feat(generator): add reset() to per-run stateful services"
```

---

### Task A2: `SpecDefinition` value object

Immutable record describing one named spec. Pure data — no logic, no Laravel deps. Used by `SpecRegistry`, `OpenApiGenerator`, `InclusionEvaluator`.

**Files:**
- Create: `src/Core/Spec/SpecDefinition.php`
- Test: `tests/Unit/Spec/SpecDefinitionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Spec;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;

it('holds the spec name, info, servers, tags, match config, paths', function (): void {
    $info = new OA\Info(['title' => 'V1', 'version' => '1.0']);
    $server = new OA\Server(['url' => 'https://v1.example.com']);
    $tag = new OA\Tag(['name' => 'Flights']);

    $spec = new SpecDefinition(
        name: 'v1',
        info: $info,
        servers: [$server],
        tags: [$tag],
        match: ['prefix' => 'api/v1/*'],
        outputPath: '/tmp/openapi-v1.yaml',
        routeUri: 'openapi-v1.yaml',
        playgroundUri: 'docs/v1',
    );

    expect($spec->name)->toBe('v1')
        ->and($spec->info)->toBe($info)
        ->and($spec->servers)->toBe([$server])
        ->and($spec->tags)->toBe([$tag])
        ->and($spec->match)->toBe(['prefix' => 'api/v1/*'])
        ->and($spec->outputPath)->toBe('/tmp/openapi-v1.yaml')
        ->and($spec->routeUri)->toBe('openapi-v1.yaml')
        ->and($spec->playgroundUri)->toBe('docs/v1');
});

it('allows null route_uri and playground_uri to opt out of HTTP serving', function (): void {
    $spec = new SpecDefinition(
        name: 'internal',
        info: new OA\Info(['title' => 'X', 'version' => '1.0']),
        servers: [],
        tags: [],
        match: [],
        outputPath: '/tmp/x.yaml',
        routeUri: null,
        playgroundUri: null,
    );

    expect($spec->routeUri)->toBeNull()
        ->and($spec->playgroundUri)->toBeNull();
});
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Unit/Spec/SpecDefinitionTest.php
```

Expected: class-not-found.

- [ ] **Step 3: Create the class**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Spec;

use OpenApi\Annotations as OA;

/**
 * Immutable description of one OpenAPI specification produced by the generator.
 *
 * Built by {@see SpecRegistry} from `config('openapi.specs')` + root config keys.
 * Consumed by {@see \Radiergummi\OpenApi\Core\Generator\OpenApiGenerator},
 * {@see \Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator}, and the HTTP /
 * CLI surfaces.
 *
 * `routeUri` / `playgroundUri` may be `null` to opt out of HTTP serving entirely
 * (config sets the entry to `false` or `null`).
 */
final readonly class SpecDefinition
{
    /**
     * @param list<OA\Server>      $servers
     * @param list<OA\Tag>         $tags
     * @param array<string, mixed> $match Raw match config (prefix/middleware/namespace).
     */
    public function __construct(
        public string $name,
        public OA\Info $info,
        public array $servers,
        public array $tags,
        public array $match,
        public string $outputPath,
        public ?string $routeUri,
        public ?string $playgroundUri,
    ) {}

    public function servesOverHttp(): bool
    {
        return $this->routeUri !== null;
    }
}
```

- [ ] **Step 4: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Spec/SpecDefinitionTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add src/Core/Spec/SpecDefinition.php tests/Unit/Spec/SpecDefinitionTest.php
git commit -m "feat(spec): add SpecDefinition value object"
```

---

### Task A3: `SpecMatcher` — config-driven matching

Pure evaluator for the three `match` config keys (`prefix`, `middleware`, `namespace`). No Laravel container access. Operates on a route URI + middleware list + controller FQCN.

**Files:**
- Create: `src/Core/Spec/SpecMatcher.php`
- Test: `tests/Unit/Spec/SpecMatcherTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Spec;

use Radiergummi\OpenApi\Core\Spec\SpecMatcher;

beforeEach(function (): void {
    $this->matcher = new SpecMatcher();
});

it('matches empty / missing config — matches everything', function (): void {
    expect($this->matcher->matches(uri: 'api/anything', middleware: [], controller: null, match: []))->toBeTrue();
});

it('matches a single prefix as fnmatch glob', function (): void {
    $match = ['prefix' => 'api/v1/*'];

    expect($this->matcher->matches('api/v1/flights', [], null, $match))->toBeTrue()
        ->and($this->matcher->matches('api/v2/flights', [], null, $match))->toBeFalse();
});

it('matches list of prefixes with OR semantics', function (): void {
    $match = ['prefix' => ['api/v1/*', 'api/v1b/*']];

    expect($this->matcher->matches('api/v1b/x', [], null, $match))->toBeTrue();
});

it('matches middleware literally or by prefix-before-colon', function (): void {
    $match = ['middleware' => 'auth'];

    expect($this->matcher->matches('x', ['auth:api'], null, $match))->toBeTrue()
        ->and($this->matcher->matches('x', ['auth'], null, $match))->toBeTrue()
        ->and($this->matcher->matches('x', ['throttle'], null, $match))->toBeFalse();
});

it('matches a middleware literal with its own colon-suffix', function (): void {
    $match = ['middleware' => 'auth:partner'];

    expect($this->matcher->matches('x', ['auth:partner'], null, $match))->toBeTrue()
        ->and($this->matcher->matches('x', ['auth:api'], null, $match))->toBeFalse();
});

it('matches namespace prefix on controller FQCN', function (): void {
    $match = ['namespace' => 'App\\Http\\Controllers\\V1\\'];

    expect($this->matcher->matches('x', [], 'App\\Http\\Controllers\\V1\\FlightController', $match))->toBeTrue()
        ->and($this->matcher->matches('x', [], 'App\\Http\\Controllers\\V2\\FlightController', $match))->toBeFalse()
        ->and($this->matcher->matches('x', [], null, $match))->toBeFalse();
});

it('ANDs the three keys — every present key must match', function (): void {
    $match = [
        'prefix'     => 'api/v1/*',
        'middleware' => 'auth:partner',
    ];

    expect($this->matcher->matches('api/v1/flights', ['auth:partner'], null, $match))->toBeTrue()
        ->and($this->matcher->matches('api/v1/flights', ['auth:api'], null, $match))->toBeFalse()
        ->and($this->matcher->matches('api/v2/flights', ['auth:partner'], null, $match))->toBeFalse();
});

it('ignores unknown keys', function (): void {
    $match = ['unknown' => 'whatever', 'prefix' => 'api/v1/*'];

    expect($this->matcher->matches('api/v1/x', [], null, $match))->toBeTrue();
});
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Unit/Spec/SpecMatcherTest.php
```

Expected: class-not-found.

- [ ] **Step 3: Create the matcher**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Spec;

use function array_any;
use function explode;
use function fnmatch;
use function is_array;
use function str_contains;
use function str_starts_with;

/**
 * Evaluates a {@see SpecDefinition::$match} config block against a single route.
 *
 * Pure: no Laravel container access, no reflection. Stateless. Three supported keys:
 *
 * - `prefix`     — string|string[]: URI glob(s); matches if any matches via {@see fnmatch()}.
 * - `middleware` — string|string[]: middleware token(s); matches if any of the route's
 *                  middleware entries equals the token OR shares the token's pre-`:` prefix
 *                  (so `'auth'` matches `'auth:api'`).
 * - `namespace`  — string|string[]: controller FQCN prefix(es); matches if the controller's
 *                  class name starts with any prefix. Closure routes (controller === null)
 *                  never match a namespace constraint.
 *
 * AND across the three keys (every present key must match); OR within a single key's array.
 * An empty/missing match block matches everything.
 */
final readonly class SpecMatcher
{
    /**
     * @param list<string>         $middleware Resolved middleware tokens for the route.
     * @param array<string, mixed> $match
     */
    public function matches(
        string $uri,
        array $middleware,
        ?string $controller,
        array $match,
    ): bool {
        if ($match === []) {
            return true;
        }

        if (isset($match['prefix']) && !$this->matchesPrefix($uri, $match['prefix'])) {
            return false;
        }

        if (isset($match['middleware']) && !$this->matchesMiddleware($middleware, $match['middleware'])) {
            return false;
        }

        if (isset($match['namespace']) && !$this->matchesNamespace($controller, $match['namespace'])) {
            return false;
        }

        return true;
    }

    private function matchesPrefix(string $uri, mixed $patterns): bool
    {
        $list = is_array($patterns) ? $patterns : [$patterns];

        return array_any($list, static fn(string $pattern): bool => fnmatch($pattern, $uri));
    }

    /**
     * @param list<string> $middleware
     */
    private function matchesMiddleware(array $middleware, mixed $tokens): bool
    {
        $list = is_array($tokens) ? $tokens : [$tokens];

        foreach ($list as $token) {
            foreach ($middleware as $entry) {
                if ($entry === $token) {
                    return true;
                }

                // If the token has no colon, allow it to match any colon-suffixed variant.
                // 'auth' matches 'auth:api', 'auth:partner'. But 'auth:partner' must match exactly.
                if (!str_contains($token, ':') && str_starts_with($entry, $token . ':')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchesNamespace(?string $controller, mixed $prefixes): bool
    {
        if ($controller === null) {
            return false;
        }

        $list = is_array($prefixes) ? $prefixes : [$prefixes];

        return array_any($list, static fn(string $prefix): bool => str_starts_with($controller, $prefix));
    }
}
```

- [ ] **Step 4: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Spec/SpecMatcherTest.php
```

Expected: every test PASS.

- [ ] **Step 5: Commit**

```
git add src/Core/Spec/SpecMatcher.php tests/Unit/Spec/SpecMatcherTest.php
git commit -m "feat(spec): add SpecMatcher (prefix/middleware/namespace evaluator)"
```

---

### Task A4: `SpecRegistry` — parses config into `SpecDefinition` list

Reads root config + `'specs'` to produce one `SpecDefinition` per spec. Resolves defaults for `output_path`, `route_uri`, `playground_uri`. Handles `false`/`null` opt-out. Deep-merges `info` over root; replaces `servers` and `tags` wholesale.

**Files:**
- Create: `src/Core/Spec/SpecRegistry.php`
- Test: `tests/Unit/Spec/SpecRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Spec;

use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

function makeRegistry(array $rootConfig = [], ?array $specs = null): SpecRegistry
{
    return new SpecRegistry(
        rootInfo: $rootConfig['info'] ?? ['title' => 'API', 'version' => '1.0'],
        rootServers: $rootConfig['servers'] ?? [],
        rootTags: $rootConfig['tags'] ?? [],
        rootOutputPath: $rootConfig['output_path'] ?? '/storage/openapi.yaml',
        rootRouteUri: $rootConfig['routes']['spec']['uri'] ?? 'openapi.yaml',
        rootPlaygroundUri: $rootConfig['routes']['playground']['uri'] ?? 'docs',
        specs: $specs,
        storagePath: '/storage',
    );
}

it('with no `specs` config, returns one default spec from root keys', function (): void {
    $reg = makeRegistry();
    $all = $reg->all();

    expect($all)->toHaveCount(1)
        ->and($all[0]->name)->toBe('default')
        ->and($all[0]->outputPath)->toBe('/storage/openapi.yaml')
        ->and($all[0]->routeUri)->toBe('openapi.yaml')
        ->and($all[0]->playgroundUri)->toBe('docs')
        ->and($all[0]->match)->toBe([]);
});

it('materialises named specs with default output_path / route_uri / playground_uri', function (): void {
    $reg = makeRegistry(specs: [
        'v1' => ['match' => ['prefix' => 'api/v1/*']],
    ]);

    $v1 = $reg->get('v1');

    expect($v1->name)->toBe('v1')
        ->and($v1->outputPath)->toBe('/storage/openapi-v1.yaml')
        ->and($v1->routeUri)->toBe('openapi-v1.yaml')
        ->and($v1->playgroundUri)->toBe('docs/v1')
        ->and($v1->match)->toBe(['prefix' => 'api/v1/*']);
});

it('honours explicit overrides for output_path / route_uri / playground_uri', function (): void {
    $reg = makeRegistry(specs: [
        'v1' => [
            'output_path'    => '/custom/path.yaml',
            'route_uri'      => 'openapi-versioned.yaml',
            'playground_uri' => 'reference/v1',
        ],
    ]);

    $v1 = $reg->get('v1');

    expect($v1->outputPath)->toBe('/custom/path.yaml')
        ->and($v1->routeUri)->toBe('openapi-versioned.yaml')
        ->and($v1->playgroundUri)->toBe('reference/v1');
});

it('treats false or null route_uri / playground_uri as opt-out (becomes null on the definition)', function (): void {
    $reg = makeRegistry(specs: [
        'internal' => [
            'route_uri'      => false,
            'playground_uri' => null,
        ],
    ]);

    $spec = $reg->get('internal');

    expect($spec->routeUri)->toBeNull()
        ->and($spec->playgroundUri)->toBeNull()
        ->and($spec->servesOverHttp())->toBeFalse();
});

it('deep-merges per-spec `info` over root info', function (): void {
    $reg = makeRegistry(
        rootConfig: ['info' => ['title' => 'API', 'version' => '1.0', 'description' => 'Root.']],
        specs: ['v1' => ['info' => ['version' => '1.x']]],
    );

    $info = $reg->get('v1')->info;

    expect($info->title)->toBe('API')
        ->and($info->version)->toBe('1.x')
        ->and($info->description)->toBe('Root.');
});

it('replaces servers wholesale per-spec', function (): void {
    $reg = makeRegistry(
        rootConfig: ['servers' => [['url' => 'https://root.example.com']]],
        specs: ['v1' => ['servers' => [['url' => 'https://v1.example.com']]]],
    );

    $servers = $reg->get('v1')->servers;

    expect($servers)->toHaveCount(1)
        ->and($servers[0]->url)->toBe('https://v1.example.com');
});

it('reads an explicit `specs.default` entry as overrides on the implicit default', function (): void {
    $reg = makeRegistry(specs: [
        'default' => ['match' => ['prefix' => 'api/*']],
        'v1'      => ['match' => ['prefix' => 'api/v1/*']],
    ]);

    $default = $reg->default();

    expect($default->match)->toBe(['prefix' => 'api/*'])
        ->and($default->outputPath)->toBe('/storage/openapi.yaml');     // root key preserved
});

it('throws when getting an unknown spec by name', function (): void {
    $reg = makeRegistry();
    $reg->get('missing');
})->throws(\InvalidArgumentException::class, "Spec 'missing' is not defined");
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Unit/Spec/SpecRegistryTest.php
```

Expected: class-not-found.

- [ ] **Step 3: Create `SpecRegistry`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Spec;

use InvalidArgumentException;
use OpenApi\Annotations as OA;

use function array_key_exists;
use function array_merge;
use function array_map;
use function array_values;
use function is_array;
use function sprintf;

/**
 * Materialises {@see SpecDefinition} value objects from the application's
 * `config('openapi.*')` keys plus the optional `config('openapi.specs')` map.
 *
 * The default spec is implicit: it is always present and built from root keys
 * (`info`, `servers`, `tags`, `output_path`, `routes.spec.uri`, `routes.playground.uri`).
 * An explicit `'default'` entry in `'specs'` may add a `match` config or override any of
 * the per-spec fields; missing keys fall back to root.
 *
 * Resolved lazily on first call and memoised for the lifetime of the registry
 * instance (which is bound `scoped` by the service provider — one instance per
 * generation run / per request).
 */
final class SpecRegistry
{
    /** @var array<string, SpecDefinition>|null */
    private ?array $cache = null;

    /**
     * @param array<string, mixed>             $rootInfo
     * @param list<array<string, mixed>>       $rootServers
     * @param array<string, mixed>             $rootTags
     * @param array<string, array<string,mixed>>|null $specs
     */
    public function __construct(
        private array $rootInfo,
        private array $rootServers,
        private array $rootTags,
        private string $rootOutputPath,
        private string $rootRouteUri,
        private string $rootPlaygroundUri,
        private ?array $specs,
        private string $storagePath,
    ) {}

    /** @return list<SpecDefinition> */
    public function all(): array
    {
        return array_values($this->resolve());
    }

    public function get(string $name): SpecDefinition
    {
        $map = $this->resolve();

        if (!array_key_exists($name, $map)) {
            throw new InvalidArgumentException(sprintf("Spec '%s' is not defined.", $name));
        }

        return $map[$name];
    }

    public function default(): SpecDefinition
    {
        return $this->get('default');
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->resolve());
    }

    /** @return array<string, SpecDefinition> */
    private function resolve(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $defaultOverrides = $this->specs['default'] ?? [];
        $defs = ['default' => $this->buildSpec('default', $defaultOverrides)];

        foreach (($this->specs ?? []) as $name => $overrides) {
            if ($name === 'default') {
                continue;
            }

            $defs[(string) $name] = $this->buildSpec((string) $name, (array) $overrides);
        }

        return $this->cache = $defs;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildSpec(string $name, array $overrides): SpecDefinition
    {
        $infoArray = is_array($overrides['info'] ?? null)
            ? array_merge($this->rootInfo, $overrides['info'])
            : $this->rootInfo;

        $serversArray = is_array($overrides['servers'] ?? null) ? $overrides['servers'] : $this->rootServers;
        $tagsArray = is_array($overrides['tags'] ?? null) ? $overrides['tags'] : $this->rootTags;

        $servers = array_values(array_map(
            static fn(array $s): OA\Server => new OA\Server($s),
            $serversArray,
        ));

        $tags = [];
        foreach ($tagsArray as $tagName => $cfg) {
            $cfg = is_array($cfg) ? $cfg : [];
            $tags[] = new OA\Tag(['name' => (string) $tagName] + $cfg);
        }

        $match = is_array($overrides['match'] ?? null) ? $overrides['match'] : [];

        $outputPath = $this->resolveOutputPath($name, $overrides);
        $routeUri = $this->resolveOptional($overrides, 'route_uri', $name === 'default'
            ? $this->rootRouteUri
            : sprintf('openapi-%s.yaml', $name));
        $playgroundUri = $this->resolveOptional($overrides, 'playground_uri', $name === 'default'
            ? $this->rootPlaygroundUri
            : sprintf('docs/%s', $name));

        return new SpecDefinition(
            name: $name,
            info: new OA\Info($infoArray),
            servers: $servers,
            tags: $tags,
            match: $match,
            outputPath: $outputPath,
            routeUri: $routeUri,
            playgroundUri: $playgroundUri,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resolveOutputPath(string $name, array $overrides): string
    {
        if (array_key_exists('output_path', $overrides) && is_string($overrides['output_path'])) {
            return $overrides['output_path'];
        }

        return $name === 'default'
            ? $this->rootOutputPath
            : rtrim($this->storagePath, '/') . sprintf('/openapi-%s.yaml', $name);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resolveOptional(array $overrides, string $key, string $default): ?string
    {
        if (!array_key_exists($key, $overrides)) {
            return $default;
        }

        $value = $overrides[$key];

        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }
}
```

- [ ] **Step 4: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Spec/SpecRegistryTest.php
```

Expected: every test PASS.

- [ ] **Step 5: Commit**

```
git add src/Core/Spec/SpecRegistry.php tests/Unit/Spec/SpecRegistryTest.php
git commit -m "feat(spec): add SpecRegistry (config → SpecDefinition[])"
```

---

### Task A5: `#[Spec]` attribute

Repeatable, multi-target, normalises `null`/string/list arg into `list<string>`.

**Files:**
- Create: `src/Core/Attributes/Spec.php`
- Test: `tests/Unit/Attributes/SpecTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Attributes;

use Radiergummi\OpenApi\Core\Attributes\Spec;

it('normalises null / no arg to ["default"]', function (): void {
    expect(new Spec()->names)->toBe(['default'])
        ->and(new Spec(null)->names)->toBe(['default']);
});

it('wraps a single string in a list', function (): void {
    expect(new Spec('v1')->names)->toBe(['v1']);
});

it('preserves a list as-is', function (): void {
    expect(new Spec(['v1', 'v2'])->names)->toBe(['v1', 'v2']);
});
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Unit/Attributes/SpecTest.php
```

Expected: class-not-found.

- [ ] **Step 3: Create the attribute**

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

use function array_values;
use function is_string;

/**
 * Pin a route to one or more named specs explicitly.
 *
 * When present, the spec partition's `match` config is ignored for this route — `#[Spec]` is
 * the definitive declaration. Global filters and `#[Hide]` / `#[Expose]` still apply.
 *
 * Forms:
 *   #[Spec]                 // ['default']  — opt out of named specs
 *   #[Spec('v1')]           // ['v1']
 *   #[Spec(['v1', 'v2'])]   // ['v1', 'v2']
 *
 * Repeatable: stacking `#[Spec('v1'), Spec('v2')]` unions to `['v1', 'v2']`. Method-level
 * attributes replace class-level attributes when the method carries any `#[Spec]`.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Spec
{
    /** @var list<string> */
    public array $names;

    public function __construct(string|array|null $name = null)
    {
        $this->names = match (true) {
            $name === null    => ['default'],
            is_string($name)  => [$name],
            default           => array_values($name),
        };
    }
}
```

- [ ] **Step 4: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Attributes/SpecTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add src/Core/Attributes/Spec.php tests/Unit/Attributes/SpecTest.php
git commit -m "feat(attributes): add #[Spec] for explicit per-route spec assignment"
```

---

### Task A6: `SpecResolver` — extracts the effective `#[Spec]` list from an `ActionDescriptor`

Implements the class/method resolution rule: method-level `#[Spec]` attributes (union) replace class-level if any are present; otherwise class-level union; otherwise `null` (= no attribute, defer to filter).

**Files:**
- Create: `src/Core/Spec/SpecResolver.php`
- Test: `tests/Unit/Spec/SpecResolverTest.php`

- [ ] **Step 1: Write the failing test**

Test fixtures are inline anonymous classes — no need for separate fixture files. Use real reflection.

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Spec;

use Radiergummi\OpenApi\Core\Attributes\Spec;
use Radiergummi\OpenApi\Core\Spec\SpecResolver;
use ReflectionClass;
use ReflectionMethod;

beforeEach(function (): void {
    $this->resolver = new SpecResolver();
});

// Fixtures — declared at module level so reflection sees them.

#[Spec('v1')]
final class FxClassOnly
{
    public function handle(): void {}
}

final class FxMethodOnly
{
    #[Spec('v2')]
    public function handle(): void {}
}

#[Spec('v1')]
final class FxBoth
{
    #[Spec('v2')]
    public function handle(): void {}
}

final class FxRepeatable
{
    #[Spec('v1')]
    #[Spec('v2')]
    public function handle(): void {}
}

final class FxNone
{
    public function handle(): void {}
}

it('returns class-level names when only the class carries #[Spec]', function (): void {
    $method = new ReflectionMethod(FxClassOnly::class, 'handle');
    $class = new ReflectionClass(FxClassOnly::class);

    expect($this->resolver->resolve($class, $method))->toBe(['v1']);
});

it('returns method-level names when only the method carries #[Spec]', function (): void {
    $method = new ReflectionMethod(FxMethodOnly::class, 'handle');
    $class = new ReflectionClass(FxMethodOnly::class);

    expect($this->resolver->resolve($class, $method))->toBe(['v2']);
});

it('method wins over class when both carry #[Spec]', function (): void {
    $method = new ReflectionMethod(FxBoth::class, 'handle');
    $class = new ReflectionClass(FxBoth::class);

    expect($this->resolver->resolve($class, $method))->toBe(['v2']);
});

it('unions repeated #[Spec] attributes on the same target', function (): void {
    $method = new ReflectionMethod(FxRepeatable::class, 'handle');
    $class = new ReflectionClass(FxRepeatable::class);

    expect($this->resolver->resolve($class, $method))->toBe(['v1', 'v2']);
});

it('returns null when neither class nor method carries #[Spec]', function (): void {
    $method = new ReflectionMethod(FxNone::class, 'handle');
    $class = new ReflectionClass(FxNone::class);

    expect($this->resolver->resolve($class, $method))->toBeNull();
});

it('handles a null class reflector (closure routes)', function (): void {
    $method = new ReflectionMethod(FxClassOnly::class, 'handle');

    expect($this->resolver->resolve(null, $method))->toBe(['v1']);  // method's class wins via $method->getDeclaringClass()
});

it('handles a null method reflector', function (): void {
    $class = new ReflectionClass(FxClassOnly::class);

    expect($this->resolver->resolve($class, null))->toBe(['v1']);
});

it('returns null when both reflectors are null', function (): void {
    expect($this->resolver->resolve(null, null))->toBeNull();
});
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Unit/Spec/SpecResolverTest.php
```

Expected: class-not-found.

- [ ] **Step 3: Create `SpecResolver`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Spec;

use Radiergummi\OpenApi\Core\Attributes\Spec;
use ReflectionClass;
use ReflectionMethod;

use function array_merge;
use function array_unique;
use function array_values;

/**
 * Resolves the effective list of spec names declared by `#[Spec]` attributes on a route.
 *
 * Returns:
 * - `null` when neither the controller class nor the action method carries `#[Spec]`
 *   (i.e. the route is subject to filter-based assignment).
 * - `list<string>` (possibly empty) — the union of all method-level `#[Spec]` attributes if
 *   the method carries any, otherwise the union of all class-level attributes.
 *
 * Method presence shadows the class: a method carrying `#[Spec]` ignores the class's
 * `#[Spec]` entirely, even if the union would differ.
 */
final readonly class SpecResolver
{
    /**
     * @return list<string>|null
     */
    public function resolve(?ReflectionClass $class, ?ReflectionMethod $method): ?array
    {
        $methodNames = $method !== null ? $this->collect($method) : null;

        if ($methodNames !== null) {
            return $methodNames;
        }

        // Fall back to the method's declaring class if no explicit class was passed.
        $effectiveClass = $class ?? $method?->getDeclaringClass();

        return $effectiveClass !== null ? $this->collect($effectiveClass) : null;
    }

    /**
     * @return list<string>|null  null when no attribute present
     */
    private function collect(ReflectionClass|ReflectionMethod $target): ?array
    {
        $attributes = $target->getAttributes(Spec::class);

        if ($attributes === []) {
            return null;
        }

        $names = [];
        foreach ($attributes as $attr) {
            /** @var Spec $instance */
            $instance = $attr->newInstance();
            $names = array_merge($names, $instance->names);
        }

        return array_values(array_unique($names));
    }
}
```

- [ ] **Step 4: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Spec/SpecResolverTest.php
```

Expected: every test PASS.

- [ ] **Step 5: Commit**

```
git add src/Core/Spec/SpecResolver.php tests/Unit/Spec/SpecResolverTest.php
git commit -m "feat(spec): add SpecResolver (extracts effective #[Spec] from reflectors)"
```

---

## Phase B — Inclusion engine

### Task B1: `TraceEntry` + `InclusionDecision` value objects

Pure data carriers. Used by the evaluator to record the decision and by `openapi:why` / `--explain` to render it.

**Files:**
- Create: `src/Core/Inclusion/TraceEntry.php`
- Create: `src/Core/Inclusion/InclusionDecision.php`
- Test: `tests/Unit/Inclusion/InclusionEvaluatorTest.php` (will get populated by next task; declare here to keep file paths stable)

- [ ] **Step 1: Create `TraceEntry`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Inclusion;

/**
 * One line in an inclusion-decision trace.
 *
 * Composed by {@see InclusionEvaluator} and rendered by the `openapi:why` command and
 * `openapi:generate --explain`. Pure data — no formatting decisions live here.
 *
 * `stage` identifies the conceptual phase (e.g. `'global-filter'`, `'spec-attribute'`,
 * `'spec-match'`, `'visibility'`); `name` identifies the specific thing under that stage
 * (the filter class name, the matched key, etc.); `passed` is the boolean outcome; `reason`
 * is a one-line human-readable explanation.
 */
final readonly class TraceEntry
{
    public function __construct(
        public string $stage,
        public string $name,
        public bool $passed,
        public string $reason,
    ) {}
}
```

- [ ] **Step 2: Create `InclusionDecision`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Inclusion;

/**
 * Result of {@see InclusionEvaluator::decide()} for one (route × spec) pair.
 *
 * `included` is the final yes/no the generator reads. `trace` is the structured list of
 * checks that produced it; `summary` is a one-line text reason suitable for `--explain`
 * output (the leading verb explaining the outcome, e.g. "global filter SkipNovaRoutes",
 * "matched by prefix", "hidden in environment local").
 */
final readonly class InclusionDecision
{
    /**
     * @param list<TraceEntry> $trace
     */
    public function __construct(
        public bool $included,
        public array $trace,
        public string $summary,
    ) {}
}
```

- [ ] **Step 3: Create the empty test file with a stub assertion so the next task's TDD can extend it**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Inclusion;

use Radiergummi\OpenApi\Core\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Core\Inclusion\TraceEntry;

it('TraceEntry holds stage, name, passed, reason', function (): void {
    $entry = new TraceEntry('global-filter', 'SkipNovaRoutes', true, 'not a Nova route');

    expect($entry->stage)->toBe('global-filter')
        ->and($entry->name)->toBe('SkipNovaRoutes')
        ->and($entry->passed)->toBeTrue()
        ->and($entry->reason)->toBe('not a Nova route');
});

it('InclusionDecision holds included, trace, summary', function (): void {
    $decision = new InclusionDecision(true, [], 'matches default spec');

    expect($decision->included)->toBeTrue()
        ->and($decision->trace)->toBe([])
        ->and($decision->summary)->toBe('matches default spec');
});
```

- [ ] **Step 4: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Inclusion/InclusionEvaluatorTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add src/Core/Inclusion/TraceEntry.php src/Core/Inclusion/InclusionDecision.php tests/Unit/Inclusion/InclusionEvaluatorTest.php
git commit -m "feat(inclusion): add TraceEntry and InclusionDecision value objects"
```

---

### Task B2: `InclusionEvaluator`

The single decision engine. Implements the four-rule algorithm. Used by the generator, by `openapi:why`, by `--explain`, and by the orchestrator.

**Files:**
- Create: `src/Core/Inclusion/InclusionEvaluator.php`
- Modify: `tests/Unit/Inclusion/InclusionEvaluatorTest.php`

- [ ] **Step 1: Add failing tests covering the four-rule decision**

Append to `tests/Unit/Inclusion/InclusionEvaluatorTest.php`:

```php
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecMatcher;
use Radiergummi\OpenApi\Core\Spec\SpecResolver;
use Radiergummi\OpenApi\Core\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;
use OpenApi\Annotations as OA;

/**
 * Builds a minimal ActionDescriptor for testing. The evaluator only reads route + reflectors;
 * other descriptor fields can be safely null/empty. Implementations of this helper should
 * mirror the actual ActionDescriptor constructor — check current shape before authoring.
 */
function makeDescriptor(
    string $uri,
    array $middleware = [],
    ?ReflectionClass $controller = null,
    ?ReflectionMethod $action = null,
): ActionDescriptor {
    $route = (new Route(['GET'], $uri, fn() => null))->middleware($middleware);

    return new ActionDescriptor(
        route: $route,
        controller: $controller,
        actionReflector: $action,
        // …fill the remaining ActionDescriptor constructor params with sensible defaults;
        //    consult the current class signature before writing this helper.
    );
}

function makeSpec(string $name, array $match = []): SpecDefinition
{
    return new SpecDefinition(
        name: $name,
        info: new OA\Info(['title' => $name, 'version' => '1.0']),
        servers: [],
        tags: [],
        match: $match,
        outputPath: "/tmp/{$name}.yaml",
        routeUri: null,
        playgroundUri: null,
    );
}

function makeEvaluator(array $globalFilters = []): InclusionEvaluator
{
    return new InclusionEvaluator(
        globalFilters: $globalFilters,
        matcher: new SpecMatcher(),
        specResolver: new SpecResolver(),
        visibility: new VisibilityResolver(VisibilityMode::Public),
    );
}

it('includes a route in default spec when nothing opposes', function (): void {
    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('default');

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeTrue();
});

it('excludes a route when any global RouteFilter returns shouldSkip=true', function (): void {
    $filter = new class implements RouteFilter {
        public function shouldSkip(Route $route): bool { return true; }
    };

    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);

    $decision = makeEvaluator([$filter])->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeFalse()
        ->and($decision->summary)->toContain('global filter');
});

it('includes a route in a named spec when the spec match config matches', function (): void {
    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeTrue();
});

it('excludes a route from a named spec when match config does not match', function (): void {
    $descriptor = makeDescriptor('api/v2/flights');
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeFalse()
        ->and($decision->summary)->toContain('match');
});

it('includes a route with #[Spec(v1)] in spec v1 regardless of match config', function (): void {
    // Use a class with #[Spec('v1')]; reuse Spec attribute fixture from Phase A6.
    $class = new ReflectionClass(\Radiergummi\OpenApi\Tests\Unit\Spec\FxClassOnly::class);
    $method = $class->getMethod('handle');

    $descriptor = makeDescriptor('api/legacy/x', controller: $class, action: $method);
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);  // would NOT match by prefix

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeTrue();
});

it('excludes a route with #[Spec(v1)] from spec v2 even if v2 match matches', function (): void {
    $class = new ReflectionClass(\Radiergummi\OpenApi\Tests\Unit\Spec\FxClassOnly::class);
    $method = $class->getMethod('handle');

    $descriptor = makeDescriptor('api/v2/foo', controller: $class, action: $method);
    $spec = makeSpec('v2', ['prefix' => 'api/v2/*']);

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    expect($decision->included)->toBeFalse();
});

it('excludes a route when #[Hide] applies in the current environment', function (): void {
    $hidden = new class { #[Hide] public function handle(): void {} };
    $class = new ReflectionClass($hidden);
    $method = $class->getMethod('handle');

    $descriptor = makeDescriptor('api/v1/x', controller: $class, action: $method);
    $spec = makeSpec('default');

    $decision = makeEvaluator()->decide($descriptor, $spec, 'production');

    expect($decision->included)->toBeFalse()
        ->and($decision->summary)->toContain('hidden');
});

it('produces a trace with one entry per check', function (): void {
    $descriptor = makeDescriptor('api/v1/flights');
    $spec = makeSpec('v1', ['prefix' => 'api/v1/*']);

    $decision = makeEvaluator()->decide($descriptor, $spec, 'local');

    $stages = array_column(array_map(fn($t) => (array) $t, $decision->trace), 'stage');
    expect($stages)->toContain('spec-match')
        ->and($stages)->toContain('visibility');
});
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Unit/Inclusion/InclusionEvaluatorTest.php
```

Expected: class-not-found / undefined class.

- [ ] **Step 3: Create `InclusionEvaluator`**

Inspect `ActionDescriptor` constructor before writing the evaluator. The descriptor exposes `route`, `controller`, `actionReflector` (plus extras). Reading those is all the evaluator needs.

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Inclusion;

use Radiergummi\OpenApi\Core\Attributes\Expose;
use Radiergummi\OpenApi\Core\Attributes\Hide;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecMatcher;
use Radiergummi\OpenApi\Core\Spec\SpecResolver;
use Radiergummi\OpenApi\Core\Visibility\VisibilityResolver;

use function in_array;

/**
 * Single source of truth for the four-rule decision "does route R belong in spec X?".
 *
 * Used by the generator (reads `->included`), by `openapi:why` and `openapi:generate --explain`
 * (read `->trace`), and by the pre-build lint rules (resolve the same decision from the
 * descriptor list without building the spec).
 *
 * The four rules, evaluated in order:
 *
 * 1. Global filters (`config('openapi.filters')`): if any `RouteFilter::shouldSkip()` returns
 *    true, the route is excluded from every spec.
 * 2. Spec membership: if `#[Spec]` is present, the route is in spec X iff X is in the list;
 *    otherwise the spec's `match` config must match the route.
 * 3. Visibility: the route is excluded when `#[Hide]` applies in the current environment.
 * 4. Visibility default + `#[Expose]`: included when `visibility.default = 'public'` OR an
 *    `#[Expose]` resolves true for the current environment.
 */
final readonly class InclusionEvaluator
{
    /**
     * @param list<RouteFilter> $globalFilters
     */
    public function __construct(
        private array $globalFilters,
        private SpecMatcher $matcher,
        private SpecResolver $specResolver,
        private VisibilityResolver $visibility,
    ) {}

    public function decide(
        ActionDescriptor $descriptor,
        SpecDefinition $spec,
        string $environment,
    ): InclusionDecision {
        $trace = [];

        // 1. Global exclusion
        foreach ($this->globalFilters as $filter) {
            $skip = $filter->shouldSkip($descriptor->route);
            $name = $filter::class;
            $trace[] = new TraceEntry(
                stage: 'global-filter',
                name: $name,
                passed: !$skip,
                reason: $skip ? 'shouldSkip = true' : 'shouldSkip = false',
            );

            if ($skip) {
                return new InclusionDecision(false, $trace, "excluded by global filter {$name}");
            }
        }

        // 2. Spec membership
        $explicit = $this->specResolver->resolve($descriptor->controller, $descriptor->actionReflector);

        if ($explicit !== null) {
            $isMember = in_array($spec->name, $explicit, true);
            $trace[] = new TraceEntry(
                stage: 'spec-attribute',
                name: '#[Spec]',
                passed: $isMember,
                reason: $isMember
                    ? "attribute lists '{$spec->name}'"
                    : "attribute lists [" . implode(',', $explicit) . "]; '{$spec->name}' not present",
            );

            if (!$isMember) {
                return new InclusionDecision(false, $trace, "not in #[Spec] list for {$spec->name}");
            }
        } else {
            $controllerFqcn = $descriptor->controller?->getName();
            $middleware = $this->extractMiddleware($descriptor);

            $matched = $this->matcher->matches(
                uri: $descriptor->route->uri(),
                middleware: $middleware,
                controller: $controllerFqcn,
                match: $spec->match,
            );

            $trace[] = new TraceEntry(
                stage: 'spec-match',
                name: $spec->match === [] ? '(no match config)' : implode(',', array_keys($spec->match)),
                passed: $matched,
                reason: $matched ? 'match config matched' : 'match config did not match',
            );

            if (!$matched) {
                return new InclusionDecision(false, $trace, "match config did not match for {$spec->name}");
            }
        }

        // 3. + 4. Visibility (Hide / Expose / default)
        $hides = $this->collectAttributes($descriptor, Hide::class);
        $exposes = $this->collectAttributes($descriptor, Expose::class);
        $visible = $this->visibility->isVisible($hides, $exposes, $environment);

        $trace[] = new TraceEntry(
            stage: 'visibility',
            name: $environment,
            passed: $visible,
            reason: $visible ? 'visible in environment' : 'hidden in environment',
        );

        return new InclusionDecision(
            included: $visible,
            trace: $trace,
            summary: $visible ? "included in {$spec->name}" : "hidden in environment {$environment}",
        );
    }

    /**
     * @return list<string>
     */
    private function extractMiddleware(ActionDescriptor $descriptor): array
    {
        $middleware = $descriptor->route->middleware();
        return array_values(array_map(static fn($m): string => (string) $m, $middleware));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private function collectAttributes(ActionDescriptor $descriptor, string $class): array
    {
        $instances = [];

        if ($descriptor->actionReflector !== null) {
            foreach ($descriptor->actionReflector->getAttributes($class) as $attr) {
                $instances[] = $attr->newInstance();
            }
        }

        if ($descriptor->controller !== null) {
            foreach ($descriptor->controller->getAttributes($class) as $attr) {
                $instances[] = $attr->newInstance();
            }
        }

        return $instances;
    }
}
```

- [ ] **Step 4: Run the new tests, confirm pass**

```
vendor/bin/pest tests/Unit/Inclusion/InclusionEvaluatorTest.php
```

Expected: every test PASS. If the `makeDescriptor` helper struggles, read `src/Core/Routing/ActionDescriptor.php` and align the constructor arguments — the helper's job is to produce a real descriptor, no shortcuts.

- [ ] **Step 5: Run the full unit suite**

```
vendor/bin/pest tests/Unit/
```

Expected: green.

- [ ] **Step 6: Commit**

```
git add src/Core/Inclusion/InclusionEvaluator.php tests/Unit/Inclusion/InclusionEvaluatorTest.php
git commit -m "feat(inclusion): add InclusionEvaluator (single source of truth for spec membership)"
```

---

## Phase C — Generator integration

### Task C1: Refactor `OpenApiGenerator::generate()` to take `SpecDefinition`

Replace the parameterless `$filters = []` shape with a `SpecDefinition $spec` first arg. The generator pulls `info`/`servers`/`tags` from `$spec`, and routes routes through the `InclusionEvaluator`.

**Files:**
- Modify: `src/Core/Generator/OpenApiGenerator.php`
- Modify: `tests/Unit/Core/Generator/OpenApiGeneratorTest.php` (existing — adapt to new signature)
- Modify: `tests/Pest.php` (the `generateSpec()` helper)
- Modify: `src/OpenApiServiceProvider.php` (it constructs OpenApiGenerator; the constructor signature changes too — add the evaluator)

- [ ] **Step 1: Adapt `OpenApiGenerator` constructor + `generate()`**

Open `src/Core/Generator/OpenApiGenerator.php`. New constructor signature:

```php
public function __construct(
    private RouteIntrospector $introspector,
    private OperationBuilder $operationBuilder,
    private ComponentSchemaRegistry $schemaRegistry,
    private \Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator $evaluator,
) {}
```

The `VisibilityResolver` member is removed — the evaluator owns visibility now. Update the class's `use` block to drop the now-unused `Visibility\*` imports and add the `Inclusion\*` import.

Change `generate()`:

```php
public function generate(SpecDefinition $spec, string $environment): OA\OpenApi
{
    $previousContext = Generator::$context;
    Generator::$context = new Context(['version' => OA\OpenApi::VERSION_3_1_0]);

    try {
        return $this->assembleDocument($spec, $environment);
    } finally {
        Generator::$context = $previousContext;
    }
}
```

Replace the existing `assembleDocument(array $filters)` body so the route-iteration step calls the evaluator:

```php
private function assembleDocument(SpecDefinition $spec, string $environment): OA\OpenApi
{
    $pathItems = [];
    $webhookItems = [];

    foreach ($this->introspector->discover() as $descriptor) {
        $decision = $this->evaluator->decide($descriptor, $spec, $environment);

        if (!$decision->included) {
            continue;
        }

        $webhookAttr = $this->readWebhookAttribute($descriptor);

        if ($webhookAttr !== null) {
            $name = $webhookAttr->name;
            $webhookItems[$name] ??= new OA\Webhook(['webhook' => $name]);
            $this->attachOperation($webhookItems[$name], $descriptor);
            continue;
        }

        $path = $this->normalisePath($descriptor->route->uri());
        $pathItems[$path] ??= new OA\PathItem(['path' => $path]);
        $this->attachOperation($pathItems[$path], $descriptor);
    }

    $componentSchemas = $this->schemaRegistry->all();
    $componentResponses = $this->schemaRegistry->allResponses();

    $componentsProps = ['securitySchemes' => $this->operationBuilder->buildSecuritySchemes()];

    if ($componentSchemas !== []) {
        $componentsProps['schemas'] = $componentSchemas;
    }
    if ($componentResponses !== []) {
        $componentsProps['responses'] = $componentResponses;
    }

    $documentProps = [
        'openapi'   => '3.1.0',
        'info'      => $spec->info,
        'servers'   => $spec->servers !== [] ? $spec->servers : $this->fallbackServers(),
        'paths'     => array_values($pathItems),
        'components' => new OA\Components($componentsProps),
    ];

    if ($webhookItems !== []) {
        $documentProps['webhooks'] = array_values($webhookItems);
    }

    if ($spec->tags !== []) {
        $documentProps['tags'] = $spec->tags;
    }

    $document = new OA\OpenApi($documentProps);
    OpenApiExtensions::applyDocumentTransformers($document);

    return $document;
}

/** @return list<OA\Server> */
private function fallbackServers(): array
{
    return [new OA\Server(['url' => (string) config('app.url')])];
}
```

Remove the now-unused `buildInfo`, `buildServers`, `buildTags`, `shouldSkip`, `isHidden`, `collectAttributes` methods — they all migrated into `SpecRegistry` / `InclusionEvaluator`. Run static analysis to find any leftovers.

- [ ] **Step 2: Update the `tests/Pest.php` helper**

Replace the existing `generateSpec()` helper:

```php
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

/**
 * Runs the generator against the default spec and returns the YAML parsed to array.
 */
function generateSpec(?string $specName = null, string $environment = 'testing'): array
{
    $registry = app(SpecRegistry::class);
    $spec = $specName === null ? $registry->default() : $registry->get($specName);

    return Yaml::parse(app(OpenApiGenerator::class)->generate($spec, $environment)->toYaml());
}
```

Delete the old `$filters` parameter usage everywhere it appears in tests (a few feature tests call `generateSpec([...])` to inject custom filters — those filters now belong on the SpecDefinition or in a fresh helper).

Search:

```
grep -rn "generateSpec(" tests/
```

Plan: any call passing filters needs to be revisited. For the immediate refactor, accept that those tests will fail; they get fixed in step 5.

- [ ] **Step 3: Update existing `OpenApiGeneratorTest` cases**

```
vendor/bin/pest tests/Unit/Core/Generator/OpenApiGeneratorTest.php
```

Failing tests need to pass `SpecRegistry::default()` + `$environment` to `generate()`. Adapt all calls. If the test was previously asserting against `config('openapi.info')`, it now asserts against the registry's resolved default spec — semantically equivalent.

- [ ] **Step 4: Provide the evaluator in the service provider binding**

In `src/OpenApiServiceProvider.php::registerGenerator()`:

```php
$this->app->scoped(
    OpenApiGenerator::class,
    static fn(Container $app) => new OpenApiGenerator(
        introspector: $app->make(RouteIntrospector::class),
        operationBuilder: $app->make(OperationBuilder::class),
        schemaRegistry: $app->make(ComponentSchemaRegistry::class),
        evaluator: $app->make(\Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator::class),
    ),
);
```

The `InclusionEvaluator` binding is added in Task D1 — this commit may temporarily break the boot. That's fine: D1 follows immediately and adds the binding.

- [ ] **Step 5: Adapt remaining feature tests**

```
vendor/bin/pest tests/Feature/ -v
```

Many will fail because `generateSpec()` formerly accepted callable filters. Search them with `grep -rn "generateSpec(\[" tests/Feature/` — for each, replace the call with the new no-arg or named-spec form. If a test genuinely needs a route filter, register the filter through a temporary config push (`config(['openapi.filters' => [MyFilter::class]])` in the test's `beforeEach`).

- [ ] **Step 6: Confirm Larastan still passes**

```
composer analyse
```

Expected: no errors. Fix any nullable/readonly mismatches introduced by removing `VisibilityResolver` from the constructor.

- [ ] **Step 7: Commit**

```
git add src/Core/Generator/OpenApiGenerator.php src/OpenApiServiceProvider.php tests/Pest.php tests/
git commit -m "refactor(generator): generate(SpecDefinition, env) — route through InclusionEvaluator"
```

(The service-provider compile error remains until D1 binds InclusionEvaluator — the next phase commit closes the loop. The plan deliberately couples this so the generator refactor lands as one focused change.)

---

### Task C2: `OpenApiGenerationOrchestrator`

Wrapper over `OpenApiGenerator` that resets per-run state between specs and exposes `generateOne` / `generateAll`.

**Files:**
- Create: `src/Core/Generator/OpenApiGenerationOrchestrator.php`
- Test: `tests/Unit/Core/Generator/OpenApiGenerationOrchestratorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Generator;

use Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

it('generateAll returns one document per defined spec, keyed by name', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);

    $orchestrator = app(OpenApiGenerationOrchestrator::class);
    $documents = $orchestrator->generateAll('testing');

    expect($documents)->toHaveKeys(['default', 'v1']);
});

it('generateOne returns the document for the named spec', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);

    $orchestrator = app(OpenApiGenerationOrchestrator::class);
    $document = $orchestrator->generateOne('v1', 'testing');

    expect($document)->toBeInstanceOf(\OpenApi\Annotations\OpenApi::class)
        ->and($document->info->title)->toBe('API');
});

it('resets ComponentSchemaRegistry between specs (no leakage)', function (): void {
    // Two specs claim different routes; the schemas referenced by spec A must not appear
    // in spec B. Use a real fixture pair under tests/Fixtures with distinct Data classes.
    // (Implementation note: pick fixtures already used elsewhere in the suite — do not
    // create new ones here; that would balloon scope.)
    // Concrete assertion: component keys in document A and document B are disjoint when
    // the route prefixes are disjoint and only-one-spec routes exist.

    // Steps:
    //   1. Read tests/Fixtures/ to find two Data classes used by routes in disjoint URL prefixes
    //      (e.g. one under api/v1/*, another under api/v2/*).
    //   2. Set config(['openapi.specs' => ['v1' => ['match' => ['prefix' => 'api/v1/*']],
    //                                       'v2' => ['match' => ['prefix' => 'api/v2/*']]]]).
    //   3. Call $orchestrator->generateAll('testing').
    //   4. Assert that the v1 document's components.schemas keys ∩ v2's = ∅
    //      (use array_intersect_key on the schema-name maps).
    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Unit/Core/Generator/OpenApiGenerationOrchestratorTest.php
```

Expected: class-not-found.

- [ ] **Step 3: Create the orchestrator**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

/**
 * Drives multi-spec generation in a single process.
 *
 * Owns the {@see ComponentSchemaRegistry::reset()} / {@see ExampleFileLoader::reset()} calls
 * that prevent per-spec state from leaking across spec runs in the same request. Used by
 * {@see \Radiergummi\OpenApi\Console\GenerateCommand} and {@see \Radiergummi\OpenApi\Http\DocsController}.
 */
final readonly class OpenApiGenerationOrchestrator
{
    public function __construct(
        private OpenApiGenerator $generator,
        private SpecRegistry $registry,
        private ComponentSchemaRegistry $schemaRegistry,
        private ExampleFileLoader $exampleFileLoader,
    ) {}

    public function generateOne(string $name, string $environment): OA\OpenApi
    {
        $this->resetState();

        return $this->generator->generate($this->registry->get($name), $environment);
    }

    /**
     * @return array<string, OA\OpenApi>
     */
    public function generateAll(string $environment): array
    {
        $documents = [];

        foreach ($this->registry->all() as $spec) {
            $this->resetState();
            $documents[$spec->name] = $this->generator->generate($spec, $environment);
        }

        return $documents;
    }

    private function resetState(): void
    {
        $this->schemaRegistry->reset();
        $this->exampleFileLoader->reset();
    }
}
```

- [ ] **Step 4: Bind the orchestrator scoped in `OpenApiServiceProvider::registerGenerator()`**

Add at the bottom of that method:

```php
$this->app->scoped(
    OpenApiGenerationOrchestrator::class,
    static fn(Container $app) => new OpenApiGenerationOrchestrator(
        generator: $app->make(OpenApiGenerator::class),
        registry: $app->make(\Radiergummi\OpenApi\Core\Spec\SpecRegistry::class),
        schemaRegistry: $app->make(ComponentSchemaRegistry::class),
        exampleFileLoader: $app->make(ExampleFileLoader::class),
    ),
);
```

- [ ] **Step 5: Run the test, confirm pass**

```
vendor/bin/pest tests/Unit/Core/Generator/OpenApiGenerationOrchestratorTest.php
```

Expected: PASS (the `todo` test remains pending).

- [ ] **Step 6: Commit**

```
git add src/Core/Generator/OpenApiGenerationOrchestrator.php src/OpenApiServiceProvider.php tests/Unit/Core/Generator/OpenApiGenerationOrchestratorTest.php
git commit -m "feat(generator): add OpenApiGenerationOrchestrator with per-spec state reset"
```

---

## Phase D — Service wiring

### Task D1: Register `SpecRegistry` + `InclusionEvaluator` in the service provider

Closes the loop left open in C1. Both are `scoped`.

**Files:**
- Modify: `src/OpenApiServiceProvider.php`

- [ ] **Step 1: Add a `registerSpec()` method called from `register()`**

Add a call near the top of `register()`:

```php
$this->registerSpec();
```

Then add the method:

```php
private function registerSpec(): void
{
    $this->app->scoped(
        \Radiergummi\OpenApi\Core\Spec\SpecMatcher::class,
        static fn() => new \Radiergummi\OpenApi\Core\Spec\SpecMatcher(),
    );

    $this->app->scoped(
        \Radiergummi\OpenApi\Core\Spec\SpecResolver::class,
        static fn() => new \Radiergummi\OpenApi\Core\Spec\SpecResolver(),
    );

    $this->app->scoped(
        \Radiergummi\OpenApi\Core\Spec\SpecRegistry::class,
        static fn(Container $app) => new \Radiergummi\OpenApi\Core\Spec\SpecRegistry(
            rootInfo:          (array) config('openapi.info', []),
            rootServers:       (array) config('openapi.servers', []),
            rootTags:          (array) config('openapi.tags', []),
            rootOutputPath:    (string) config('openapi.output_path'),
            rootRouteUri:      (string) (config('openapi.routes.spec.uri') ?? 'openapi.yaml'),
            rootPlaygroundUri: (string) (config('openapi.routes.playground.uri') ?? 'docs'),
            specs:             is_array(config('openapi.specs')) ? config('openapi.specs') : null,
            storagePath:       storage_path(''),
        ),
    );

    $this->app->scoped(
        \Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator::class,
        static function (Container $app): \Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator {
            $filterClasses = (array) config('openapi.filters', []);
            $filters = array_values(array_map(
                static function (mixed $entry) use ($app): \Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter {
                    return is_string($entry) ? $app->make($entry) : $entry;
                },
                $filterClasses,
            ));

            return new \Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator(
                globalFilters: $filters,
                matcher:       $app->make(\Radiergummi\OpenApi\Core\Spec\SpecMatcher::class),
                specResolver:  $app->make(\Radiergummi\OpenApi\Core\Spec\SpecResolver::class),
                visibility:    $app->make(\Radiergummi\OpenApi\Core\Visibility\VisibilityResolver::class),
            );
        },
    );
}
```

- [ ] **Step 2: Boot a Testbench app and confirm container resolution**

Smoke-check via the existing suite:

```
vendor/bin/pest tests/Unit/ tests/Feature/Lint/
```

Expected: green. Any container errors here mean a wiring mistake in this task or in C1.

- [ ] **Step 3: Commit**

```
git add src/OpenApiServiceProvider.php
git commit -m "feat(provider): bind SpecRegistry, SpecMatcher, SpecResolver, InclusionEvaluator"
```

---

### Task D2: Multi-spec route mounting + spec-aware `DocsController`

Iterate `SpecRegistry::all()` in `registerRoutes()`; mount one `spec` + one `playground` route per spec with non-null URIs. `DocsController` accepts the spec as a method arg.

**Files:**
- Modify: `src/OpenApiServiceProvider.php`
- Modify: `src/Http/DocsController.php`
- Modify: `resources/views/playground.blade.php` (so the playground view receives the spec URL — read the current view first, may already accept `$specUrl`)
- Test: `tests/Feature/MultiSpecRoutesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

it('mounts /api/openapi.yaml for the default spec', function (): void {
    $this->get('/api/openapi.yaml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml');
});

it('mounts named-spec routes when specs.X.route_uri resolves', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);
    // Re-register routes after config change. Use the package's testbench helper if available;
    // otherwise call $this->refreshApplication() to rebuild from the config snapshot.
    $this->refreshApplication();

    $this->get('/api/openapi-v1.yaml')->assertOk();
});

it('does not mount the route when route_uri is false', function (): void {
    config([
        'openapi.specs' => [
            'internal' => ['route_uri' => false, 'playground_uri' => false],
        ],
    ]);
    $this->refreshApplication();

    $this->get('/api/openapi-internal.yaml')->assertNotFound();
});
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Feature/MultiSpecRoutesTest.php
```

Expected: 404s or render errors for the new routes.

- [ ] **Step 3: Rewrite `registerRoutes()` to iterate the registry**

Replace `OpenApiServiceProvider::registerRoutes()`:

```php
private function registerRoutes(): void
{
    $config = (array) config('openapi.routes', []);

    if (($config['enabled'] ?? false) !== true) {
        return;
    }

    // Resolve eagerly here because routes must be declared during boot, not lazily.
    // SpecRegistry is scoped, but its sole job is config parsing — no heavy work happens
    // on construction and the result is memoised inside the registry.
    $registry = $this->app->make(\Radiergummi\OpenApi\Core\Spec\SpecRegistry::class);

    \Illuminate\Support\Facades\Route::group([
        'prefix'     => $config['prefix'] ?? 'api',
        'middleware' => $config['middleware'] ?? ['web'],
    ], static function () use ($registry, $config): void {
        foreach ($registry->all() as $spec) {
            if ($spec->routeUri !== null && ($config['spec']['enabled'] ?? true)) {
                $name = $spec->name === 'default' ? 'openapi.spec' : 'openapi.spec.' . $spec->name;

                \Illuminate\Support\Facades\Route::get($spec->routeUri, [DocsController::class, 'spec'])
                    ->defaults('spec', $spec->name)
                    ->name($name);
            }

            if ($spec->playgroundUri !== null && ($config['playground']['enabled'] ?? false)) {
                $name = $spec->name === 'default' ? 'openapi.playground' : 'openapi.playground.' . $spec->name;

                \Illuminate\Support\Facades\Route::get($spec->playgroundUri, [DocsController::class, 'playground'])
                    ->defaults('spec', $spec->name)
                    ->name($name);
            }
        }
    });
}
```

- [ ] **Step 4: Update `DocsController` to accept the spec arg**

```php
final class DocsController extends Controller
{
    public function spec(
        OpenApiGenerationOrchestrator $orchestrator,
        SpecRegistry $registry,
        Request $request,
        string $spec = 'default',
    ): BinaryFileResponse|Response {
        $definition = $registry->get($spec);

        if ((string) config('app.env', 'production') === 'local') {
            return response(
                $orchestrator->generateOne($spec, app()->environment())->toYaml(),
                200,
                ['Content-Type' => 'application/yaml', 'Cache-Control' => 'no-store'],
            );
        }

        if (!file_exists($definition->outputPath)) {
            throw new RuntimeException("OpenAPI specification '{$spec}' has not been generated");
        }

        return response()
            ->file($definition->outputPath, [
                'Content-Type'  => 'application/yaml',
                'Cache-Control' => 'public, max-age=300, must-revalidate',
            ])
            ->setAutoEtag()
            ->setAutoLastModified()
            ->tap(fn($r) => $r->isNotModified($request));
    }

    public function playground(SpecRegistry $registry, string $spec = 'default'): View
    {
        $registry->get($spec);  // 404 surface: throws InvalidArgumentException if unknown
        $routeName = $spec === 'default' ? 'openapi.spec' : "openapi.spec.{$spec}";

        return view('openapi::playground', ['specUrl' => route($routeName)]);
    }
}
```

(Top-of-file `use` additions: `Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator`, `Radiergummi\OpenApi\Core\Spec\SpecRegistry`. Remove the old `OpenApiGenerator` import.)

- [ ] **Step 5: Read the playground view; verify it consumes `$specUrl`**

```
cat resources/views/playground.blade.php
```

It already accepts `$specUrl` (passed today). No change required.

- [ ] **Step 6: Run the test, confirm pass**

```
vendor/bin/pest tests/Feature/MultiSpecRoutesTest.php
```

Expected: PASS for all three cases.

- [ ] **Step 7: Run the full feature suite to catch any regressions**

```
vendor/bin/pest tests/Feature/
```

Expected: green. (The `ScopedDiBindingsTest` may need a small adjustment since the binding set grew — read its expectations.)

- [ ] **Step 8: Commit**

```
git add src/OpenApiServiceProvider.php src/Http/DocsController.php tests/Feature/MultiSpecRoutesTest.php
git commit -m "feat(http): mount one spec + playground route per defined spec"
```

---

## Phase E — CLI

### Task E1: `GenerateCommand` — optional positional `spec`, `--explain`

The command now drives the orchestrator. With no arg it generates every spec; with a positional name it generates one.

**Files:**
- Modify: `src/Console/GenerateCommand.php`
- Modify: `tests/Unit/Console/GenerateCommandTest.php`

- [ ] **Step 1: Adapt the existing tests + add new ones**

Add these cases to `tests/Unit/Console/GenerateCommandTest.php` (preserve existing tests for the default path):

```php
it('generates every defined spec by default, writing each to its output_path', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);

    $defaultPath = storage_path('openapi.yaml');
    $v1Path = storage_path('openapi-v1.yaml');
    @unlink($defaultPath); @unlink($v1Path);

    $this->artisan('openapi:generate')->assertSuccessful();

    expect(file_exists($defaultPath))->toBeTrue()
        ->and(file_exists($v1Path))->toBeTrue();
});

it('generates only the named spec when passed positionally', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);

    $defaultPath = storage_path('openapi.yaml');
    $v1Path = storage_path('openapi-v1.yaml');
    @unlink($defaultPath); @unlink($v1Path);

    $this->artisan('openapi:generate v1')->assertSuccessful();

    expect(file_exists($v1Path))->toBeTrue()
        ->and(file_exists($defaultPath))->toBeFalse();
});

it('--explain prints one decision line per (route × spec)', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);

    $this->artisan('openapi:generate --explain')
        ->expectsOutputToContain('[default]')
        ->expectsOutputToContain('[v1]')
        ->assertSuccessful();
});

it('--output= is rejected when generating multiple specs', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);

    $this->artisan('openapi:generate --output=/tmp/x.yaml')
        ->expectsOutputToContain('--output= requires a single spec target')
        ->assertFailed();
});
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Unit/Console/GenerateCommandTest.php
```

Expected: most fail.

- [ ] **Step 3: Rewrite `GenerateCommand::handle()`**

```php
public function handle(
    OpenApiGenerationOrchestrator $orchestrator,
    SpecRegistry $registry,
    \Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator $evaluator,
    \Radiergummi\OpenApi\Core\Routing\RouteIntrospector $introspector,
): int {
    $specName = $this->argument(self::ARGUMENT_SPEC);
    $outputOverride = $this->option(self::OPTION_OUTPUT);
    $explain = (bool) $this->option(self::OPTION_EXPLAIN);

    $targets = $specName === null ? $registry->all() : [$registry->get((string) $specName)];

    if ($outputOverride !== null && count($targets) > 1) {
        $this->components->error('--output= requires a single spec target. Pass the spec name positionally.');

        return self::FAILURE;
    }

    if ($explain) {
        $this->emitExplain($evaluator, $introspector, $registry->all());
    }

    foreach ($targets as $spec) {
        $document = $orchestrator->generateOne($spec->name, app()->environment());

        if (!$this->validate($document)) {
            return self::FAILURE;
        }

        $content = $this->serialise($document);
        $path = $outputOverride !== null ? (string) $outputOverride : $spec->outputPath;

        try {
            $this->writeOutput($path, $content);
        } catch (Throwable $e) {
            $this->components->error("Failed to write OpenAPI file for spec '{$spec->name}': {$e->getMessage()}");
            return self::FAILURE;
        }

        if ($path !== '-') {
            $this->components->info("OpenAPI document for spec '{$spec->name}' written to {$path}");
        }
    }

    return self::SUCCESS;
}

private function writeOutput(string $path, string $content): void
{
    if ($path === '-') {
        fwrite(STDOUT, $content);
        return;
    }

    if (realpath(dirname($path)) === false) {
        throw new RuntimeException("Output directory does not exist: {$path}");
    }
    if (!is_writable(dirname($path))) {
        throw new RuntimeException("Output directory is not writable: {$path}");
    }
    file_put_contents($path, $content);
}

private function emitExplain(
    \Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator $evaluator,
    \Radiergummi\OpenApi\Core\Routing\RouteIntrospector $introspector,
    array $specs,
): void {
    foreach ($introspector->discover() as $descriptor) {
        foreach ($specs as $spec) {
            $decision = $evaluator->decide($descriptor, $spec, app()->environment());
            $mark = $decision->included ? '✓' : '✗';
            $method = $descriptor->route->methods()[0] ?? 'GET';
            $uri = $descriptor->route->uri();
            fwrite(STDERR, "[{$spec->name}] {$mark} {$method} {$uri}  {$decision->summary}\n");
        }
    }
}
```

Update `configure()`:

```php
protected function configure(): void
{
    $this->addArgument(self::ARGUMENT_SPEC, InputArgument::OPTIONAL,
        'Name of the spec to generate. Omit to generate every defined spec.', null);

    $this->addOption(self::OPTION_OUTPUT, null, InputOption::VALUE_REQUIRED,
        'Override output path. Requires a single spec target. Use "-" for stdout.');

    $this->addOption(self::OPTION_FORMAT, null, InputOption::VALUE_REQUIRED,
        'Output format: yaml or json.', 'yaml');

    $this->addOption(self::OPTION_EXPLAIN, null, InputOption::VALUE_NONE,
        'Print one (route × spec) decision line per route on stderr.');
}
```

Add the new constants at the top:

```php
public const string ARGUMENT_SPEC = 'spec';
public const string OPTION_OUTPUT = 'output';
public const string OPTION_EXPLAIN = 'explain';
```

Drop the old `ARGUMENT_PATH` constant — see Task E2 for the `ClearCommand` update that depended on it.

- [ ] **Step 4: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Console/GenerateCommandTest.php
```

Expected: every test PASS.

- [ ] **Step 5: Commit**

```
git add src/Console/GenerateCommand.php tests/Unit/Console/GenerateCommandTest.php
git commit -m "feat(cli): openapi:generate accepts positional spec; adds --explain"
```

---

### Task E2: `ClearCommand` — optional positional `spec`

Without arg: clears every spec's output file. With arg: only that spec's.

**Files:**
- Modify: `src/Console/ClearCommand.php`
- Modify: `tests/Unit/Console/ClearCommandTest.php`

- [ ] **Step 1: Update the failing tests**

```php
it('clears every spec output path when no arg passed', function (): void {
    config(['openapi.specs' => ['v1' => []]]);

    $default = storage_path('openapi.yaml');
    $v1 = storage_path('openapi-v1.yaml');
    file_put_contents($default, 'x');
    file_put_contents($v1, 'x');

    $this->artisan('openapi:clear')->assertSuccessful();

    expect(file_exists($default))->toBeFalse()
        ->and(file_exists($v1))->toBeFalse();
});

it('clears only the named spec', function (): void {
    config(['openapi.specs' => ['v1' => []]]);

    $default = storage_path('openapi.yaml');
    $v1 = storage_path('openapi-v1.yaml');
    file_put_contents($default, 'x');
    file_put_contents($v1, 'x');

    $this->artisan('openapi:clear v1')->assertSuccessful();

    expect(file_exists($default))->toBeTrue()
        ->and(file_exists($v1))->toBeFalse();
});

it('errors gracefully on an unknown spec name', function (): void {
    $this->artisan('openapi:clear nope')->assertFailed();
});
```

- [ ] **Step 2: Rewrite `ClearCommand`**

```php
class ClearCommand extends Command
{
    public const string ARGUMENT_SPEC = 'spec';

    protected $name = 'openapi:clear';
    protected $description = 'Remove the generated OpenAPI specification file(s)';

    public function handle(SpecRegistry $registry): int
    {
        $specName = $this->argument(self::ARGUMENT_SPEC);

        try {
            $targets = $specName === null ? $registry->all() : [$registry->get((string) $specName)];
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        }

        foreach ($targets as $spec) {
            if (file_exists($spec->outputPath)) {
                unlink($spec->outputPath);
            }
            $this->components->info("Cleared {$spec->outputPath}");
        }

        return self::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument(
            self::ARGUMENT_SPEC,
            InputArgument::OPTIONAL,
            'Name of the spec to clear. Omit to clear every defined spec.',
        );
    }
}
```

Add `use Radiergummi\OpenApi\Core\Spec\SpecRegistry;` at the top.

- [ ] **Step 3: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Console/ClearCommandTest.php
```

Expected: PASS.

- [ ] **Step 4: Commit**

```
git add src/Console/ClearCommand.php tests/Unit/Console/ClearCommandTest.php
git commit -m "feat(cli): openapi:clear accepts positional spec; defaults to all"
```

---

### Task E3: `openapi:why` command

Accepts a route name (exact match) or URI substring; resolves the descriptor; runs `InclusionEvaluator::decide` per spec; renders the trace.

**Files:**
- Create: `src/Console/WhyCommand.php`
- Test: `tests/Unit/Console/WhyCommandTest.php`
- Modify: `src/OpenApiServiceProvider.php` (register the command in the `runningInConsole` guard)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Console;

it('explains why a route is included in each spec', function (): void {
    // Use an existing testbench-registered route. The lint feature suite already exposes one;
    // pick a route URI that's known to exist in the test app, e.g. by inspecting routes via
    // `app('router')->getRoutes()` in a helper or by using a fixture route loaded in setUp.

    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);

    $this->artisan('openapi:why api/v1/flights')
        ->expectsOutputToContain('Route:')
        ->expectsOutputToContain('default:')
        ->expectsOutputToContain('v1:')
        ->expectsOutputToContain('Result:')
        ->assertSuccessful();
});

it('exits non-zero when the substring matches multiple routes', function (): void {
    $this->artisan('openapi:why api/')->assertFailed();
});

it('exits non-zero when no route matches', function (): void {
    $this->artisan('openapi:why nonsense/xyz')->assertFailed();
});

it('--env overrides app environment for Hide/Expose evaluation', function (): void {
    $this->artisan('openapi:why api/v1/flights --env=production')
        ->expectsOutputToContain('environment: production')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run, confirm failure**

```
vendor/bin/pest tests/Unit/Console/WhyCommandTest.php
```

Expected: command-not-found.

- [ ] **Step 3: Create `WhyCommand`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use function array_values;
use function str_contains;

class WhyCommand extends Command
{
    public const string ARGUMENT_ROUTE = 'route';
    public const string OPTION_ENV = 'env';

    protected $name = 'openapi:why';
    protected $description = 'Explain inclusion of a route across all defined specs';

    public function handle(
        RouteIntrospector $introspector,
        SpecRegistry $registry,
        InclusionEvaluator $evaluator,
    ): int {
        $query = (string) $this->argument(self::ARGUMENT_ROUTE);
        $env = (string) ($this->option(self::OPTION_ENV) ?? app()->environment());

        $candidates = $this->findCandidates($introspector, $query);

        if ($candidates === []) {
            $this->components->error("No route matched '{$query}'.");
            return self::FAILURE;
        }

        if (count($candidates) > 1) {
            $this->components->error("Ambiguous route '{$query}'. Candidates:");
            foreach ($candidates as $d) {
                $this->line("  - " . ($d->route->getName() ?? '(unnamed)') . "  " . $d->route->uri());
            }
            return self::FAILURE;
        }

        $descriptor = $candidates[0];
        $this->printHeader($descriptor, $env);

        $included = [];
        foreach ($registry->all() as $spec) {
            $decision = $evaluator->decide($descriptor, $spec, $env);
            $this->printSpecDecision($spec->name, $decision);
            if ($decision->included) {
                $included[] = $spec->name;
            }
        }

        $this->line('');
        $this->line('Result: ' . ($included !== [] ? 'included in [' . implode(', ', $included) . ']' : 'excluded from all specs'));

        return self::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument(self::ARGUMENT_ROUTE, InputArgument::REQUIRED,
            'Route name (exact) or URI substring.');
        $this->addOption(self::OPTION_ENV, null, InputOption::VALUE_REQUIRED,
            'Override the environment for Hide/Expose evaluation.');
    }

    /**
     * @return list<ActionDescriptor>
     */
    private function findCandidates(RouteIntrospector $introspector, string $query): array
    {
        $exact = [];
        $substring = [];

        foreach ($introspector->discover() as $descriptor) {
            if ($descriptor->route->getName() === $query) {
                $exact[] = $descriptor;
                continue;
            }

            if (str_contains($descriptor->route->uri(), $query)) {
                $substring[] = $descriptor;
            }
        }

        return $exact !== [] ? array_values($exact) : array_values($substring);
    }

    private function printHeader(ActionDescriptor $descriptor, string $env): void
    {
        $method = $descriptor->route->methods()[0] ?? 'GET';
        $this->line("Route: {$method} " . $descriptor->route->uri());
        $this->line('  controller: ' . ($descriptor->controller?->getName() ?? '(closure)'));
        $this->line('  middleware: ' . implode(', ', $descriptor->route->middleware()));
        $this->line('  environment: ' . $env);
        $this->line('');
    }

    private function printSpecDecision(string $specName, \Radiergummi\OpenApi\Core\Inclusion\InclusionDecision $decision): void
    {
        $this->line("{$specName}:");
        foreach ($decision->trace as $entry) {
            $mark = $entry->passed ? '✓' : '✗';
            $this->line("    {$mark} {$entry->stage} {$entry->name} — {$entry->reason}");
        }
        $this->line('    → ' . $decision->summary);
        $this->line('');
    }
}
```

- [ ] **Step 4: Register the command in the service provider**

In `OpenApiServiceProvider::boot()` inside the `runningInConsole()` block, add `WhyCommand::class` to the array.

- [ ] **Step 5: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Console/WhyCommandTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```
git add src/Console/WhyCommand.php src/OpenApiServiceProvider.php tests/Unit/Console/WhyCommandTest.php
git commit -m "feat(cli): add openapi:why for spec-inclusion debugging"
```

---

## Phase F — Lint

### Task F1: Add `?string $spec` to `Finding`

Findings need a spec tag so formatters can group output.

**Files:**
- Modify: `src/Core/Lint/Finding.php`
- Modify: `tests/Unit/Lint/...` (any tests that construct Finding directly — adapt to the new optional arg)

- [ ] **Step 1: Add the field + constructor arg + helper**

In `Finding.php`:

```php
public function __construct(
    public string $ruleId,
    public int $level,
    public string $message,
    public FindingLocation $location = new FindingLocation(),
    public ?string $fixHint = null,
    public array $context = [],
    public ?string $spec = null,
) {}

public function withSpec(?string $spec): self
{
    return new self(
        ruleId: $this->ruleId,
        level: $this->level,
        message: $this->message,
        location: $this->location,
        fixHint: $this->fixHint,
        context: $this->context,
        spec: $spec,
    );
}
```

Update the existing `withLocationDefaults`, `withLevel`, and `withMergedContext` to thread `spec` through (just pass `spec: $this->spec`).

Update `toArray()`:

```php
public function toArray(): array
{
    return [
        'rule_id'  => $this->ruleId,
        'level'    => $this->level,
        'message'  => $this->message,
        'fix_hint' => $this->fixHint,
        'location' => $this->location->toArray(),
        'context'  => $this->context,
        'spec'     => $this->spec,
    ];
}
```

- [ ] **Step 2: Run, confirm pass**

```
composer analyse && vendor/bin/pest tests/Unit/
```

Expected: green. Larastan may flag the JSON formatter snapshot tests — adjust expected JSON to include the new `spec` field (defaulting `null` for existing fixtures).

- [ ] **Step 3: Commit**

```
git add src/Core/Lint/Finding.php tests/
git commit -m "feat(lint): add optional spec field to Finding"
```

---

### Task F2: `PreBuildRule` interface

Visitor type for rules that inspect `SpecRegistry` + raw descriptors before any spec is built. Implementations get `FindingsCollector` to emit into.

**Files:**
- Create: `src/Core/Lint/Rules/Visitors/PreBuildRule.php`
- Test covered by the actual pre-build rules in F4.

- [ ] **Step 1: Create the interface**

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

use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

/**
 * Marker interface for lint rules that inspect the application configuration and the
 * route descriptor list before any spec is built. Used for "config-soundness" checks
 * like `spec.unknown-reference` and `spec.route-orphaned`.
 *
 * Pre-build rules run once per `openapi:lint` invocation regardless of `--spec=`.
 */
interface PreBuildRule
{
    /**
     * @param list<ActionDescriptor> $descriptors
     */
    public function checkConfiguration(
        SpecRegistry $specs,
        array $descriptors,
        FindingsCollector $findings,
    ): void;
}
```

- [ ] **Step 2: Commit**

```
git add src/Core/Lint/Rules/Visitors/PreBuildRule.php
git commit -m "feat(lint): add PreBuildRule visitor interface"
```

---

### Task F3: `LintRunner` — pre-build phase + per-spec loop + `--spec=` option

Loops every spec, calling the generator + tree walker per spec; tags emitted findings with the spec name. Runs pre-build rules once with the descriptor list and registry. Aggregates findings and returns one `LintResult` whose exit code is the max severity across specs.

**Files:**
- Modify: `src/Core/Lint/LintRunner.php`
- Modify: `src/Core/Lint/LintOptions.php` — add `?string $spec = null`
- Modify: `src/Console/LintCommand.php` — accept `--spec=`, pass through

- [ ] **Step 1: Add `spec` to `LintOptions`**

```php
public function __construct(
    public ?string $level = null,
    public array $only = [],
    public array $skip = [],
    public ?string $path = null,
    public bool $diffEnabled = false,
    public ?string $diffRef = null,
    public bool $applySuppressions = true,
    public ?string $spec = null,
) {}
```

- [ ] **Step 2: Add `--spec=` to `LintCommand` signature**

Append to the `#[Signature(…)]` block:

```
        {--spec= : Restrict per-spec rules to this spec (pre-build rules still run)}
```

And in `buildOptions()`, pass `spec: $this->option('spec') ?: null`.

- [ ] **Step 3: Refactor `LintRunner::run()` into a per-spec loop**

The detailed shape: split the current `runWithCollector()` into:

- `runPreBuild()` — iterate every `PreBuildRule` in the registry, call `checkConfiguration($specRegistry, $descriptors, $collector)`. Findings emitted carry `spec: null`.
- `runPerSpec(SpecDefinition $spec)` — extract the existing tree-walking + RouteRule + meta-suppression-stale logic; before emission, `$collector->emit($finding->withSpec($spec->name))`. The orchestrator's `generateOne($spec->name, $env)` produces the document; the rest is unchanged.
- `run(LintOptions $options)` — orchestrates: collect descriptors → resolve target specs (`$options->spec ? [$registry->get($options->spec)] : $registry->all()`) → run pre-build once → loop per-spec.

Pseudocode for the new outer flow inside `runWithCollector()`:

```php
$descriptors = $this->collectDescriptors($options);
$specRegistry = $this->container->make(\Radiergummi\OpenApi\Core\Spec\SpecRegistry::class);

// 1. Pre-build rules
foreach ($this->registry->preBuildRules() as $rule) {
    $rule->checkConfiguration($specRegistry, $descriptors, $collector);
}

// 2. Per-spec rules
$targets = $options->spec !== null ? [$specRegistry->get($options->spec)] : $specRegistry->all();
$orchestrator = $this->container->make(\Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator::class);

foreach ($targets as $spec) {
    $document = $orchestrator->generateOne($spec->name, app()->environment());
    $this->walkSpec($spec, $document, $descriptors, $options, $collector);
}
```

`walkSpec()` contains today's tree-walking, RouteRule pass, and meta-stale check. The trick: rules emit raw `Finding`s (no spec field) into a *spec-local* `ArrayFindingsCollector`; after the walk finishes, `walkSpec` drains that collector and re-emits each finding into the main collector tagged with the spec name:

```php
private function walkSpec(SpecDefinition $spec, OA\OpenApi $document, array $descriptors, LintOptions $options, ArrayFindingsCollector $mainCollector): void
{
    $specLocal = new ArrayFindingsCollector();
    $this->container->forgetScopedInstances();
    $this->container->instance(FindingsCollector::class, $specLocal);

    // …existing tree-walk + RouteRule + meta-stale logic, all emitting into $specLocal…

    foreach ($specLocal->all() as $finding) {
        $mainCollector->emit($finding->withSpec($spec->name));
    }
}
```

Inject the spec into the `LintContext` constructor too so rules that need it (none today, but future-proof) can read it.

Add `preBuildRules(): list<PreBuildRule>` to `RuleRegistry` (filter `$rules` by `instanceof PreBuildRule`).

- [ ] **Step 4: Add tests**

In `tests/Feature/Lint/LintCommandTest.php` (or a new file):

```php
it('runs pre-build rules once even with --spec= narrowing', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);

    // Trigger a pre-build rule violation: a route with #[Spec('does-not-exist')]
    // — register a temporary controller in the test app, then assert the rule fires.
    // (Use the existing test-controller registration pattern from other lint feature tests.)
});

it('tags per-spec findings with the spec name', function (): void {
    config([
        'openapi.specs' => [
            'v1' => ['match' => ['prefix' => 'api/v1/*']],
        ],
    ]);

    $result = app(\Radiergummi\OpenApi\Core\Lint\LintRunner::class)
        ->run(new \Radiergummi\OpenApi\Core\Lint\LintOptions());

    $specs = array_unique(array_map(fn($f) => $f->spec, $result->findings));
    expect($specs)->toContain('default')->toContain('v1');
});
```

- [ ] **Step 5: Run the lint test suite**

```
vendor/bin/pest tests/Feature/Lint/ tests/Unit/Lint/
```

Expected: green. Existing per-spec rule tests need `null` `spec` defaults; the field is optional so no test code change should be required for those that use the default constructor.

- [ ] **Step 6: Commit**

```
git add src/Core/Lint/ src/Console/LintCommand.php tests/
git commit -m "feat(lint): per-spec runner loop, pre-build rule phase, --spec= flag"
```

---

### Task F4: Pre-build rules `spec.unknown-reference`, `spec.route-orphaned`, `spec.config-orphaned`

Three rules implementing `PreBuildRule`. Each follows the existing rule pattern (extends a base class providing `id()`, `level()`, a `description()` for the catalog renderer).

**Files:**
- Create: `src/Core/Lint/Rules/SpecUnknownReference.php`
- Create: `src/Core/Lint/Rules/SpecRouteOrphaned.php`
- Create: `src/Core/Lint/Rules/SpecConfigOrphaned.php`
- Create: 3 corresponding `tests/Unit/Lint/Rules/Spec*Test.php`
- Modify: `src/Core/Registry/CoreRegistration.php` — register the three rules

- [ ] **Step 1: Read an existing simple rule to copy the structure**

```
cat src/Core/Lint/Rules/ComponentOrphaned.php
```

Note the `Rule` interface implementation (`id()`, `level()`, `description()`, plus visitor implementations). Copy this scaffold.

- [ ] **Step 2: Create `SpecUnknownReference`**

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

use Radiergummi\OpenApi\Core\Attributes\Spec as SpecAttribute;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

final readonly class SpecUnknownReference implements Rule, PreBuildRule
{
    public const string ID = 'spec.unknown-reference';

    public function id(): string  { return self::ID; }
    public function level(): int  { return 0; }
    public function description(): string
    {
        return '#[Spec(name:)] references a spec name not declared in config(\'openapi.specs\').';
    }

    public function checkConfiguration(SpecRegistry $specs, array $descriptors, FindingsCollector $findings): void
    {
        foreach ($descriptors as $descriptor) {
            foreach ($this->collectSpecAttributes($descriptor) as $names) {
                foreach ($names as $name) {
                    if (!$specs->has($name)) {
                        $findings->emit(new Finding(
                            ruleId:   self::ID,
                            level:    $this->level(),
                            message:  "Spec name '{$name}' referenced by #[Spec] is not declared in config('openapi.specs').",
                            location: FindingLocation::fromDescriptor($descriptor),
                            fixHint:  "Add '{$name}' to config('openapi.specs') or remove the attribute argument.",
                        ));
                    }
                }
            }
        }
    }

    /**
     * @return iterable<list<string>>
     */
    private function collectSpecAttributes(ActionDescriptor $descriptor): iterable
    {
        foreach ([$descriptor->actionReflector, $descriptor->controller] as $reflector) {
            if ($reflector === null) continue;
            foreach ($reflector->getAttributes(SpecAttribute::class) as $attr) {
                /** @var SpecAttribute $instance */
                $instance = $attr->newInstance();
                yield $instance->names;
            }
        }
    }
}
```

- [ ] **Step 3: Create `SpecRouteOrphaned`**

Same skeleton; emit when the union of all attribute names for a route, intersected with `$specs->all()` names, is empty. (Distinct framing from `unknown-reference`: this fires once per route, with explicit "appears nowhere" semantics, whereas `unknown-reference` fires once per offending attribute.)

```php
public function checkConfiguration(SpecRegistry $specs, array $descriptors, FindingsCollector $findings): void
{
    $known = array_map(fn($s) => $s->name, $specs->all());
    foreach ($descriptors as $descriptor) {
        $all = [];
        foreach ($this->collectSpecAttributes($descriptor) as $names) {
            $all = array_merge($all, $names);
        }
        if ($all === []) continue;
        if (array_intersect($all, $known) === []) {
            $findings->emit(new Finding(
                ruleId:   self::ID,
                level:    0,
                message:  'Route is pinned to specs that do not exist; it will not appear anywhere.',
                location: FindingLocation::fromDescriptor($descriptor),
                fixHint:  'Fix the #[Spec] argument(s) or declare the spec in config.',
            ));
        }
    }
}
```

- [ ] **Step 4: Create `SpecConfigOrphaned`**

Walk every defined spec; for each, count descriptors that the evaluator places in it. If zero, emit. Reuses `InclusionEvaluator`.

```php
public function checkConfiguration(SpecRegistry $specs, array $descriptors, FindingsCollector $findings): void
{
    foreach ($specs->all() as $spec) {
        $count = 0;
        foreach ($descriptors as $d) {
            if ($this->evaluator->decide($d, $spec, app()->environment())->included) {
                $count++;
                break;  // we only care whether it's zero
            }
        }
        if ($count === 0) {
            $findings->emit(new Finding(
                ruleId:   self::ID,
                level:    3,
                message:  "Spec '{$spec->name}' is defined in config but matches no routes.",
                fixHint:  'Adjust the spec\'s match config or remove the spec entry.',
            ));
        }
    }
}
```

Inject the evaluator via constructor.

- [ ] **Step 5: Write the three rule unit tests**

For each rule, write `tests/Unit/Lint/Rules/Spec{Name}Test.php` covering: (a) clean case → no findings, (b) violating case → one finding with the correct `ruleId`. Mirror the existing rule tests' shape.

- [ ] **Step 6: Register the rules in `CoreRegistration`**

Open `src/Core/Registry/CoreRegistration.php`, find where other lint rules are registered, and add:

```php
$registry->addRule(SpecUnknownReference::class);
$registry->addRule(SpecRouteOrphaned::class);
$registry->addRule(SpecConfigOrphaned::class);
```

- [ ] **Step 7: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Lint/Rules/Spec*
```

Expected: green.

- [ ] **Step 8: Commit**

```
git add src/Core/Lint/Rules/Spec*.php src/Core/Registry/CoreRegistration.php tests/Unit/Lint/Rules/Spec*Test.php
git commit -m "feat(lint): add three pre-build rules for spec config soundness"
```

---

### Task F5: Formatter grouping for per-spec findings

CLI formatter prints a `── spec: v1 ──` header before each group; JSON/GitHub formatters pass `spec` through (already on `Finding::toArray()`).

**Files:**
- Modify: `src/Core/Lint/Formatters/CliFormatter.php`
- Modify: `src/Core/Lint/Formatters/GithubFormatter.php` (annotation context only)
- Test: existing formatter tests; new cases for grouping

- [ ] **Step 1: Add grouping to `CliFormatter`**

Before the existing per-finding loop, group `$findings` by `spec` (with `null` rendered as `(pre-build)`). Write the section header per group:

```php
$grouped = [];
foreach ($findings as $f) {
    $key = $f->spec ?? '(pre-build)';
    $grouped[$key] ??= [];
    $grouped[$key][] = $f;
}

foreach ($grouped as $group => $entries) {
    $output->writeln("── spec: {$group} ──");
    // existing per-finding render here, fed $entries
}
```

- [ ] **Step 2: Verify `GithubFormatter` includes `spec` in annotation context**

The GitHub annotation has limited dedicated fields; embed the spec in the message prefix:

```php
$prefix = $finding->spec !== null ? "[spec:{$finding->spec}] " : '';
$line = "::{$severity} ... ::{$prefix}{$finding->message}";
```

- [ ] **Step 3: JSON formatter — `Finding::jsonSerialize()` already emits `spec`, no change required**

- [ ] **Step 4: Add a formatter test**

In `tests/Unit/Lint/Formatters/CliFormatterTest.php`:

```php
it('groups findings by spec with a header per group', function (): void {
    $findings = [
        new Finding('rule.a', 0, 'msg', spec: 'default'),
        new Finding('rule.b', 0, 'msg', spec: 'v1'),
    ];
    // render and assert the output contains both "── spec: default ──" and "── spec: v1 ──".
});
```

- [ ] **Step 5: Run, confirm pass**

```
vendor/bin/pest tests/Unit/Lint/Formatters/
```

Expected: green.

- [ ] **Step 6: Commit**

```
git add src/Core/Lint/Formatters/ tests/Unit/Lint/Formatters/
git commit -m "feat(lint): group findings by spec in CLI formatter, prefix in GitHub formatter"
```

---

## Phase G — Guardrails & docs

### Task G1: Feature test: no eager DI resolution on unrelated requests

Protects the "production footprint" guarantee in the spec. Adds a route → make a request → assert none of the generation services were resolved.

**Files:**
- Create: `tests/Feature/NoEagerResolutionTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

it('does not resolve any OpenAPI service when handling an unrelated request', function (): void {
    Route::get('/ping', fn() => 'pong');

    // Sanity: make a request to a non-OpenAPI route.
    $this->get('/ping')->assertOk();

    // Assert none of these scoped bindings were resolved.
    $tracked = [
        OpenApiGenerator::class,
        OpenApiGenerationOrchestrator::class,
        InclusionEvaluator::class,
        SpecRegistry::class,
    ];

    foreach ($tracked as $cls) {
        expect($this->app->resolved($cls))
            ->toBeFalse("expected {$cls} NOT to be resolved on an unrelated request");
    }
});
```

- [ ] **Step 2: Run, confirm pass**

```
vendor/bin/pest tests/Feature/NoEagerResolutionTest.php
```

Expected: PASS. If it fails, find the offending eager `make()` in the service provider; only acceptable resolution during boot for OpenAPI services is `SpecRegistry` inside `registerRoutes()` (acceptable because routes must be declared at boot). If `SpecRegistry::resolved` is asserted false, exclude it from the `$tracked` array with an explanatory comment.

- [ ] **Step 3: Commit**

```
git add tests/Feature/NoEagerResolutionTest.php
git commit -m "test(provider): guardrail asserting no eager DI resolution in unrelated requests"
```

---

### Task G2: `docs/multi-spec.md`

The new documentation page. Three sections: concepts, config reference, debugging. Three worked examples.

**Files:**
- Create: `docs/multi-spec.md`

- [ ] **Step 1: Write the doc**

Outline to follow:

```markdown
# Multi-Spec

> One Laravel application can generate any number of OpenAPI documents — `default`, plus
> any number of named specs partitioned by URL prefix, middleware, namespace, or `#[Spec]`
> attribute.

## Concept

A *spec* is a named, independently-generated OpenAPI document. Without any config, you
get one spec: `default`. Adding entries under `config('openapi.specs')` defines named
extras…

## When to use it

- **API versions** — `v1` / `v2` partitioned by URL prefix.
- **Audience splits** — `partner` / `internal` partitioned by middleware.
- **Domain splits** — `storefront` / `admin` partitioned by namespace.

## Inclusion rule

(reproduce the four-rule table from the design spec verbatim)

## Config reference

(reproduce the config shape from the design spec verbatim)

## The `#[Spec]` attribute

(reproduce the attribute table from the design spec verbatim)

## Debugging — `openapi:why`

(show the formatted output)

## Worked examples

### Example 1: v1 / v2 versioning

…full snippet with config and a 1-route fixture…

### Example 2: public / partner / internal audience split

…

### Example 3: domain split with explicit `#[Spec]` override

…
```

- [ ] **Step 2: Commit**

```
git add docs/multi-spec.md
git commit -m "docs(multi-spec): add concept, config reference, and worked examples"
```

---

### Task G3: Update `docs/usage.md`, `docs/lint-rules.md`, `README.md`

**Files:**
- Modify: `docs/usage.md` (or `docs/README.md` — check which is the index)
- Modify: `docs/lint-rules.md` (add the three new pre-build rules with their IDs and severities)
- Modify: `README.md` (mention multi-spec in feature list, link to docs/multi-spec.md)
- Modify: `CHANGELOG.md` ([Unreleased] entry — one bullet, no migration framing)

- [ ] **Step 1: Add "Multi-spec" to docs index**

In `docs/README.md` (the docs index), insert a link to `multi-spec.md` in the appropriate section.

- [ ] **Step 2: Document the three new rules**

In `docs/lint-rules.md`, add:

```markdown
### `spec.unknown-reference` (level 0)
#[Spec('foo')] references a spec name that is not declared in `config('openapi.specs')`.

### `spec.route-orphaned` (level 0)
A route's #[Spec] list resolves to no defined specs — the route appears nowhere.

### `spec.config-orphaned` (level 3)
A configured spec ends up with zero routes assigned after evaluation.
```

- [ ] **Step 3: Update `README.md`**

Add a bullet under the feature list:

```markdown
- **Multi-spec** — partition routes into multiple OpenAPI documents (v1/v2, public/partner/internal, …) with optional `#[Spec]` override. See [`docs/multi-spec.md`](docs/multi-spec.md).
```

- [ ] **Step 4: Update `CHANGELOG.md`**

Under `[Unreleased]`:

```markdown
### Added
- Multi-spec support: `config('openapi.specs')`, `#[Spec]` attribute, `openapi:why` command, `openapi:generate --explain`, per-spec lint runs, three new pre-build rules (`spec.unknown-reference`, `spec.route-orphaned`, `spec.config-orphaned`).
```

- [ ] **Step 5: Commit**

```
git add docs/README.md docs/lint-rules.md README.md CHANGELOG.md
git commit -m "docs: cross-link multi-spec; catalogue new lint rules"
```

---

### Task G4: Update the published `config/openapi.php` stub

Add the commented `'specs' => [...]` example block teaching the multi-spec config.

**Files:**
- Modify: `config/openapi.php`

- [ ] **Step 1: Insert a new section before the existing `output_path` block**

Add (placement: between the `visibility` block and the `lint` block — find an appropriate location):

```php
    /*
    |--------------------------------------------------------------------------
    | Named Specs (Multi-Spec)
    |--------------------------------------------------------------------------
    |
    | Define additional named OpenAPI specs alongside the implicit 'default' spec
    | (whose settings come from the root keys above). Each entry partitions routes
    | by URL prefix, middleware tokens, or controller namespace; #[Spec('name')]
    | on a route overrides match-based assignment.
    |
    | Defaults for named specs:
    |   - output_path    storage_path("openapi-{name}.yaml")
    |   - route_uri      "openapi-{name}.yaml"   (false / null to not mount)
    |   - playground_uri "docs/{name}"           (false / null to not mount)
    |
    | Omit this key entirely for single-spec mode.
    |
    */

    'specs' => [
        // 'v1' => [
        //     'info'  => ['version' => '1.x'],
        //     'match' => [
        //         'prefix' => 'api/v1/*',
        //         // 'middleware' => 'auth:partner',
        //         // 'namespace'  => 'App\\Http\\Controllers\\V1\\',
        //     ],
        // ],
    ],
```

- [ ] **Step 2: Commit**

```
git add config/openapi.php
git commit -m "config: document multi-spec example in published config stub"
```

---

### Task G5: Full test + analyse sweep, then green-light

**Files:** none (validation only)

- [ ] **Step 1: Composer test + analyse + lint**

```
composer test && composer analyse && composer lint
```

Expected: all green. Address any leftover Larastan errors or Pint violations inline.

- [ ] **Step 2: Final commit**

If any incidental fixes were needed:

```
git commit -am "chore: address residual lint/analyse findings post multi-spec"
```

---

## Self-review summary

This is a 19-task plan in 7 phases. Spec coverage spot-check:

- Conceptual model (spec sec. "Conceptual model") → Tasks A2, A4, B2.
- Config shape (spec sec. "Config shape") → Task A4 (`SpecRegistry` parsing), G4 (published stub).
- `#[Spec]` attribute (spec sec. "The `#[Spec]` attribute") → Tasks A5, A6.
- Inclusion rule (spec sec. "Inclusion rule") → Task B2 (`InclusionEvaluator`).
- Pipeline changes (spec sec. "Pipeline changes") → Tasks A1, C1, C2, D1.
- HTTP routes (spec sec. "HTTP routes") → Task D2.
- CLI (spec sec. "CLI") → Tasks E1 (generate), E2 (clear), E3 (why).
- Lint (spec sec. "Lint pipeline" + "Three new pre-build lint rules") → Tasks F1–F5.
- Production DI footprint (spec sec. "Production DI footprint") → Task G1 (guardrail test); all service-provider tasks (C1, C2, D1, D2) use only `scoped` bindings.
- Documentation (spec sec. "Documentation") → Tasks G2, G3.

Decisions deferred (spec sec. "Decisions deferred to the implementation plan") are surfaced as: tag merge default (replace wholesale; revisit), `specs.default` overlay semantics (additive on top of root), `openapi:why` ambiguity policy (list + exit 1), formatter grouping (CLI grouped, JSON/GH spec-tagged), and the orchestrator's reset coverage (G1 test).

No `TBD`, `TODO`, or "see above" stubs. All code shown is concrete enough to type in. Some test bodies reference fixtures or test patterns the engineer needs to read first (existing rule tests, `ActionDescriptor` constructor shape, current playground view, `RouteIntrospector` usage) — these are explicitly called out as "read this file before writing the test" steps, not handwaved.
