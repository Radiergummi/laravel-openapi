# Upstream coupling manifest

Curated knowledge for the `upstream-review` skill. One section per tracked package.
Section headings (`## vendor/package`) are the single source of truth for the tracked
set — `bin/outdated` reads them. Version constraints live in `composer.json`, never
here; entries point at the composer block instead.

Per entry:
- **Constraint location** — which composer block holds the constraint.
- **Coupling** — why a change here can hurt us.
- **Tripwire (leg 2)** — the mechanical signal that would catch a break here.
- **Diff targets (leg 3)** — upstream symbols to diff when legs 1–2 are clean but the
  changelog hints at semantic change. Starting points; refine as we learn.
- **Known-fragile** — issues/notes for soft spots we already know.
- **Watermark** — `Last reviewed: <version> (<date>)`.
- **Tier/area** — default labels for issues filed against this package.

## zircote/swagger-php
- **Constraint location:** require (`zircote/swagger-php`)
- **Coupling:** We emit `OA\*` attribute objects and rely on its serializer + validator.
  Its model decides which JSON-Schema keywords survive round-trip.
- **Tripwire:** `snapshot` group (byte-exact) + ExamplesTest; the dedicated swagger-php-5.8
  CI job is the cross-version guard.
- **Diff targets:**
  - `OA\Schema` properties — does it now model keywords it didn't? (if/then/else, not,
    dependentRequired). Unblocks #140.
  - `Generator` / `Analysis` serialization — raw nested-array schema handling (5.8 rejects
    what 6.x accepts).
- **Known-fragile:** #140 (unmodeled keywords); 5.x vs 6.x validation split.
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-0, area:core

## laravel/framework
- **Constraint location:** require, via `illuminate/console|contracts|routing|support`
- **Coupling:** RouteIntrospector walks the route collection; we read FormRequest, API
  Resource, route-model-binding, and middleware internals.
- **Tripwire:** full test suite + survey Layer A across consumer apps; `#[Override]` on
  anything we extend errors hard on signature change.
- **Diff targets:** (only if Layer A regresses or changelog flags routing/validation)
  - `Routing\Route` — middleware gathering, parameter/binding resolution.
  - `Foundation\Http\FormRequest` + `ValidatesWhenResolved` — rule extraction surface.
  - `Http\Resources\Json\JsonResource` — API Resource response shape.
- **Known-fragile:** route introspection is Tier-0 reflection; signature breaks caught
  mechanically, semantic binding changes are not.
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-0, area:core

## spatie/laravel-data
- **Constraint location:** require-dev (`spatie/laravel-data`) — also a `suggest`
- **Coupling:** SpatieData plugin reads Data class shapes to build `$ref` schema
  components — properties, casts, optional/nullable, collections.
- **Tripwire:** spatie-data examples (`examples:spatie-data`) + ExamplesTest snapshots.
- **Diff targets:** (if examples drift or changelog flags property/cast resolution)
  - `Data` base class + `DataProperty` / `DataClass` reflection model.
  - Attribute classes the plugin reads (e.g., `MapName`, `WithCast`, optionality markers).
- **Known-fragile:** plugin depends on Data's reflection model, not its runtime — internal
  reshapes of `DataClass` are the risk.
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-1, area:plugins

## phpstan/phpdoc-parser
- **Constraint location:** require (`phpstan/phpdoc-parser`)
- **Coupling:** `Support\PhpDoc\DocBlockParser` parses PHPDoc into nodes (`@param`,
  `@return`, `@throws`, `@var`, generics) feeding type resolution.
- **Tripwire:** type/PHPDoc resolution unit tests + ExamplesTest (annotated returns).
- **Diff targets:** (if PHPDoc-derived schemas drift)
  - `Ast\PhpDoc\*` node shapes and `Ast\Type\*` (generic/array/union node structure).
  - `Lexer` / `TypeParser` token or precedence changes.
- **Known-fragile:** v2 node API; structural node renames break the parser silently.
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-0, area:core

