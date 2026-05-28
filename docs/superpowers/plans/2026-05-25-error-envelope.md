# Error Envelope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the body shape of standard error responses (the 4xx/5xx responses derived from `@throws` and auth/scope/throttle middleware) configurable via shipped presets, so the generated spec accurately reflects how the host application actually returns errors.

**Architecture:** Build on the existing `ErrorResponseFactory` extension point. Rename to `ErrorResponseResolver`, change the signature to take an `ErrorDescriptor` (status + exception class + description) and return a focused `?ErrorResponse` value object (content + headers + links + optional description). Ship four built-in resolvers under `src/Core/Errors/`: `NoneEnvelope` (current bodyless), `LaravelEnvelope`, `Rfc7807Envelope`, `JsonApiEnvelope`. Wire via one flat config key `error_envelope`.

**Tech Stack:** PHP 8.4 strict-typed, Laravel 12/13, Pest (Testbench), Larastan level 8, swagger-php (OpenAPI 3.1).

**Spec:** `docs/superpowers/specs/2026-05-25-error-envelope-design.md` — implementation MUST match the design verbatim. When the plan and spec disagree, the spec wins; revise the plan rather than drift.

---

## File map

**New (production):**
- `src/Core/Errors/ErrorDescriptor.php` — immutable value object: status + exception class + description
- `src/Core/Errors/ErrorResponse.php` — immutable value object: content + headers + links + optional description
- `src/Core/Errors/NoneEnvelope.php` — bodyless preset
- `src/Core/Errors/LaravelEnvelope.php` — `{message, errors?}`
- `src/Core/Errors/Rfc7807Envelope.php` — problem details
- `src/Core/Errors/JsonApiEnvelope.php` — JSON:API errors

**New (tests):**
- `tests/Unit/Errors/ErrorDescriptorTest.php`
- `tests/Unit/Errors/ErrorResponseTest.php`
- `tests/Unit/Errors/NoneEnvelopeTest.php`
- `tests/Unit/Errors/LaravelEnvelopeTest.php`
- `tests/Unit/Errors/Rfc7807EnvelopeTest.php`
- `tests/Unit/Errors/JsonApiEnvelopeTest.php`
- `tests/Unit/Errors/SubclassMatchingTest.php`
- `tests/Unit/Extractors/ErrorResolverChainTest.php`
- `tests/Feature/Errors/EnvelopePresetSnapshotTest.php`
- `tests/Feature/Errors/DefaultBodylessTest.php`
- `tests/Fixtures/Errors/ErrorEnvelopeFixtureController.php`

**Modified (production):**
- `src/Core/Registry/ErrorResponseFactory.php` → renamed to `ErrorResponseResolver.php`; method renamed; new signature.
- `src/Core/Registry/OpenApiRegistry.php` — rename `addErrorResponseFactory()` → `addErrorResponseResolver()`; rename field; update accessor.
- `src/Core/Extraction/StandardResponsesExtractor.php` — accept resolvers under new name; per-status loop calling chain with `ErrorDescriptor`; new private `buildResponse()` helper.
- `src/OpenApiServiceProvider.php` — extractor binding uses new accessor; new method `resolveErrorEnvelopeClass()`; register the resolved class in the registry.
- `config/openapi.php` — add `'error_envelope' => 'none'` at the root; add optional `exception` key to each `middleware_responses` entry.

**Modified (docs):**
- `docs/getting-started.md` — mention `error_envelope` in the "what gets derived" section.
- `docs/recipes.md` — new section "Choosing an error envelope" with one example per preset showing the generated YAML.
- `CHANGELOG.md` — entry under `[Unreleased]`.

---

## Task 1: `ErrorDescriptor` value object

**Files:**
- Create: `src/Core/Errors/ErrorDescriptor.php`
- Test: `tests/Unit/Errors/ErrorDescriptorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Errors/ErrorDescriptorTest.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Validation\ValidationException;use Radiergummi\OpenApi\Errors\ErrorDescriptor;

uses()->group('openapi');

it('exposes status, exception class, and description', function (): void {
    $descriptor = new ErrorDescriptor(
        status: 422,
        exceptionClass: ValidationException::class,
        description: 'Validation failed',
    );

    expect($descriptor->status)->toBe(422);
    expect($descriptor->exceptionClass)->toBe(ValidationException::class);
    expect($descriptor->description)->toBe('Validation failed');
});

it('accepts a null exception class', function (): void {
    $descriptor = new ErrorDescriptor(
        status: 401,
        exceptionClass: null,
        description: 'Unauthenticated',
    );

    expect($descriptor->exceptionClass)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Errors/ErrorDescriptorTest.php`

Expected: `Class "Radiergummi\OpenApi\Core\Envelopes\ErrorDescriptor" not found`.

- [ ] **Step 3: Create the class**

Create `src/Core/Errors/ErrorDescriptor.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Errors;

use Throwable;

/**
 * A small immutable view of "what we've inferred about this error response, handed to the
 * resolver."
 *
 * Carries the exception class (the semantic origin) alongside the status code (needed for
 * problem details' literal `status` field, JSON:API's per-error `status`, and well-known
 * component-name lookup). `exceptionClass` is nullable because not every standard response
 * originates from a `@throws` — middleware-detected responses (auth/scope/throttle) carry
 * their canonical thrown exception via the extended middleware-responses config, but
 * third-party middleware mappings users add without an exception class still work.
 *
 * Resolvers branching on `$exceptionClass` must use `is_a($cls, X::class, true)`, not strict
 * equality — user code routinely subclasses framework exceptions.
 */
final readonly class ErrorDescriptor
{
    /**
     * @param class-string<Throwable>|null $exceptionClass
     */
    public function __construct(
        public int $status,
        public ?string $exceptionClass,
        public string $description,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Errors/ErrorDescriptorTest.php`

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Errors/ErrorDescriptor.php tests/Unit/Errors/ErrorDescriptorTest.php
git commit -m "feat(errors): add ErrorDescriptor value object"
```

---

## Task 2: `ErrorResponse` value object

**Files:**
- Create: `src/Core/Errors/ErrorResponse.php`
- Test: `tests/Unit/Errors/ErrorResponseTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Errors/ErrorResponseTest.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Errors\ErrorResponse;

uses()->group('openapi');

it('defaults to empty content, headers, links, and null description', function (): void {
    $response = new ErrorResponse();

    expect($response->content)->toBe([]);
    expect($response->headers)->toBe([]);
    expect($response->links)->toBe([]);
    expect($response->description)->toBeNull();
});

it('bodyless() returns an instance with no body slots populated', function (): void {
    $response = ErrorResponse::bodyless();

    expect($response->content)->toBe([]);
    expect($response->headers)->toBe([]);
    expect($response->links)->toBe([]);
});

