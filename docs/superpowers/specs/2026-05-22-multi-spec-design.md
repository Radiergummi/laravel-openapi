# Multi-Spec — Design

**Date:** 2026-05-22
**Status:** Approved (brainstorming)
**Purpose:** Let one Laravel application produce several independent OpenAPI documents — for API versions, audience splits (public / partner / internal), or domain splits — from a single set of routes. Inspired by `vyuldashev/laravel-openapi`'s `Collection` mechanism, adapted to this package's convention-first philosophy and flat Laravel-idiomatic config.

---

## Goals

- One Laravel app → many OpenAPI specs, with a generic mechanism that doesn't bake in a single use case.
- Zero-annotation default for the common cases (v1/v2 by URL prefix, audience by middleware, domain by namespace).
- Routes can belong to several specs at once. That is the common case.
- Visibility (`#[Hide]` / `#[Expose]`) stays a separate concern from spec membership.
- No change for users who don't opt in: a config without `'specs'` behaves exactly like today.
- A first-class way to debug "why is this route in / not in this spec?".
- Multi-spec must not increase the production DI footprint beyond mounting the extra HTTP routes.

## Non-goals

- Per-spec field visibility (e.g., a property that's `readOnly` in `public` but writable in `internal`). Useful, but a separate feature with its own design.
- Per-spec extractors or plugin configurations. Plugins remain global.
- Closure-based filters in config. They break `php artisan config:cache` and are not supported anywhere.
- Cross-spec lint rules (e.g., schema divergence between specs). The hook is omitted for now; nothing concrete needs it. Adding it later is non-breaking.
- A "primary" spec other than the implicit `default`. Every named spec is equal in stature.

## Conceptual model

Four orthogonal inputs decide whether a route ends up in a spec:

| Input | Today | After this design |
|---|---|---|
| **Global exclusion** — drop a route from documentation entirely | `config.filters` (Nova / Telescope / Ignition / Passport skips) | Unchanged. `RouteFilter::shouldSkip(Route): bool`. |
| **Spec partition** — which specs claim a route | n/a (one document) | New `'specs.X.match'` config: `prefix`, `middleware`, `namespace`. |
| **Per-route override** — pin a route to specific specs | n/a | New `#[Spec(string\|string[]\|null $name = null)]` attribute. |
| **Visibility** — should this be documented at all (env-scoped) | `#[Hide]` / `#[Expose]` + `visibility.default` | Unchanged. |

The mechanisms are deliberately separate. `Hide` answers *"document this at all?"*; `Spec` and `match` answer *"in which buckets?"*. Conflating them through a `Hide(spec:)` parameter was an early proposal and was rejected.

### Inclusion rule

A route R is in spec X iff **all four** hold:

1. No `RouteFilter` in `config.filters` says `shouldSkip(R) === true` (global exclusion passes).
2. Either:
   - R has **no** `#[Spec]` attribute → spec X's `match` config matches R; **or**
   - R has a `#[Spec]` attribute → X is in its list.
3. R is not `#[Hide]`-d for the current environment.
4. `visibility.default = 'public'` **or** R is `#[Expose]`-d for the current environment.

The `default` spec, when no `'specs.default.match'` config is present, matches every route — so any route not pinned elsewhere by `#[Spec]` lands in `default`. This is the catch-all that preserves today's single-spec behavior.

A route with no `#[Spec]` attribute may match several specs at once — `match` configs are independent, not mutually exclusive. This is intentional and the common case.

## Config shape

Root keys define the implicit `default` spec. `'specs'` adds named extras. The 80% case stays flat:

```php
// config/openapi.php  (additions only; the rest is unchanged)

return [
    // Root keys = implicit 'default' spec (today's behavior, unchanged).
    'info'        => [...],
    'servers'     => [...],
    'tags'        => [...],
    'output_path' => storage_path('openapi.yaml'),
    'routes'      => [
        'enabled' => true, 'prefix' => 'api', 'middleware' => ['web'],
        'spec'       => ['enabled' => true, 'uri' => 'openapi.yaml'],
        'playground' => ['enabled' => env('APP_ENV') === 'local', 'uri' => 'docs'],
    ],

    'filters' => [
        SkipNovaRoutes::class,
        SkipTelescopeRoutes::class,
        SkipIgnitionRoutes::class,
        SkipPassportRoutes::class,
    ],

    // Optional. Omit the whole key for single-spec mode.
    'specs' => [
        // 'default' may be listed explicitly — only useful when you want to set
        // `match` on the default spec. Otherwise it is inferred from root keys.

        'v1' => [
            'info'  => ['version' => '1.x'],      // merged over root info
            'match' => [
                'prefix' => 'api/v1/*',
            ],
            // All four below are optional; the defaults shown apply when absent.
            // 'output_path'    => storage_path('openapi-v1.yaml'),
            // 'route_uri'      => 'openapi-v1.yaml',  // false/null to not mount
            // 'playground_uri' => 'docs/v1',          // false/null to not mount
        ],

        'partner' => [
            'info'    => ['title' => 'Partner API', 'version' => '2025-01'],
            'servers' => [['url' => 'https://partners.example.com']],
            'match'   => [
                'middleware' => 'auth:partner',
            ],
            // Inherits defaults for output_path, route_uri, playground_uri.
        ],

        'internal' => [
            'match' => [
                'namespace' => 'App\\Http\\Controllers\\Internal\\',
            ],
            'route_uri'      => false,   // not served over HTTP
            'playground_uri' => false,
        ],
    ],
];
```

### Rules for `'specs.X.*'` entries

- **Defaults for the four optional keys** (named spec `foo`):
  - `output_path` → `storage_path("openapi-foo.yaml")`
  - `route_uri` → `"openapi-foo.yaml"` (set to `null` or `false` to not mount)
  - `playground_uri` → `"docs/foo"` (set to `null` or `false` to not mount)
- **`info` merge**: deep-merged over root `info`. Subset overrides win.
- **`servers` / `tags` merge**: replaced wholesale by the spec's value (lists, not maps — merging is ambiguous). Absent key → inherit root.
- **`match`** keys:
  - `prefix` — `string|string[]` of URI globs; matches via `fnmatch()`. OR across entries.
  - `middleware` — `string|string[]`. Matches a route's middleware list literally or by prefix-before-`:` (so `'auth'` matches `'auth:api'`). OR across entries.
  - `namespace` — `string|string[]`. Matches when the controller's FQCN starts with the prefix. OR across entries.
  - AND across the three keys: a route matches `match` iff every present key matches.
  - An empty/missing `match` block matches everything (relevant only for the `default` spec and for users who explicitly want a "catch the rest" spec).
- **`filters`** is **not** a per-spec key. Global filters in `config.filters` apply to every spec; spec partitioning lives in `match`.

### Why no `SpecMatcher` interface

For partition logic that isn't `prefix`/`middleware`/`namespace`-shaped, users put `#[Spec('v1')]` on the controllers in question (or on a base controller). Attributes are config-cache safe; closures are not. If a real use case for class-based custom matchers emerges, the design admits a `SpecMatcher` class-string entry in `match` as a future addition — non-breaking.

## The `#[Spec]` attribute

```php
// src/Core/Attributes/Spec.php
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Spec
{
    /** @var list<string> */
    public array $names;

    public function __construct(string|array|null $name = null)
    {
        $this->names = match (true) {
            $name === null   => ['default'],
            is_string($name) => [$name],
            default          => array_values($name),
        };
    }
}
```

Semantics:

| Form | Effective specs |
|---|---|
| no `#[Spec]` | filter-based (via `match`) |
| `#[Spec('v1')]` | `['v1']` |
| `#[Spec(['v1', 'v2'])]` | `['v1', 'v2']` |
| `#[Spec]` / `#[Spec(null)]` | `['default']` |
| `#[Spec('v1')]` on controller + `#[Spec('v2')]` on method | method wins → `['v2']` |
| `#[Spec('v1'), Spec('v2')]` on same target | union → `['v1', 'v2']` |

**Resolution rule for the repeatable + class/method case:** if the method carries any `#[Spec]` attribute, the union of all method-level Spec attributes is used and class-level Spec attributes are ignored. Otherwise, the union of class-level Spec attributes applies. (Method-level absence means "inherit class"; method-level presence means "replace class entirely with my list", consistent with the way `#[Hide]` / `#[Expose]` resolve today.)

When `#[Spec]` is present, `match` is ignored for that route. Global filters and `Hide`/`Expose` still apply.

## Pipeline changes

### New services (all `scoped`)

- **`SpecRegistry`** — reads `'specs'` + root keys at first resolution, materialises a list of `SpecDefinition` value objects. Provides `all()`, `get(string)`, `default()`.
- **`SpecDefinition`** — immutable: `name`, `OA\Info`, `OA\Server[]`, `OA\Tag[]`, `match` config, `outputPath`, `routeUri` (nullable), `playgroundUri` (nullable).
- **`InclusionEvaluator`** — single source of truth for the four-rule decision. Used by the generator (reads `->included`), by `openapi:why` and `--explain` (read `->trace`).
- **`InclusionDecision`** — `bool $included`, `list<TraceEntry> $trace`, `string $reason`.
- **`OpenApiGenerationOrchestrator`** — thin wrapper over `OpenApiGenerator` exposing `generateOne(string $spec): OA\OpenApi` and `generateAll(): array<string, OA\OpenApi>`. Owns the reset calls between spec runs.

### Changes to existing services

- **`OpenApiGenerator::generate()`** — signature changes to `generate(SpecDefinition $spec, array $extraFilters = []): OA\OpenApi`. Internally calls `InclusionEvaluator::decide()` per route. Builds `info`/`servers`/`tags` from `$spec`, not config directly.
- **`ComponentSchemaRegistry`** and **`ExampleFileLoader`** — `reset()` methods (already present) get called by the orchestrator between spec generations. Scoped lifetime is preserved; the reset handles intra-request multi-spec runs.
- **`RouteFilter`** — interface **unchanged**. Existing shipped filters unchanged.
- **`RouteIntrospector`** — runs once per generation cycle, descriptors are reused across specs.
- **`OperationBuilder`**, extractors, resolvers, plugins — unchanged. All per-operation work is spec-agnostic.

### Lint pipeline

Lint runs against **every spec** by default. `--spec=v1` narrows the per-spec rule pass; pre-build rules always run.

Two rule scopes:

- **Pre-build rules.** New `PreBuildRule` interface. Sees `SpecRegistry` + the full route-descriptor list (so it can read `#[Spec]` attributes without building any spec). Runs once per `lint` invocation.
- **Per-spec rules.** Today's `Rules/Visitors/*Rule` set, dispatched by `SpecTreeWalker` against one generated spec at a time. Findings carry a new `spec` field. Formatters group output by spec; text formatter prints a `── spec: v1 ──` header per group. Exit code is the worst severity across all specs.

`RuleRegistry` and `SuppressionCollector` are shared across specs — rules are stateless after construction; `#[IgnoreLint]` attributes are spec-agnostic.

### Three new pre-build lint rules

| ID | Level | Detects |
|---|---|---|
| `spec.unknown-reference` | 0 (broken) | `#[Spec('foo')]` references a spec name not in `'specs'` config or `'default'`. |
| `spec.route-orphaned` | 0 (broken) | A route's `#[Spec]` list resolves to zero existing specs → route appears nowhere. |
| `spec.config-orphaned` | 3 (inconsistent) | A configured spec ends up with zero routes assigned after evaluation. |

## CLI

### `openapi:generate [spec?] [--output=] [--format=] [--explain]`

- No `spec` arg → generates every configured spec, writing each to its `output_path`.
- `openapi:generate v1` → generates only `v1`. `--output=` overrides that spec's output path (chiefly for `--output=-` stdout).
- `--format=yaml|json` (existing flag, unchanged).
- `--explain` → for every (route × spec) pair, print the decision on stderr while writing the document(s) normally. One line per pair: `[spec] ✓/✗ METHOD uri  reason`.

The existing positional `path` argument behavior — "write to this path" — is preserved when only one spec is targeted, for back-compat. With multiple specs, `--output=` is rejected with a clear error.

### `openapi:lint [--spec=] [--format=] [--level=]`

- No `--spec` → lints every spec.
- `--spec=v1` → narrows per-spec rules. Pre-build rules still run unconditionally — that's their purpose.
- Output grouped by spec; findings tagged with `spec` field.
- Exit code = worst severity across all specs.

### `openapi:why <route> [--env=]` (new)

- `<route>` accepts a route name (exact match) or a URI substring. Ambiguous URI → list matches and exit `1`.
- `--env=production` overrides `app()->environment()` for the Hide/Expose evaluation. Useful for "why isn't this in the prod spec?" without setting `APP_ENV`.
- Output (see the brainstorming transcript for the formatted example):
  - Route metadata (method, URI, controller, middleware).
  - Global filter results (one line per filter, ✓/✗ + reason).
  - Visibility section (resolved attributes, default mode, verdict).
  - Per-spec membership (attribute or `match` evaluated per spec, verdict, reason).
  - Final summary: `included in [spec1, spec2]` or `excluded from all specs`.
- Console-only; registered inside the existing `if ($this->app->runningInConsole())` guard.

### `openapi:clear [spec?]`

- No arg → clears every spec's `output_path`.
- `openapi:clear v1` → clears only `v1`'s file.

## HTTP routes

`OpenApiServiceProvider::registerRoutes()` iterates `SpecRegistry::all()`. For each spec:

- If `route_uri !== null` and `!== false`, mount `GET {prefix}/{route_uri}` → `DocsController::spec($specName)`. Route name: `openapi.spec` for `default` (today's name preserved); `openapi.spec.{name}` for named.
- Same pattern for `playground_uri` → `DocsController::playground($specName)`. Route name: `openapi.playground` / `openapi.playground.{name}`.

`DocsController`'s methods take `string $spec = 'default'`. Local-dev regeneration: `$orchestrator->generateOne($spec)`. Production: serves the static file at `$specRegistry->get($spec)->outputPath`. The Scalar playground view receives the resolved spec URL for the current `$spec`.

## Production DI footprint

The feature must not add to per-request cost in production for routes that aren't the spec/playground endpoints.

| Action | Cost in a non-OpenAPI request |
|---|---|
| Service provider `boot()` | Mount 1–2 routes for `default` + 2× N for named specs. Routes are router hash-map inserts. |
| Service provider `register()` | N `scoped(closure)` bindings. Closures don't execute. |
| Config | One `mergeConfigFrom` at boot. |
| `SpecRegistry`, `OpenApiGenerationOrchestrator`, `InclusionEvaluator`, all extractors, plugins | **Zero** — none resolved unless a spec/playground/console path is hit. |
| Console commands | Registered only inside `if ($this->app->runningInConsole())`. |

### Guardrails

1. No eager `$this->app->make(...)` calls in `register()` / `boot()` — only inside binding closures.
2. `SpecRegistry` is `scoped`; config is parsed and `RouteFilter` instances are resolved lazily on first call.
3. `DocsController` constructor stays cheap; it only resolves the orchestrator when actually invoked (already the shape today; preserve it).
4. A new feature test boots a fresh app, makes a request to an unrelated route, and asserts none of the generation services were resolved. This prevents regressions where someone adds an eager `make()`.

## Back-compat & migration

The feature is opt-in via the `'specs'` config key for **config-side users**. For users who call the generator programmatically there is one small API break.

- **No `'specs'` key** → single-spec mode. `SpecRegistry::all()` returns one entry materialised from root keys. `RouteFilter` signature unchanged. `Hide`/`Expose` unchanged. CLI unchanged for the no-arg case. HTTP routes unchanged.
- **Existing custom `RouteFilter` implementations** — no change; signature is preserved.
- **`#[Spec]`** is additive — absent in existing code, so existing code behaves identically.
- The published `config/openapi.php` stub keeps showing today's flat single-spec form, plus a commented-out `'specs' => [...]` example.
- **`OpenApiGenerator::generate()` signature changes** from `generate(array $filters = []): OA\OpenApi` to `generate(SpecDefinition $spec, array $extraFilters = []): OA\OpenApi`. Pre-1.0, this is fine; the `CHANGELOG.md` entry calls it out with a one-line migration recipe (`$generator->generate($specRegistry->default())`).

`CHANGELOG.md` gets an `[Unreleased]` entry describing the additive feature and pointing at `docs/multi-spec.md`.

## Documentation

- `docs/multi-spec.md` (new) — concept; config shape with defaults and opt-out values; the four-rule inclusion table; `#[Spec]` attribute reference; debugging with `openapi:why` / `--explain`; three worked examples (v1/v2 versioning, internal/external audience split, domain split).
- `docs/usage.md` index → add a "Multi-spec" link.
- `docs/lint-rules.md` → add the three new pre-build rules.
- `README.md` → mention multi-spec in the feature list and link to `docs/multi-spec.md`.

## Decisions deferred to the implementation plan

1. **`SpecDefinition::tags` merge.** Spec said "replaced wholesale." Reconsider during implementation: per-tag-name deep merge (keyed by name) may be more useful for the common case of overriding only a description. Lean towards deep-merge for symmetry with `info`.
2. **`'specs.default'` explicit entry merge order.** Root keys are the base; `'specs.default'` (if present) overrides root; named specs override root independently. A `'specs.default'` entry is purely additive — mainly useful for setting `match` on the default spec.
3. **`openapi:why` ambiguity policy.** Substring URI match listing all candidates and exiting `1` is the proposed UX; revisit if it proves annoying in practice (e.g., add a `--first` flag).
4. **Lint formatter grouping.** Text formatter clearly groups by spec; JSON/GitHub formatters add a `spec` field but keep flat structure. Confirm during implementation that GitHub's annotation format accepts the extra context the way we expect.
5. **Octane multi-spec test.** Concrete test that `generateAll()` produces N independent specs in one request without cross-contamination of `ComponentSchemaRegistry` state. New regression guard.

---

## Out of scope (explicitly)

- Cross-spec lint rules. The `CrossSpecRule` hook is omitted; no current use case justifies it.
- Per-spec field visibility / per-spec extractors / per-spec plugins.
- Spec inheritance hierarchies ("v2 extends v1") beyond the implicit root-keys-as-default base.
- Runtime spec selection (`?spec=v2` query param on a single endpoint). Specs are build-time artifacts.