## symfony/type-info
- **Constraint location:** require (`symfony/type-info`)
- **Coupling:** `Support\Types\TypeNodeResolver` maps resolved types to OpenAPI schema
  types; relies on `Type` subclass shapes and `TypeResolver`.
- **Tripwire:** type resolution unit tests + ExamplesTest (typed returns/properties).
- **Diff targets:** (if typed-return schemas drift)
  - `Type` hierarchy (`BuiltinType`, `ObjectType`, `CollectionType`, `NullableType`,
    union/intersection) — class/accessor renames.
  - `TypeResolver` / `TypeContext` resolution behaviour.
- **Known-fragile:** symfony 7.x type-info is comparatively young; API still moves between
  minors. Range is `^7.3 || ^8.0`.
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-0, area:core

## spatie/laravel-query-builder
- **Constraint location:** require-dev (`spatie/laravel-query-builder`) — also a `suggest`
- **Coupling:** QueryBuilder plugin documents filter/sort/include query parameters by
  reading the allowed-* definitions.
- **Tripwire:** query-builder examples (`examples:query-builder`) + ExamplesTest.
- **Diff targets:** (if query-param docs drift)
  - `AllowedFilter` / `AllowedSort` / `AllowedInclude` factory + accessor API.
  - `QueryBuilderRequest` parameter-name conventions.
- **Known-fragile:** plugin is opt-in (commented out by default); only matters when enabled.
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-1, area:params

## league/fractal
- **Constraint location:** require-dev (`league/fractal`) — also a `suggest`
- **Coupling:** Fractal plugin documents transformer responses by reading
  `TransformerAbstract` includes/availableIncludes and the serializer shape.
- **Tripwire:** fractal examples (`examples:fractal`) + ExamplesTest.
- **Diff targets:** (if transformer response docs drift)
  - `TransformerAbstract` (availableIncludes / defaultIncludes surface).
  - `Serializer\*` output envelope shape (`DataArraySerializer` etc.).
- **Known-fragile:** plugin opt-in; `spatie/laravel-fractal` is the Laravel bridge layered
  on top — review both together when either bumps.
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-1, area:plugins

## laravel/passport
- **Constraint location:** require-dev (`laravel/passport`) — also a `suggest`
- **Coupling:** Enables OAuth scope-coverage validation in `openapi:lint` — reads
  registered scopes to flag undocumented/uncovered scopes.
- **Tripwire:** lint rule tests covering scope coverage.
- **Diff targets:** (if scope-coverage lint misbehaves)
  - `Passport::scopes()` / scope registry API.
  - Token/scope guard middleware names (we match on middleware).
- **Known-fragile:** opt-in; lint rule only active when Passport is installed.
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-0, area:lint

## nikic/php-parser
- **Constraint location:** require (`nikic/php-parser`)
- **Coupling:** Backs the Tier-1 bounded-AST body scans (inline `validate()`, `abort()`,
  `response()->json()`, `->noContent()`, literal `->setStatusCode()`).
- **Tripwire:** body-scan unit tests + ExamplesTest for scanned idioms.
- **Diff targets:** (if a body scan stops matching)
  - `Node\*` class shapes used by the scanners (Expr\MethodCall, Arg, Scalar nodes).
  - `NodeVisitorAbstract` / traverser API; `ParserFactory` construction.
- **Known-fragile:** v5 API. Node renames break scanners; scanners must degrade gracefully
  per the Tier-1 contract (log + skip, never crash).
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-1, area:core

## opis/json-schema
- **Constraint location:** require (`opis/json-schema`)
- **Coupling:** Validates generated documents at lint time; perf-sensitive (dominates the
  lint hot path per the perf profile).
- **Tripwire:** lint validation tests; ExamplesTest documents must validate.
- **Diff targets:** (if validation verdicts or perf change)
  - `Validator` / `SchemaLoader` API; draft support and keyword handling.
  - Error formatter shape if we surface validation messages.
- **Known-fragile:** validation strictness shifts can newly reject docs we used to emit.
- **Watermark:** Last reviewed: — (initial)
- **Tier/area:** tier-0, area:lint