it('accepts named-argument construction', function (): void {
    $media = new OA\MediaType(['mediaType' => 'application/json']);
    $response = new ErrorResponse(
        content: [$media],
        description: 'Not found',
    );

    expect($response->content)->toBe([$media]);
    expect($response->description)->toBe('Not found');
    expect($response->headers)->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Errors/ErrorResponseTest.php`

Expected: `Class "Radiergummi\OpenApi\Core\Envelopes\ErrorResponse" not found`.

- [ ] **Step 3: Create the class**

Create `src/Core/Errors/ErrorResponse.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Errors;

use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;

/**
 * The body slice of an error response, produced by an {@see ErrorResponseResolver}.
 *
 * Carries only what a resolver can produce: media-type contents, response headers, links, and
 * an optional description that overrides the extractor's default. The response key, named-
 * component registration, and default description are owned by
 * {@see \Radiergummi\OpenApi\Core\Extraction\StandardResponsesExtractor} — there is
 * intentionally no slot for them on this type.
 */
final readonly class ErrorResponse
{
    /**
     * @param list<OA\MediaType> $content
     * @param list<OA\Header>    $headers
     * @param list<OA\Link>      $links
     */
    public function __construct(
        public array $content = [],
        public array $headers = [],
        public array $links = [],
        public ?string $description = null,
    ) {}

    /**
     * Claim the response with no body.
     */
    public static function bodyless(): self
    {
        return new self();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Errors/ErrorResponseTest.php`

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Errors/ErrorResponse.php tests/Unit/Errors/ErrorResponseTest.php
git commit -m "feat(errors): add ErrorResponse value object"
```

---

## Task 3: Rename interface and update registry + extractor

This task changes the existing extension point and its sole in-package consumer atomically, so the suite stays green.

**Files:**
- Rename: `src/Core/Registry/ErrorResponseFactory.php` → `src/Core/Registry/ErrorResponseResolver.php`
- Modify: `src/Core/Registry/OpenApiRegistry.php`
- Modify: `src/Core/Extraction/StandardResponsesExtractor.php`
- Modify: `src/OpenApiServiceProvider.php` (only the call site of `errorResponseFactories()` in the extractor binding)

- [ ] **Step 1: Write the new interface**

Delete the old file and create `src/Core/Registry/ErrorResponseResolver.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Registry;

use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;use Radiergummi\OpenApi\Core\Extraction\StandardResponsesExtractor;use Radiergummi\OpenApi\Errors\ErrorDescriptor;use Radiergummi\OpenApi\Errors\ErrorResponse;

/**
 * Resolves the body of a standard error response — the 4xx/5xx responses derived from
 * `@throws` annotations and auth/scope/throttle middleware.
 *
 * Implementations are consulted in registration order; the first non-null result wins.
 * Return {@see ErrorResponse::bodyless()} to claim the response while emitting no body.
 *
 * The response key (`response`), named-component registration (`Unauthorized`, `Forbidden`,
 * ...), and default description are owned by {@see StandardResponsesExtractor}. The returned
 * {@see ErrorResponse} carries only the body slice — content, headers, links, and an
 * optional description override — that's why the type intentionally lacks a response-key
 * field.
 *
 * Implementations must catch exceptions internally and return null on failure, so a
 * misbehaving resolver does not abort a full generation run (matching the
 * {@see PrimaryResponseResolver} contract).
 *
 * Branching on `$descriptor->exceptionClass` must use `is_a($cls, X::class, true)`, not
 * strict equality — user code routinely subclasses framework exceptions.
 */
interface ErrorResponseResolver
{
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse;
}
```

Delete `src/Core/Registry/ErrorResponseFactory.php`.

- [ ] **Step 2: Update `OpenApiRegistry` — rename field, mutator, accessor**

In `src/Core/Registry/OpenApiRegistry.php`:

Replace the `use` line:
```php
use Radiergummi\OpenApi\Core\Registry\ErrorResponseFactory;
```
with:

```php

```

Rename the property (around line 55):
```php
/**
 * @var list<class-string<ErrorResponseResolver>>
 */
private array $errorResponseResolvers = [];
```

Rename the mutator (around line 114-122):
```php
/**
 * @param class-string<ErrorResponseResolver> $class
 */
public function addErrorResponseResolver(string $class): void
{
    if (!in_array($class, $this->errorResponseResolvers, strict: true)) {
        $this->errorResponseResolvers[] = $class;
    }
}
```

Rename the accessor (around line 193-198):
```php
/**
 * @return list<class-string<ErrorResponseResolver>>
 */
public function errorResponseResolvers(): array
{
    return $this->errorResponseResolvers;
}
```

Delete the old `addErrorResponseFactory()` and `errorResponseFactories()` methods. Replace any other references in the file (`grep "errorResponseFactor" src/Core/Registry/OpenApiRegistry.php` should return nothing after this step).

- [ ] **Step 3: Update `StandardResponsesExtractor`**

In `src/Core/Extraction/StandardResponsesExtractor.php`:

Update imports:

```php


```
(Remove the `ErrorResponseFactory` import.)

Update the constructor PHPDoc and parameter (around line 74-85):
```php
/**
 * @param list<ErrorResponseResolver>                            $errorResponseResolvers
 * @param array<string, array{status: int, description: string}> $exceptionMap
 * @param array<string, array{status: int, description: string, exception?: class-string<\Throwable>}> $middlewareMap
 */
public function __construct(
    private ComponentSchemaRegistry $registry,
    private FindingsCollector $findings,
    private array $errorResponseResolvers = [],
    private array $exceptionMap = [],
    private array $middlewareMap = [],
) {}
```

Replace the body of `extract()` from the `if ($byStatus === []) { return []; }` block downward (lines 148-186). The old code calls `errorResponseContent()` once and uses `makeErrorResponse()`. Replace with per-status chain calls and a `buildResponse()` helper. Final shape of the loop:

```php
if ($byStatus === []) {
    return [];
}

ksort($byStatus);

$responses = [];

foreach ($byStatus as $status => $entry) {
    $exceptionClass = $entry['exception'] ?? null;

    $descriptor = new ErrorDescriptor(
        status: $status,
        exceptionClass: $exceptionClass,
        description: (string) $entry['description'],
    );

    $body = $this->resolveBody($descriptor);

    $componentName = self::STATUS_COMPONENT_NAMES[$status] ?? null;

    $responses[] = $this->buildResponse($descriptor, $body, $componentName);
}

return $responses;
```

Now adjust `$byStatus`'s shape. The `addOnce()` helper currently stores only `['description' => …]`; it must also carry the exception class. Modify the PHPDoc and assignments:

In the loop near the top of `extract()` (around line 129-131), change:
```php
if (!array_key_exists($status, $byStatus)) {
    $byStatus[$status] = ['description' => (string) $entry['description']];
}
```
to:
```php
if (!array_key_exists($status, $byStatus)) {
    $byStatus[$status] = [
        'description' => (string) $entry['description'],
        'exception'   => $throw, // class-string<\Throwable>
    ];
}
```

Update `addOnce()` (around line 296-308) to optionally store the `exception`:
```php
/**
 * @param array<int, array{description: string, exception?: class-string<\Throwable>}> $byStatus
 * @param array{status: int, description: string, exception?: class-string<\Throwable>} $entry
 */
private function addOnce(array &$byStatus, array $entry): void
{
    $status = (int) $entry['status'];

    if (array_key_exists($status, $byStatus)) {
        return;
    }

    $stored = ['description' => (string) $entry['description']];
    if (isset($entry['exception'])) {
        $stored['exception'] = $entry['exception'];
    }
    $byStatus[$status] = $stored;
}
```

Update the `@var` annotation on `$byStatus` (line 94) to reflect the new shape:
```php
/** @var array<int, array{description: string, exception?: class-string<\Throwable>}> $byStatus */
```

Replace `errorResponseContent()` (lines 334-353) and `makeErrorResponse()` (lines 355-373) with:

```php
/**
 * Walks the resolver chain for one descriptor. First non-null wins. Returns null when
 * every resolver passes — the extractor then emits a bodyless response.
 */
private function resolveBody(ErrorDescriptor $descriptor): ?ErrorResponse
{
    foreach ($this->errorResponseResolvers as $resolver) {
        $body = $resolver->resolveErrorResponse($descriptor);

        if ($body !== null) {
            return $body;
        }
    }

    return null;
}

/**
 * Composes the resolver's body slice with the extractor-owned fields: response key,
 * default description, named-component registration.
 */
private function buildResponse(
    ErrorDescriptor $descriptor,
    ?ErrorResponse $body,
    ?string $componentName,
): OA\Response {
    $description = $body?->description !== null && $body->description !== ''
        ? $body->description
        : $descriptor->description;

    $properties = [
        'response'    => $componentName ?? (string) $descriptor->status,
        'description' => $description,
    ];

    if ($body !== null && $body->content !== []) {
        $properties['content'] = $body->content;
    }
    if ($body !== null && $body->headers !== []) {
        $properties['headers'] = $body->headers;
    }
    if ($body !== null && $body->links !== []) {
        $properties['links'] = $body->links;
    }

    if ($componentName !== null) {
        $this->registry->registerNamedResponse(
            $componentName,
            new OA\Response($properties),
        );

        return new OA\Response([
            'response' => (string) $descriptor->status,
            'ref'      => $this->registry->qualifyKey($componentName, ComponentType::Responses),
        ]);
    }

    return new OA\Response($properties);
}
```

- [ ] **Step 4: Update service-provider binding to use the new accessor**

In `src/OpenApiServiceProvider.php` around line 237-248, change:
```php
errorResponseFactories: array_map(
    static fn(string $class) => $app->make($class),
    $registry->errorResponseFactories(),
),
```
to:
```php
errorResponseResolvers: array_map(
    static fn(string $class) => $app->make($class),
    $registry->errorResponseResolvers(),
),
```

- [ ] **Step 5: Run the full suite to verify the rename is internally consistent**

Run: `composer test`

Expected: PASS — no test today asserts on the old factory name, so the suite stays green. If any test fails referencing `errorResponseFactor*`, the rename missed a call site.

- [ ] **Step 6: Run static analysis and style**

Run: `composer analyse && composer lint`

Expected: PHPStan reports no errors. Pint reports no style violations.

- [ ] **Step 7: Commit**

```bash
git add -A src/Core/Registry/ src/Core/Extractors/StandardResponsesExtractor.php src/OpenApiServiceProvider.php
git commit -m "refactor(errors): rename ErrorResponseFactory to ErrorResponseResolver

Switch the interface signature to resolveErrorResponse(ErrorDescriptor): ?ErrorResponse.
Per-status loop in StandardResponsesExtractor; new buildResponse() helper composes the
resolver's body slice with the extractor-owned response key and default description."
```

---

## Task 4: Extend `middleware_responses` config with optional `exception` key

**Files:**
- Modify: `config/openapi.php`

- [ ] **Step 1: Update the config block**

In `config/openapi.php` around line 132-136, replace:
```php
'middleware_responses' => [
    'auth' => ['status' => 401, 'description' => 'Unauthenticated'],
    'scope' => ['status' => 403, 'description' => 'Insufficient scope'],
    'throttle' => ['status' => 429, 'description' => 'Too many requests'],
],
```
with:
```php
'middleware_responses' => [
    'auth' => [
        'status'      => 401,
        'description' => 'Unauthenticated',
        'exception'   => AuthenticationException::class,
    ],
    'scope' => [
        'status'      => 403,
        'description' => 'Insufficient scope',
        // No canonical scope exception ships with Laravel core; Passport's
        // MissingScopeException is the conventional choice when Passport is installed.
        // 'exception' => \Laravel\Passport\Exceptions\MissingScopeException::class,
    ],
    'throttle' => [
        'status'      => 429,
        'description' => 'Too many requests',
        'exception'   => ThrottleRequestsException::class,
    ],
],
```

(The `AuthenticationException` and `ThrottleRequestsException` imports already exist at the top of `config/openapi.php` — verify with `head -20 config/openapi.php`.)

- [ ] **Step 2: Run the suite to confirm no regression**

Run: `composer test`

Expected: PASS — the extractor reads `exception` defensively via `$entry['exception'] ?? null` (added in Task 3), so the new keys are picked up without any code change.

- [ ] **Step 3: Commit**

```bash
git add config/openapi.php
git commit -m "feat(config): add optional 'exception' key to middleware_responses

Carries the canonical thrown exception per middleware so error resolvers can
branch on exception class for middleware-originating responses, not only status."
```

---

## Task 5: `NoneEnvelope` preset

**Files:**
- Create: `src/Core/Errors/NoneEnvelope.php`
- Test: `tests/Unit/Errors/NoneEnvelopeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Errors/NoneEnvelopeTest.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;use Illuminate\Validation\ValidationException;use Radiergummi\OpenApi\Core\Envelopes\NoneEnvelope;use Radiergummi\OpenApi\Errors\ErrorDescriptor;use Radiergummi\OpenApi\Errors\ErrorResponse;

uses()->group('openapi');

it('returns a bodyless ErrorResponse for every descriptor', function (): void {
    $envelope = new NoneEnvelope();

    $cases = [
        new ErrorDescriptor(status: 401, exceptionClass: AuthenticationException::class, description: 'Unauthenticated'),
        new ErrorDescriptor(status: 422, exceptionClass: ValidationException::class, description: 'Validation failed'),
        new ErrorDescriptor(status: 500, exceptionClass: null, description: 'Server error'),
    ];

    foreach ($cases as $descriptor) {
        $response = $envelope->resolveErrorResponse($descriptor);

        expect($response)->toBeInstanceOf(ErrorResponse::class);
        expect($response->content)->toBe([]);
        expect($response->headers)->toBe([]);
        expect($response->links)->toBe([]);
        expect($response->description)->toBeNull();
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Errors/NoneEnvelopeTest.php`

Expected: `Class "Radiergummi\OpenApi\Core\Envelopes\NoneEnvelope" not found`.

- [ ] **Step 3: Create the class**

Create `src/Core/Errors/NoneEnvelope.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Errors;

use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;use Radiergummi\OpenApi\Errors\ErrorDescriptor;use Radiergummi\OpenApi\Errors\ErrorResponse;

/**
 * The explicit "no body" preset — claims every standard error response without emitting
 * content. Selected via `config('openapi.error_envelope') = 'none'` (the package default,
 * preserving today's bodyless behavior).
 */
final readonly class NoneEnvelope implements ErrorResponseResolver
{
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
    {
        return ErrorResponse::bodyless();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Errors/NoneEnvelopeTest.php`

Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Errors/NoneEnvelope.php tests/Unit/Errors/NoneEnvelopeTest.php
git commit -m "feat(errors): add NoneEnvelope bodyless preset"
```

---

## Task 6: `LaravelEnvelope` preset

`Error { message: string }` for generic errors; `ValidationError { message: string, errors: object<string, array<string>> }` for `ValidationException` (or status 422 fallback).

**Files:**
- Create: `src/Core/Errors/LaravelEnvelope.php`
- Test: `tests/Unit/Errors/LaravelEnvelopeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Errors/LaravelEnvelopeTest.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;use Illuminate\Validation\ValidationException;use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Core\Envelopes\LaravelEnvelope;use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;use Radiergummi\OpenApi\Errors\ErrorDescriptor;

uses()->group('openapi');

it('refs the Error schema for non-validation responses', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 401,
        exceptionClass: AuthenticationException::class,
        description: 'Unauthenticated',
    ));

    expect($response->content)->toHaveCount(1);
    $media = $response->content[0];
    expect($media)->toBeInstanceOf(OA\MediaType::class);
    expect($media->mediaType)->toBe('application/json');
    expect($media->schema->ref)->toBe($registry->qualifyKey('Error'));
});

it('refs the ValidationError schema when the exception is ValidationException', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 422,
        exceptionClass: ValidationException::class,
        description: 'Validation failed',
    ));

    expect($response->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationError'));
});

