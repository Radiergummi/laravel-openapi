# Error Envelope—Design

**Date:** 2026-05-25
**Status:** Approved (brainstorming)
**Purpose:** Make the body shape of standard error responses (the 4xx/5xx responses derived from `@throws` and auth/scope/throttle middleware) configurable via shipped presets, so the generated spec accurately reflects how the host application actually returns errors. Spec-only — the package does not install runtime handlers; it documents whatever shape the host emits.

---

## Goals

- Ship four envelope presets covering the common Laravel surface area: `none` (current bodyless behavior), `laravel` (default `{message, errors?}`), `rfc7807` (problem details), `json-api` (JSON:API errors document).
- Let users select a preset with one flat config key, or plug in a custom resolver class for anything exotic.
- Replace the loose `?list<OA\MediaType>` return type with `?OA\Response`, mirroring `PrimaryResponseResolver` exactly. Resolvers populate content, headers, and examples; the extractor still owns the response key, named-component registration, and default description.
- Unify naming with the existing `PrimaryResponseResolver` convention: resolvers consume a descriptor and return a typed result, chained first-non-null-wins.

## Non-goals

- Changing the host application's exception handler, middleware, or response transformers. This package documents; it does not mutate runtime behavior. Recipes for matching handlers belong in a companion package or docs cookbook, not here.
- Per-status-code preset selection. One preset governs all error responses; the resolver may branch internally on the `ErrorDescriptor` (which carries status + exception class) when its shape varies across statuses.
- Per-spec preset selection. Multi-spec integration can extend this later; first cut is one global setting.
- Customizing the well-known status code → component-response mapping (`Unauthorized`, `Forbidden`, etc.) — that's still owned by `StandardResponsesExtractor`.

## Architecture

Two architectural moves, building on the existing `ErrorResponseFactory` extension point in `src/Core/Registry/`:

1. **Rename and re-shape the existing interface** for consistency with `PrimaryResponseResolver` and to carry richer per-call context.
2. **Ship four built-in resolvers** under `src/Core/Errors/`, selectable via one config key.

### Interface change

```php
namespace Radiergummi\OpenApi\Core\Registry;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Errors\ErrorDescriptor;

interface ErrorResponseResolver
{
    /**
     * Resolve the OpenAPI response object for a standard error response.
     *
     * Resolvers populate `content`, `headers`, and per-media-type `examples`.
     * The response key (`response`), named-component registration
     * (`Unauthorized`, `Forbidden`, ...), and default description are owned by
     * {@see StandardResponsesExtractor} and applied after this method returns —
     * resolvers may set `description` to override.
     *
     * Implementations must catch exceptions internally and return null on
     * failure, so a misbehaving resolver does not abort a full generation run
     * (matching the {@see PrimaryResponseResolver} contract).
     *
     * Branching on `$descriptor->exceptionClass` must use `is_a($cls, X::class, true)`,
     * not strict equality — user code routinely subclasses framework exceptions.
     *
     * Return `null` to pass to the next resolver in the chain; return an
     * `OA\Response` with empty `content` to claim the response with no body.
     */
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?OA\Response;
}
```

Chain semantics, now explicit in the type system:

| Return | Meaning |
|---|---|
| `null` | Resolver passes. Try the next resolver in the chain. |
| `OA\Response` with empty `content` | Resolver claims the response, emits no body. Chain stops. |
| `OA\Response` with populated `content` | Resolver claims the response with body (and optional headers/examples). Chain stops. |

The shape is identical to `PrimaryResponseResolver::resolvePrimaryResponse()`, giving the package a single mental model for response resolution.

### Chain composition

Resolvers are registered through `OpenApiRegistry::addErrorResponseResolver()`. Two registration sources exist:

1. **Plugins** (via `Plugin::register()`) may add resolvers — e.g., a future Passport plugin registering one that handles `MissingScopeException` specifically.
2. **The config-selected envelope** is added last by `OpenApiServiceProvider`.

The chain runs in registration order. The config-selected envelope is therefore the **fallback** — plugins get first refusal on every descriptor and the envelope catches whatever they pass on. This means a user who picks `'laravel'` but installs a plugin with an aggressive resolver may not see the Laravel envelope at all; this is intentional (plugins know their domain best) and documented.