it('falls back to status 422 when no exception class is set', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 422,
        exceptionClass: null,
        description: 'Validation failed',
    ));

    expect($response->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationError'));
});

it('registers both Error and ValidationError component schemas idempotently', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $envelope->resolveErrorResponse(new ErrorDescriptor(401, AuthenticationException::class, 'Unauthenticated'));
    $envelope->resolveErrorResponse(new ErrorDescriptor(401, AuthenticationException::class, 'Unauthenticated'));
    $envelope->resolveErrorResponse(new ErrorDescriptor(422, ValidationException::class, 'Validation failed'));

    expect($registry->hasKey('Error'))->toBeTrue();
    expect($registry->hasKey('ValidationError'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Errors/LaravelEnvelopeTest.php`

Expected: `Class "Radiergummi\OpenApi\Core\Envelopes\LaravelEnvelope" not found`.

- [ ] **Step 3: Create the class**

Create `src/Core/Errors/LaravelEnvelope.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Errors;

use Illuminate\Validation\ValidationException;use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;use Radiergummi\OpenApi\Errors\ErrorDescriptor;use Radiergummi\OpenApi\Errors\ErrorResponse;

/**
 * Laravel's default JSON error envelope:
 *  - `{ message: string }` for generic 4xx/5xx responses.
 *  - `{ message: string, errors: { <field>: string[] } }` for ValidationException / 422.
 *
 * Media type: `application/json`. Selected via
 * `config('openapi.error_envelope') = 'laravel'`.
 */
final readonly class LaravelEnvelope implements ErrorResponseResolver
{
    public function __construct(
        private ComponentSchemaRegistry $registry,
    ) {}

    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
    {
        $isValidation = $this->isValidation($descriptor);

        $this->registerSchemas();

        $schemaKey = $isValidation ? 'ValidationError' : 'Error';

        $media = new OA\MediaType([
            'mediaType' => 'application/json',
            'schema'    => new OA\Schema(['ref' => $this->registry->qualifyKey($schemaKey)]),
        ]);

        return new ErrorResponse(content: [$media]);
    }

    private function isValidation(ErrorDescriptor $descriptor): bool
    {
        if ($descriptor->exceptionClass !== null
            && is_a($descriptor->exceptionClass, ValidationException::class, true)
        ) {
            return true;
        }

        return $descriptor->exceptionClass === null && $descriptor->status === 422;
    }

    private function registerSchemas(): void
    {
        if (!$this->registry->hasKey('Error')) {
            $this->registry->registerNamed('Error', new OA\Schema([
                'schema'     => 'Error',
                'type'       => 'object',
                'required'   => ['message'],
                'properties' => [
                    new OA\Property(['property' => 'message', 'type' => 'string']),
                ],
            ]));
        }

        if (!$this->registry->hasKey('ValidationError')) {
            $this->registry->registerNamed('ValidationError', new OA\Schema([
                'schema'     => 'ValidationError',
                'type'       => 'object',
                'required'   => ['message', 'errors'],
                'properties' => [
                    new OA\Property(['property' => 'message', 'type' => 'string']),
                    new OA\Property([
                        'property'             => 'errors',
                        'type'                 => 'object',
                        'additionalProperties' => new OA\AdditionalProperties([
                            'type'  => 'array',
                            'items' => new OA\Items(['type' => 'string']),
                        ]),
                    ]),
                ],
            ]));
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Errors/LaravelEnvelopeTest.php`

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Errors/LaravelEnvelope.php tests/Unit/Errors/LaravelEnvelopeTest.php
git commit -m "feat(errors): add LaravelEnvelope preset"
```

---

## Task 7: `Rfc7807Envelope` preset

Same Validation/generic split as Laravel, but with the problem-details fields (`type`, `title`, `status`, `detail`, `instance`, plus `errors` for validation). Media type: `application/problem+json`.

**Files:**
- Create: `src/Core/Errors/Rfc7807Envelope.php`
- Test: `tests/Unit/Errors/Rfc7807EnvelopeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Errors/Rfc7807EnvelopeTest.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;use Illuminate\Validation\ValidationException;use Radiergummi\OpenApi\Core\Envelopes\Rfc7807Envelope;use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;use Radiergummi\OpenApi\Errors\ErrorDescriptor;

uses()->group('openapi');

it('refs the Problem schema for non-validation responses with application/problem+json', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new Rfc7807Envelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 404,
        exceptionClass: ModelNotFoundException::class,
        description: 'Resource not found',
    ));

    $media = $response->content[0];
    expect($media->mediaType)->toBe('application/problem+json');
    expect($media->schema->ref)->toBe($registry->qualifyKey('Problem'));
});

it('refs ValidationProblem for ValidationException', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new Rfc7807Envelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 422,
        exceptionClass: ValidationException::class,
        description: 'Validation failed',
    ));

    expect($response->content[0]->schema->ref)
        ->toBe($registry->qualifyKey('ValidationProblem'));
});

it('registers both Problem and ValidationProblem component schemas', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new Rfc7807Envelope($registry);

    $envelope->resolveErrorResponse(new ErrorDescriptor(404, ModelNotFoundException::class, 'Not found'));
    $envelope->resolveErrorResponse(new ErrorDescriptor(422, ValidationException::class, 'Validation failed'));

    expect($registry->hasKey('Problem'))->toBeTrue();
    expect($registry->hasKey('ValidationProblem'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Errors/Rfc7807EnvelopeTest.php`

Expected: `Class "Radiergummi\OpenApi\Core\Envelopes\Rfc7807Envelope" not found`.

- [ ] **Step 3: Create the class**

Create `src/Core/Errors/Rfc7807Envelope.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Errors;

use Illuminate\Validation\ValidationException;use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;use Radiergummi\OpenApi\Errors\ErrorDescriptor;use Radiergummi\OpenApi\Errors\ErrorResponse;

/**
 * RFC 7807 problem-details envelope. Media type `application/problem+json`.
 *
 * Selected via `config('openapi.error_envelope') = 'rfc7807'`. Registers two schemas:
 * `Problem` (generic) and `ValidationProblem` (adds the `errors` extension field).
 */
final readonly class Rfc7807Envelope implements ErrorResponseResolver
{
    public function __construct(
        private ComponentSchemaRegistry $registry,
    ) {}

    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
    {
        $isValidation = $descriptor->exceptionClass !== null
            && is_a($descriptor->exceptionClass, ValidationException::class, true)
            || ($descriptor->exceptionClass === null && $descriptor->status === 422);

        $this->registerSchemas();

        $schemaKey = $isValidation ? 'ValidationProblem' : 'Problem';

        $media = new OA\MediaType([
            'mediaType' => 'application/problem+json',
            'schema'    => new OA\Schema(['ref' => $this->registry->qualifyKey($schemaKey)]),
        ]);

        return new ErrorResponse(content: [$media]);
    }

    private function registerSchemas(): void
    {
        if (!$this->registry->hasKey('Problem')) {
            $this->registry->registerNamed('Problem', new OA\Schema([
                'schema'     => 'Problem',
                'type'       => 'object',
                'description' => 'RFC 7807 problem details object.',
                'properties' => [
                    new OA\Property(['property' => 'type', 'type' => 'string', 'format' => 'uri']),
                    new OA\Property(['property' => 'title', 'type' => 'string']),
                    new OA\Property(['property' => 'status', 'type' => 'integer']),
                    new OA\Property(['property' => 'detail', 'type' => 'string']),
                    new OA\Property(['property' => 'instance', 'type' => 'string', 'format' => 'uri']),
                ],
            ]));
        }

        if (!$this->registry->hasKey('ValidationProblem')) {
            $this->registry->registerNamed('ValidationProblem', new OA\Schema([
                'schema'     => 'ValidationProblem',
                'type'       => 'object',
                'description' => 'RFC 7807 problem details with a per-field errors extension.',
                'properties' => [
                    new OA\Property(['property' => 'type', 'type' => 'string', 'format' => 'uri']),
                    new OA\Property(['property' => 'title', 'type' => 'string']),
                    new OA\Property(['property' => 'status', 'type' => 'integer']),
                    new OA\Property(['property' => 'detail', 'type' => 'string']),
                    new OA\Property(['property' => 'instance', 'type' => 'string', 'format' => 'uri']),
                    new OA\Property([
                        'property'             => 'errors',
                        'type'                 => 'object',
                        'additionalProperties' => new OA\AdditionalProperties([
                            'type'  => 'array',
                            'items' => new OA\Items(['type' => 'string']),
                        ]),
                    ]),
                ],
            ]));
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Errors/Rfc7807EnvelopeTest.php`

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Errors/Rfc7807Envelope.php tests/Unit/Errors/Rfc7807EnvelopeTest.php
git commit -m "feat(errors): add Rfc7807Envelope preset"
```

---

## Task 8: `JsonApiEnvelope` preset

Single uniform `ErrorDocument` schema for every status, mapped to media type `application/vnd.api+json`.

**Files:**
- Create: `src/Core/Errors/JsonApiEnvelope.php`
- Test: `tests/Unit/Errors/JsonApiEnvelopeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Errors/JsonApiEnvelopeTest.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;use Illuminate\Validation\ValidationException;use Radiergummi\OpenApi\Core\Envelopes\JsonApiEnvelope;use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;use Radiergummi\OpenApi\Errors\ErrorDescriptor;

uses()->group('openapi');

it('refs ErrorDocument with application/vnd.api+json for every status', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new JsonApiEnvelope($registry);

    $cases = [
        new ErrorDescriptor(401, AuthenticationException::class, 'Unauthenticated'),
        new ErrorDescriptor(422, ValidationException::class, 'Validation failed'),
        new ErrorDescriptor(500, null, 'Server error'),
    ];

    foreach ($cases as $descriptor) {
        $response = $envelope->resolveErrorResponse($descriptor);
        $media = $response->content[0];
        expect($media->mediaType)->toBe('application/vnd.api+json');
        expect($media->schema->ref)->toBe($registry->qualifyKey('ErrorDocument'));
    }
});

it('registers the ErrorDocument schema', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new JsonApiEnvelope($registry);

    $envelope->resolveErrorResponse(new ErrorDescriptor(500, null, 'Server error'));

    expect($registry->hasKey('ErrorDocument'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Errors/JsonApiEnvelopeTest.php`

Expected: `Class "Radiergummi\OpenApi\Core\Envelopes\JsonApiEnvelope" not found`.

- [ ] **Step 3: Create the class**

Create `src/Core/Errors/JsonApiEnvelope.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Errors;

use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;use Radiergummi\OpenApi\Errors\ErrorDescriptor;use Radiergummi\OpenApi\Errors\ErrorResponse;

/**
 * JSON:API errors document. Media type `application/vnd.api+json`. The shape is uniform
 * across every status code: `{ errors: [ { status, title, detail, source?: { pointer } } ] }`.
 *
 * Selected via `config('openapi.error_envelope') = 'json-api'`.
 */
final readonly class JsonApiEnvelope implements ErrorResponseResolver
{
    public function __construct(
        private ComponentSchemaRegistry $registry,
    ) {}

    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
    {
        $this->registerSchema();

        $media = new OA\MediaType([
            'mediaType' => 'application/vnd.api+json',
            'schema'    => new OA\Schema(['ref' => $this->registry->qualifyKey('ErrorDocument')]),
        ]);

        return new ErrorResponse(content: [$media]);
    }

    private function registerSchema(): void
    {
        if ($this->registry->hasKey('ErrorDocument')) {
            return;
        }

        $errorItem = new OA\Items([
            'type'       => 'object',
            'properties' => [
                new OA\Property(['property' => 'status', 'type' => 'string']),
                new OA\Property(['property' => 'title', 'type' => 'string']),
                new OA\Property(['property' => 'detail', 'type' => 'string']),
                new OA\Property([
                    'property'   => 'source',
                    'type'       => 'object',
                    'properties' => [
                        new OA\Property(['property' => 'pointer', 'type' => 'string']),
                        new OA\Property(['property' => 'parameter', 'type' => 'string']),
                    ],
                ]),
            ],
        ]);

        $this->registry->registerNamed('ErrorDocument', new OA\Schema([
            'schema'     => 'ErrorDocument',
            'type'       => 'object',
            'description' => 'JSON:API errors document.',
            'required'   => ['errors'],
            'properties' => [
                new OA\Property([
                    'property' => 'errors',
                    'type'     => 'array',
                    'items'    => $errorItem,
                ]),
            ],
        ]));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Errors/JsonApiEnvelopeTest.php`

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Errors/JsonApiEnvelope.php tests/Unit/Errors/JsonApiEnvelopeTest.php
git commit -m "feat(errors): add JsonApiEnvelope preset"
```

---

## Task 9: Service-provider wiring for `error_envelope` config

**Files:**
- Modify: `src/OpenApiServiceProvider.php`
- Modify (existing test, add cases): `tests/Unit/OpenApiServiceProviderTest.php`

- [ ] **Step 1: Read the relevant context**

Look at where plugins and registry registrations live in `src/OpenApiServiceProvider.php`. Look for the method that runs after `CoreRegistration::register()` and before plugin registration (typically in `register()` or a dedicated method). The new wiring belongs after `CoreRegistration::register()` and **after** plugin registration loops — the spec says the config envelope is the fallback (runs last).

Run: `grep -n "CoreRegistration\|addErrorResponseResolver\|register(\$registry)\|->register(" src/OpenApiServiceProvider.php | head -30`

- [ ] **Step 2: Add the resolver-class resolution method**

Add this private method to `OpenApiServiceProvider`:

```php
/**
 * Resolve the configured error envelope to its resolver class.
 *
 * Accepts the four preset names (`'none'`, `'laravel'`, `'rfc7807'`, `'json-api'`) or a
 * fully-qualified class name of a custom {@see ErrorResponseResolver}. Throws on a
 * typoed preset name so failures surface at boot, not later as an autoload error.
 *
 * @return class-string<\Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver>
 */
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;private function resolveErrorEnvelopeClass(string $envelope): string
{
    return match ($envelope) {
        'none'     => \Radiergummi\OpenApi\Core\Envelopes\NoneEnvelope::class,
        'laravel'  => \Radiergummi\OpenApi\Core\Envelopes\LaravelEnvelope::class,
        'rfc7807'  => \Radiergummi\OpenApi\Core\Envelopes\Rfc7807Envelope::class,
        'json-api' => \Radiergummi\OpenApi\Core\Envelopes\JsonApiEnvelope::class,
        default    => $this->validateCustomEnvelopeClass($envelope),
    };
}

/**
 * @return class-string<\Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver>
 */
private function validateCustomEnvelopeClass(string $envelope): string
{
    if (!class_exists($envelope)) {
        throw new \InvalidArgumentException(sprintf(
            'Unknown error_envelope "%s". Known presets: none, laravel, rfc7807, json-api. '
            . 'Or supply a fully-qualified class name implementing %s.',
            $envelope,
            \Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver::class,
        ));
    }

    if (!is_a($envelope, \Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver::class, true)) {
        throw new \InvalidArgumentException(sprintf(
            'Class %s does not implement %s.',
            $envelope,
            \Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver::class,
        ));
    }

    /** @var class-string<\Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver> $envelope */
    return $envelope;
}
```

- [ ] **Step 3: Register the resolver after plugin registration**

Find the spot in `register()` (or wherever) where plugin `register()` calls have completed. Add immediately after:

```php
$envelopeClass = $this->resolveErrorEnvelopeClass(
    (string) config('openapi.error_envelope', 'none'),
);
$registry->addErrorResponseResolver($envelopeClass);
```

(`$registry` here is the `OpenApiRegistry` instance already in scope at that point.)

- [ ] **Step 4: Write tests for the validation**

Add to `tests/Unit/OpenApiServiceProviderTest.php` (or create if not present):

```php
use Radiergummi\OpenApi\Core\Envelopes\LaravelEnvelope;use Radiergummi\OpenApi\Core\Envelopes\NoneEnvelope;use Radiergummi\OpenApi\Registry\OpenApiRegistry;

it('registers the configured envelope resolver', function (): void {
    config()->set('openapi.error_envelope', 'laravel');

    $registry = app(OpenApiRegistry::class);

    expect($registry->errorResponseResolvers())->toContain(LaravelEnvelope::class);
});

it('defaults to NoneEnvelope when no envelope is configured', function (): void {
    config()->set('openapi.error_envelope', 'none');

    $registry = app(OpenApiRegistry::class);

    expect($registry->errorResponseResolvers())->toContain(NoneEnvelope::class);
});

it('throws InvalidArgumentException on a typoed preset name', function (): void {
    config()->set('openapi.error_envelope', 'larvel');

    expect(fn () => app(OpenApiRegistry::class))
        ->toThrow(InvalidArgumentException::class, 'Unknown error_envelope "larvel"');
});

it('throws InvalidArgumentException when a custom FQCN does not implement the resolver', function (): void {
    config()->set('openapi.error_envelope', \stdClass::class);

    expect(fn () => app(OpenApiRegistry::class))
        ->toThrow(InvalidArgumentException::class, 'does not implement');
});
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Unit/OpenApiServiceProviderTest.php`

Expected: PASS (4 new tests; existing tests untouched).

- [ ] **Step 6: Run the full suite + static analysis**

Run: `composer test && composer analyse && composer lint`

Expected: PASS / no PHPStan errors / no Pint violations.

- [ ] **Step 7: Commit**

```bash
git add src/OpenApiServiceProvider.php tests/Unit/OpenApiServiceProviderTest.php
git commit -m "feat(provider): wire error_envelope config to resolver registry

Resolves preset names to built-in envelope classes; treats anything else as an
FQCN that must implement ErrorResponseResolver. Typos and bad classes fail at
boot with a clear message listing the known presets."
```

---

## Task 10: Add `error_envelope` default to shipped config

**Files:**
- Modify: `config/openapi.php`

- [ ] **Step 1: Add the config key**

In `config/openapi.php`, after the `'middleware_responses'` block (around line 137), insert:

```php

    /*
    |--------------------------------------------------------------------------
    | Error Envelope
    |--------------------------------------------------------------------------
    |
    | Selects the body shape of the standard error responses (4xx/5xx derived
    | from @throws and middleware). Ships with four presets:
    |
    |   'none'     — description-only responses (no body). The package default.
    |   'laravel'  — { message, errors? }. Matches Laravel's default JSON shape.
    |   'rfc7807'  — application/problem+json, RFC 7807 problem details.
    |   'json-api' — application/vnd.api+json, JSON:API errors document.
    |
    | Or pass a fully-qualified class name of your own ErrorResponseResolver.
    |
    */

    'error_envelope' => 'none',
```

- [ ] **Step 2: Run the suite**

Run: `composer test`

Expected: PASS — `'none'` was already the implicit default; the explicit key just makes it visible.

- [ ] **Step 3: Commit**

```bash
git add config/openapi.php
git commit -m "feat(config): expose error_envelope key with 'none' default"
```

---

## Task 11: Chain composition test — plugins first, envelope last

**Files:**
- Create: `tests/Unit/Extractors/ErrorResolverChainTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Validation\ValidationException;use OpenApi\Annotations as OA;use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;use Radiergummi\OpenApi\Core\Envelopes\LaravelEnvelope;use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;use Radiergummi\OpenApi\Errors\ErrorDescriptor;use Radiergummi\OpenApi\Errors\ErrorResponse;

uses()->group('openapi');

it('falls through to the next resolver when one returns null', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $passthrough = new class implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
        {
            return null;
        }
    };

    $resolvers = [$passthrough, $envelope];

    $body = null;
    foreach ($resolvers as $resolver) {
        $body = $resolver->resolveErrorResponse(new ErrorDescriptor(
            status: 422,
            exceptionClass: ValidationException::class,
            description: 'Validation failed',
        ));
        if ($body !== null) {break;}
    }

    expect($body)->not->toBeNull();
    expect($body->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationError'));
});

it('short-circuits on the first non-null result', function (): void {
    $custom = new class implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
        {
            return new ErrorResponse(
                content: [new OA\MediaType(['mediaType' => 'application/custom'])],
            );
        }
    };

    $envelope = new LaravelEnvelope(new ComponentSchemaRegistry());

    $resolvers = [$custom, $envelope];

    $body = null;
    foreach ($resolvers as $resolver) {
        $body = $resolver->resolveErrorResponse(new ErrorDescriptor(401, null, 'Unauthenticated'));
        if ($body !== null) {break;}
    }

    expect($body->content[0]->mediaType)->toBe('application/custom');
});

it('honors ErrorResponse::bodyless() as a claim with no body', function (): void {
    $custom = new class implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
        {
            return ErrorResponse::bodyless();
        }
    };

    $body = $custom->resolveErrorResponse(new ErrorDescriptor(500, null, 'Server error'));

    expect($body)->not->toBeNull();
    expect($body->content)->toBe([]);
});
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/pest tests/Unit/Extractors/ErrorResolverChainTest.php`

Expected: PASS (3 tests).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Extractors/ErrorResolverChainTest.php
git commit -m "test(errors): verify resolver-chain semantics"
```

---

## Task 12: Subclass-matching test

Verifies the `is_a($cls, X::class, true)` convention works for user-defined exception subclasses.

**Files:**
- Create: `tests/Unit/Errors/SubclassMatchingTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Validation\ValidationException;use Radiergummi\OpenApi\Core\Envelopes\LaravelEnvelope;use Radiergummi\OpenApi\Core\Envelopes\Rfc7807Envelope;use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;use Radiergummi\OpenApi\Errors\ErrorDescriptor;

uses()->group('openapi');

// User-defined subclass of a framework exception.
class CustomValidationException extends ValidationException {
    public function __construct() {
        // Bypass parent constructor (it requires a Validator instance).
    }
}

it('LaravelEnvelope matches subclasses of ValidationException', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new LaravelEnvelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 422,
        exceptionClass: CustomValidationException::class,
        description: 'Validation failed',
    ));

    expect($response->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationError'));
});

it('Rfc7807Envelope matches subclasses of ValidationException', function (): void {
    $registry = new ComponentSchemaRegistry();
    $envelope = new Rfc7807Envelope($registry);

    $response = $envelope->resolveErrorResponse(new ErrorDescriptor(
        status: 422,
        exceptionClass: CustomValidationException::class,
        description: 'Validation failed',
    ));

    expect($response->content[0]->schema->ref)->toBe($registry->qualifyKey('ValidationProblem'));
});
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/pest tests/Unit/Errors/SubclassMatchingTest.php`

Expected: PASS (2 tests).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Errors/SubclassMatchingTest.php
git commit -m "test(errors): verify subclass matching via is_a convention"
```

---

## Task 13: Integration snapshot tests — one per preset

**Files:**
- Create: `tests/Fixtures/Errors/ErrorEnvelopeFixtureController.php`
- Create: `tests/Feature/Errors/EnvelopePresetSnapshotTest.php`

- [ ] **Step 1: Create the fixture controller**

Create `tests/Fixtures/Errors/ErrorEnvelopeFixtureController.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Errors;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class ErrorEnvelopeFixtureController
{
    /**
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
```

- [ ] **Step 2: Write the snapshot test**

Create `tests/Feature/Errors/EnvelopePresetSnapshotTest.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Tests\Fixtures\Errors\ErrorEnvelopeFixtureController;

uses()->group('openapi');

beforeEach(function (): void {
    Route::middleware('auth')
        ->get('/widgets/{id}', [ErrorEnvelopeFixtureController::class, 'show'])
        ->name('widgets.show');
});

dataset('envelopes', [
    'none'     => ['none', 'application/json', null],          // preset, expected media type (null = bodyless), expected schema key
    'laravel'  => ['laravel', 'application/json', 'Error'],
    'rfc7807'  => ['rfc7807', 'application/problem+json', 'Problem'],
    'json-api' => ['json-api', 'application/vnd.api+json', 'ErrorDocument'],
]);

it('renders the configured envelope on error responses', function (
    string $preset,
    string $expectedMediaType,
    ?string $expectedSchemaKey,
): void {
    config()->set('openapi.error_envelope', $preset);

    $document = app(OpenApiGenerator::class)->generate();
    $yaml = $document->toYaml();

    if ($preset === 'none') {
        // Bodyless — error responses have description but no content.
        expect($yaml)->toMatch('/Unauthorized:\s+description:/');
        expect($yaml)->not->toMatch('/Unauthorized:\s+description:[\s\S]{0,200}content:/');
        return;
    }

    expect($yaml)->toContain($expectedMediaType);
    expect($yaml)->toContain("\$ref: '#/components/schemas/{$expectedSchemaKey}'");
})->with('envelopes');
```

- [ ] **Step 3: Run the test**

Run: `vendor/bin/pest tests/Feature/Errors/EnvelopePresetSnapshotTest.php`

Expected: PASS (4 dataset cases).

If the YAML structure differs from what the assertions expect (e.g., snake-case keys, different ref quoting), adjust the regex/contains assertions to match swagger-php's actual output. The intent is to assert *presence* of the right schema ref and media type per preset, not to lock byte-exact YAML.

- [ ] **Step 4: Commit**

```bash
git add tests/Fixtures/Errors/ tests/Feature/Errors/EnvelopePresetSnapshotTest.php
git commit -m "test(errors): integration snapshot per envelope preset"
```

---

## Task 14: Default-bodyless integration test

Locks in the no-behavior-change-on-upgrade promise: shipping with `'error_envelope' => 'none'` produces today's bodyless output.

**Files:**
- Create: `tests/Feature/Errors/DefaultBodylessTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Tests\Fixtures\Errors\ErrorEnvelopeFixtureController;

uses()->group('openapi');

it('default config (error_envelope=none) emits no body on error responses', function (): void {
    // Do not override config — exercise the shipped default.
    Route::middleware('auth')
        ->get('/widgets/{id}', [ErrorEnvelopeFixtureController::class, 'show'])
        ->name('widgets.show');

    $document = app(OpenApiGenerator::class)->generate();
    $yaml = $document->toYaml();

    // Pick one well-known error response and assert no content section follows.
    expect($yaml)->toMatch('/Unauthorized:\s+description:/');

    // Crude but effective: ensure the Unauthorized component does not contain a
    // content section.
    $unauthorizedSection = preg_match(
        '/Unauthorized:(?:[^\n]*\n[ ]+[^\n]*)*?(?=\n[a-zA-Z]|\z)/',
        $yaml,
        $matches,
    );
    expect($unauthorizedSection)->toBe(1);
    expect($matches[0])->not->toContain('content:');
});
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/pest tests/Feature/Errors/DefaultBodylessTest.php`

Expected: PASS (1 test).

If the regex doesn't match the actual YAML shape, adjust it — the intent is "for the well-known Unauthorized response component, the rendered YAML contains a description but no content key."

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Errors/DefaultBodylessTest.php
git commit -m "test(errors): lock in the no-body default behavior"
```

---

## Task 15: Docs and changelog

**Files:**
- Modify: `docs/getting-started.md`
- Modify: `docs/recipes.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update `docs/getting-started.md`**

Find the "what gets derived" section (`grep -n "derive" docs/getting-started.md`). Add one bullet:
```markdown
- Error response bodies from the configured envelope preset (`config('openapi.error_envelope')`): `none` (default, no body), `laravel`, `rfc7807`, or `json-api`. See [Recipes › Choosing an error envelope](recipes.md#choosing-an-error-envelope).
```

- [ ] **Step 2: Add a recipes section**

In `docs/recipes.md`, add a new section (insertion point depends on the existing layout — append at end if unsure):

````markdown
## Choosing an error envelope

Standard error responses (4xx/5xx derived from `@throws` and auth/scope/throttle middleware) ship with no body by default. Select an envelope preset via `config/openapi.php`:

```php
'error_envelope' => 'laravel',  // or 'rfc7807' | 'json-api' | 'none'
```

### Presets

| Preset    | Media type                    | Generic shape                    | 422 shape                                                  |
|-----------|-------------------------------|----------------------------------|------------------------------------------------------------|
| `none`    | — (no body)                   | description only                 | description only                                           |
| `laravel` | `application/json`            | `{ message: string }`            | `{ message: string, errors: { <field>: string[] } }`       |
| `rfc7807` | `application/problem+json`    | Problem (`type`, `title`, ...)   | ValidationProblem (`+ errors`)                             |
| `json-api`| `application/vnd.api+json`    | `{ errors: [...] }` (uniform)    | same shape                                                 |

### Custom envelopes

Implement `ErrorResponseResolver` and point `error_envelope` at your class:

```php
use Radiergummi\OpenApi\Core\Envelopes\{ErrorDescriptor, ErrorResponse};
use Radiergummi\OpenApi\Core\Registry\ErrorResponseResolver;

final class MyEnvelope implements ErrorResponseResolver
{
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse
    {
        // Use is_a — never strict equality on framework exceptions.
        if ($descriptor->exceptionClass !== null
            && is_a($descriptor->exceptionClass, MyDomainException::class, true)
        ) {
            return new ErrorResponse(content: [/* OA\MediaType */]);
        }
        return null;  // defer to the next resolver in the chain
    }
}
```

```php
'error_envelope' => App\OpenApi\MyEnvelope::class,
```

> **Note:** This package documents your error shapes; it does not install a Laravel exception handler that emits them. The recipes cookbook for matching runtime handlers is separate.
````

- [ ] **Step 3: Update `CHANGELOG.md`**

Under the `[Unreleased]` section, add:

```markdown
### Added
- `config('openapi.error_envelope')` config key with four presets (`none`, `laravel`, `rfc7807`, `json-api`) selecting the body shape of standard error responses.
- `ErrorDescriptor` and `ErrorResponse` value objects in `Radiergummi\OpenApi\Core\Envelopes\`.
- Optional `exception` key on `middleware_responses` entries, carrying the canonical thrown exception per middleware so resolvers can branch on exception class.

### Changed
- Renamed `ErrorResponseFactory` → `ErrorResponseResolver`. Method renamed to `resolveErrorResponse(ErrorDescriptor): ?ErrorResponse`. `OpenApiRegistry::addErrorResponseFactory()` → `addErrorResponseResolver()`.
- `StandardResponsesExtractor` calls the resolver chain per status code (instead of once per operation) so each status can carry a distinct body shape.
```

- [ ] **Step 4: Run docs link check (manual)**

Open the modified docs and verify the cross-link in `getting-started.md` points to the new section anchor.

- [ ] **Step 5: Commit**

```bash
git add docs/getting-started.md docs/recipes.md CHANGELOG.md
git commit -m "docs(errors): document error envelope presets and recipes"
```

---

## Final verification

After all tasks are complete:

- [ ] Run the full suite end-to-end

```bash
composer test && composer analyse && composer lint
```

Expected: tests green, PHPStan no errors, Pint no violations.

- [ ] Verify the shipped default still produces today's bodyless behavior (already covered by Task 14, but re-confirm visually):

```bash
vendor/bin/pest tests/Feature/Errors/DefaultBodylessTest.php
```

- [ ] Sanity-check generated YAML for each preset on a small fixture (manual, optional):

```bash
APP_ENV=testing php artisan openapi:generate --format=yaml | grep -A2 'Unauthorized:'
```

(With each preset set in config, the output should differ as expected.)