### New value object

```php
namespace Radiergummi\OpenApi\Core\Errors;

final readonly class ErrorDescriptor
{
    public function __construct(
        public int $status,
        /** @var class-string<\Throwable>|null */
        public ?string $exceptionClass,
        public string $description,
    ) {}
}
```

`ErrorDescriptor` mirrors `ActionDescriptor` in naming and intent: a small, immutable view of "what we've inferred about this error response, handed to the resolver." It carries the exception class (the *semantic* origin) alongside the status code (needed for problem details' literal `status` field, JSON:API's per-error `status`, and well-known component-name lookup).

`exceptionClass` is nullable because not every standard response originates from a `@throws`. Middleware-detected responses (auth/scope/throttle) carry their canonical thrown exception via the extended `middlewareMap` config (see below), but third-party middleware mappings users add without an exception class still work — resolvers must defensively fall back to status-based branching when null.

**Resolution rule when multiple `@throws` collapse onto the same status.** When two annotations on the same method map to the same status (e.g., both `@throws ModelNotFoundException` and `@throws AuthorizationException` producing 404 because of a mapping override), the descriptor carries the **first encountered** exception class. The others contribute their descriptions through the existing `byStatus` collation but their semantic identity is lost. Resolvers that need finer-grained behavior should advise users to split the offending route, or use `#[ExceptionResponse]` on the wrapper exception type.

### Extractor change

`StandardResponsesExtractor::extract()` today calls `errorResponseContent()` once per operation and reuses the body for every status. Under this design it loops, with the extractor still owning the response key and merging the resolver's contributions:

```php
foreach ($byStatus as $status => $entry) {
    $descriptor = new ErrorDescriptor(
        status: $status,
        exceptionClass: $entry['exception'] ?? null,
        description: $entry['description'],
    );

    $response = $this->resolveResponse($descriptor);  // walk the chain
    if ($response === null) {
        // Bodyless fallback (today's behavior)
        $response = new OA\Response(['description' => $descriptor->description]);
    } else {
        // Resolver claimed it; backfill description if it left it unset.
        if (!isset($response->description) || $response->description === '') {
            $response->description = $descriptor->description;
        }
    }

    // Existing response-key + named-component registration unchanged:
    $componentName = self::STATUS_COMPONENT_NAMES[$status] ?? null;
    // ... register / inline as today, using $response as the body source
}
```

The existing per-status response-component registration (named `Unauthorized`, `Forbidden`, etc., or inline for unmapped statuses) is unchanged.

### middlewareMap shape

The config block `standard_responses.middleware` grows an `exception` key so middleware-origin responses can fill `ErrorDescriptor::exceptionClass`:

```php
// config/openapi.php — standard_responses block (existing, extended)
'middleware' => [
    'auth' => [
        'status'      => 401,
        'description' => 'Unauthenticated.',
        'exception'   => \Illuminate\Auth\AuthenticationException::class,
    ],
    'scope' => [
        'status'      => 403,
        'description' => 'Insufficient scopes.',
        'exception'   => \Laravel\Passport\Exceptions\MissingScopeException::class,
    ],
    'throttle' => [
        'status'      => 429,
        'description' => 'Too many requests.',
        'exception'   => \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
    ],
],
```

`exception` is optional; if absent, `ErrorDescriptor::exceptionClass` is null and resolvers fall back to status-based branching.

### Built-in resolvers

All four live in `src/Core/Errors/` and implement `ErrorResponseResolver`. Each idempotently registers its component schemas with the injected `ComponentSchemaRegistry` on first use.

| Class | Schemas registered | Media type | Branching logic |
|---|---|---|---|
| `NoneEnvelope` | (none) | — | Returns an `OA\Response` with no `content` for every descriptor (claims, emits bodyless). |
| `LaravelEnvelope` | `Error`, `ValidationError` | `application/json` | Refs `ValidationError` when `$exceptionClass` is `ValidationException` (or status 422 as fallback); refs `Error` otherwise. |
| `Rfc7807Envelope` | `Problem`, `ValidationProblem` | `application/problem+json` | Same split as Laravel; `status` field in the schema constrained to the descriptor's status. |
| `JsonApiEnvelope` | `ErrorDocument` | `application/vnd.api+json` | Single uniform shape for every status. |

Schema names are reserved by the preset; if a user already has a same-named Data class, `ComponentSchemaRegistry`'s existing dedup conflict trips with a clear error (documented caveat — workaround is to switch presets or rename the class).

### Service-provider wiring

```php
// OpenApiServiceProvider::register()
$envelope = config('openapi.error_envelope', 'none');
$resolverClass = match ($envelope) {
    'none'     => NoneEnvelope::class,
    'laravel'  => LaravelEnvelope::class,
    'rfc7807'  => Rfc7807Envelope::class,
    'json-api' => JsonApiEnvelope::class,
    default    => $this->resolveCustomEnvelopeClass($envelope),
};
$registry->addErrorResponseResolver($resolverClass);
```

`resolveCustomEnvelopeClass()` validates the value: it must be a string that names an existing class implementing `ErrorResponseResolver`. On miss it throws `InvalidArgumentException` listing the known preset names — so a typo (`'larvel'`) fails at boot with a clear message, not later with an "autoload failed for 'larvel'" stack trace.

Default is `'none'` — no behavior change on upgrade. Resolvers are resolved through the Laravel container, so built-in and custom resolvers may declare dependencies (e.g., `ComponentSchemaRegistry`) in their constructor.

## User-facing surface

### Config

```php
// config/openapi.php
return [
    // 'none' (default) | 'laravel' | 'rfc7807' | 'json-api' | FQCN of a user-supplied
    // ErrorResponseResolver implementation.
    'error_envelope' => 'laravel',

    // ... existing keys
];
```

### Custom resolvers

Implement the interface; register the FQCN in config:

```php
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Core\Registry\ErrorResponseResolver;

final class MyEnvelope implements ErrorResponseResolver
{
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?OA\Response
    {
        // is_a with allowSubclass — never strict equality on framework exceptions
        if ($descriptor->exceptionClass !== null
            && is_a($descriptor->exceptionClass, MyDomainException::class, true)
        ) {
            return new OA\Response([
                'content' => [/* one or more OA\MediaType */],
                // 'headers' => [...], 'description' => '...', etc. optional
            ]);
        }
        return null;  // defer to next resolver (or to the config-selected envelope)
    }
}
```

Chaining a custom resolver in front of a built-in preset is best done via a plugin: register the custom resolver in `Plugin::register()` and the config-selected envelope will run after it as the fallback. Users without a plugin who want composition write a single resolver that branches internally. A first-class list form in config can be added later without breaking the scalar form.

## File map

**New (production):**
- `src/Core/Errors/ErrorDescriptor.php`
- `src/Core/Errors/NoneEnvelope.php`
- `src/Core/Errors/LaravelEnvelope.php`
- `src/Core/Errors/Rfc7807Envelope.php`
- `src/Core/Errors/JsonApiEnvelope.php`

**Modified (production):**
- `src/Core/Registry/ErrorResponseFactory.php` → renamed to `ErrorResponseResolver.php`; method renamed and re-typed.
- `src/Core/Registry/OpenApiRegistry.php` — `addErrorResponseFactory()` → `addErrorResponseResolver()`; field rename to match.
- `src/Core/Extractors/StandardResponsesExtractor.php` — per-status loop calling the chain with `ErrorDescriptor`; consume `?OA\Response`; backfill `description` when the resolver left it blank.
- `src/OpenApiServiceProvider.php` — resolve config key, register resolver class.
- `config/openapi.php` — add `'error_envelope' => 'none'`; extend `standard_responses.middleware` entries with optional `exception` key.

**New (tests):**
- `tests/Unit/Errors/NoneEnvelopeTest.php`
- `tests/Unit/Errors/LaravelEnvelopeTest.php`
- `tests/Unit/Errors/Rfc7807EnvelopeTest.php`
- `tests/Unit/Errors/JsonApiEnvelopeTest.php`
- `tests/Unit/Registry/ResolverChainSemanticsTest.php` — `null` continues the chain; empty-content `OA\Response` short-circuits with no body; populated `OA\Response` short-circuits with body. Plugin-then-envelope registration order verified.
- `tests/Unit/Errors/SubclassMatchingTest.php` — resolvers correctly match user-defined subclasses of framework exceptions (`is_a($cls, X::class, true)` convention).
- `tests/Unit/OpenApiServiceProviderTest.php` (add cases) — typoed preset name throws `InvalidArgumentException` listing presets; FQCN that doesn't implement `ErrorResponseResolver` throws.
- `tests/Feature/Errors/EnvelopePresetTest.php` — one case per preset over a fixture controller, snapshot-comparing generated YAML.
- `tests/Feature/Errors/DefaultBodylessTest.php` — assert the shipped default (`'none'`) reproduces today's bodyless output.

**Modified (docs):**
- `docs/getting-started.md` — mention `error_envelope` config key in the "what gets derived" section.
- `docs/recipes.md` — new section: "Choosing an error envelope," with one example per preset showing the generated response shape.
- `CHANGELOG.md` — entry under `[Unreleased]`: interface rename + new presets.

## Testing strategy

Standard Pest + Testbench, PHPStan level 8, Pint clean.

- **Per-preset unit tests** assert that for representative `(status, exceptionClass)` pairs, the resolver returns an `OA\Response` with the expected media type and a `$ref` to the right schema. Schema registration is idempotent — calling the resolver twice with the same descriptor does not re-register.
- **Chain semantics** verified by registering ad-hoc resolvers that return `null` / empty-content `OA\Response` / a populated `OA\Response`, asserting the extractor honors each.
- **Integration** snapshots generate a YAML spec from a small fixture (controller with `@throws ModelNotFoundException`, auth middleware, a FormRequest); one snapshot per preset.
- **Default behavior** snapshot verifies `'error_envelope' => 'none'` matches today's bodyless output byte-for-byte, guarding the no-behavior-change-on-upgrade promise.

## Trade-offs and open questions

- **Resolver chaining via config.** The config value selects exactly *one* resolver — there is no list form. Plugins may register additional resolvers; the config-selected envelope runs **last as a fallback** (see "Chain composition" above). Users wanting "custom resolver in front of the Laravel preset" without going through a plugin must write a single resolver that delegates internally. A list form is non-breaking to add later; we wait for demand.
- **Schema name collisions.** `Error`, `Problem`, `ValidationError`, `ValidationProblem`, `ErrorDocument` are short and prone to collide with user-defined Data classes — or worse, with future plugin-registered schemas (a hypothetical JSON:API resource plugin would naturally want an `ErrorDocument` of its own). Two options for first cut: short names (chosen, matches the RFCs verbatim — RFC 7807 *says* the schema is named `Problem`), or namespaced names (`Errors/Problem`). We accept the collision risk for now; the registry catches it with a clear error and a docs note explains the workaround. Revisit if real-world collisions surface.
- **`exceptionClass` nullability.** Required by middleware-origin responses where the user's config might omit the `exception` key, and by `#[ExceptionResponse]` attributes on unmapped statuses. Resolvers must defensively fall back to status-based branching when null. Documented in the `ErrorResponseResolver` interface docblock.
- **Response examples not generated.** Built-in presets register schemas but no canonical `examples` on the media type. RFC 7807 and JSON:API both benefit greatly from worked examples (`{"type": "about:blank", "title": "Not Found", "status": 404, ...}`). Easy follow-up since presets already build the `OA\MediaType` — add examples per preset. Out of scope for the first cut to keep the surface small.
- **`OA\Response` carries headers natively.** No need for a separate slot — presets that want `Retry-After` on 429 (problem details + `ThrottleRequestsException`) populate `$response->headers` directly. Forward-compatible for any future header-bearing convention.
- **Per-operation context not exposed to resolvers.** `ErrorDescriptor` carries status + exception + description only. A resolver that wants to customize errors per controller, tag, or route (e.g., "admin endpoints use a different envelope") cannot today. This is a **deliberate scoping choice** — exposing the full `ActionDescriptor` would couple error resolution to unrelated route data. Forward-compatible: add an optional `ActionDescriptor` field on `ErrorDescriptor` later without breaking existing resolvers.
- **Multi-spec interaction.** Per-spec envelope selection is out of scope for this design; when multi-spec ships, the natural extension is to allow `error_envelope` inside each `specs.<name>` block, falling back to the root key.
