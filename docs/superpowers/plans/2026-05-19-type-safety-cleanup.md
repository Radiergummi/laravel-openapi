# Type Safety Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring `radiergummi/laravel-openapi` to a clean PHPStan level 8, with `treatPhpDocTypesAsCertain` disabled, and make PHPStan a CI-blocking gate.

**Architecture:** Three phases. (1) Flip `treatPhpDocTypesAsCertain: false` — this alone clears 189 of 216 errors, all certainty artifacts. (2) Raise the level 6 → 8, surfacing 80 real errors, and fix them category by category. (3) Make CI blocking and update the docs that describe PHPStan as advisory.

**Tech Stack:** PHP 8.4, PHPStan level 8 + Larastan extension, Pest (Testbench), GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-05-19-type-safety-design.md`

---

## Conventions used in every task

- **List errors of one identifier:** run `composer analyse` and read the table; each entry ends with a `🪪 <identifier>` line. To count remaining errors of a category:

  ```bash
  composer analyse 2>&1 | grep -c '<identifier>'
  ```

- **A category is done** when that count is `0` AND `composer test` is green.
- **Never** silence a finding with a new `@phpstan-ignore` line. Fix the type.
- Every PHP file already carries `declare(strict_types=1);` and the MIT header — do not touch them.
- Commit after each task with the message shown in its final step.

---

## Task 1: Flip `treatPhpDocTypesAsCertain`

**Files:**
- Modify: `phpstan.neon`

- [ ] **Step 1: Capture the certainty-error sample**

Run `composer analyse` (currently 216 errors at level 6). From the output, pick ~12 entries spread across these identifiers: `identical.alwaysFalse`, `function.alreadyNarrowedType`, `instanceof.alwaysTrue`, `booleanOr.alwaysFalse`, `deadCode.unreachable`. For each, open the file at the reported line.

- [ ] **Step 2: Verify each sampled site is a genuine guard, not dead code**

For each sampled site, confirm the flagged check guards against a value PHPStan only *believes* is impossible because it trusts a PHPDoc type — typically a swagger-php `Generator::UNDEFINED` sentinel, a reflection result, or an annotation union. The check must be reachable at runtime.

Expected: all sampled sites are genuine guards. One is already confirmed — the `deadCode.unreachable` entries in `src/Core/Lint/Rules/SchemaConstraintsMissing.php` (lines 144, 160, 179) guard `$raw->maxLength`/`maxItems`/`minimum` against `Generator::UNDEFINED`; their `yield` statements are reachable.

If any sampled site is genuine dead code (the PHPDoc is accurate and the branch truly cannot run), STOP and report it before continuing — it must be removed deliberately, not flag-flipped away.

- [ ] **Step 3: Add the flag to `phpstan.neon`**

The file is:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 6
    paths:
        - src
    tmpDir: build/phpstan
```

Change the `parameters` block to:

```neon
parameters:
    level: 6
    treatPhpDocTypesAsCertain: false
    paths:
        - src
    tmpDir: build/phpstan
```

- [ ] **Step 4: Verify the error count drops to 27**

Run: `composer analyse 2>&1 | tail -3`
Expected: `[ERROR] Found 27 errors` — `missingType.generics` (17), `missingType.iterableValue` (5), `ignore.unmatchedIdentifier` (3), `ignore.unmatchedLine` (2).

- [ ] **Step 5: Verify the test suite is unaffected**

Run: `composer test`
Expected: all tests pass (PHPStan config has no runtime effect; this confirms a clean baseline).

- [ ] **Step 6: Commit**

```bash
git add phpstan.neon
git commit -m "build: disable treatPhpDocTypesAsCertain in PHPStan config

The package introspects reflection and swagger-php annotation data whose
PHPDoc types are not runtime guarantees; the defensive checks PHPStan
flagged as redundant are legitimate. Clears 189 certainty artifacts.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Raise the analysis level to 8

**Files:**
- Modify: `phpstan.neon`

- [ ] **Step 1: Change the level**

In `phpstan.neon`, change `level: 6` to `level: 8`.

- [ ] **Step 2: Verify 80 errors surface**

Run: `composer analyse 2>&1 | tail -3`
Expected: `[ERROR] Found 80 errors`.

- [ ] **Step 3: Record the category breakdown**

Run: `composer analyse 2>&1 | grep -oE '🪪  [a-zA-Z.]+' | sort | uniq -c | sort -rn`
Expected (counts may shift by ±1 across PHPStan patch versions):

```
24 🪪  argument.type
17 🪪  missingType.generics
 9 🪪  assign.propertyType
 8 🪪  property.notFound
 7 🪪  property.nonObject
 5 🪪  missingType.iterableValue
 3 🪪  ignore.unmatchedIdentifier
 2 🪪  method.nonObject
 2 🪪  return.type
 2 🪪  ignore.unmatchedLine
 1 🪪  foreach.nonIterable
```

- [ ] **Step 4: Commit**

CI still has `continue-on-error: true` (Task 11 removes it), so committing the level bump while errors exist does not break the build.

```bash
git add phpstan.neon
git commit -m "build: raise PHPStan to level 8

Surfaces 80 real findings, fixed in the following commits. CI remains
non-blocking until the backlog is clear.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Fix `missingType.generics` (17 sites)

**Recipe:** PHPStan reports a generic class used without its type argument. Add the type argument in the `@param` / `@return` / `@var` PHPDoc tag, or in the `implements` clause. The PHPStan message names the class and the missing parameter (`T`, `TKey`, `TValue`).

**Files (all 17 sites):**
- `src/Core/Generator/JsonSchemaFromType.php:128, 140, 151`
- `src/Core/Generator/OpenApiGenerator.php:241`
- `src/Core/Generator/OperationBuilder.php:693`
- `src/Core/Generator/OperationDescriptor.php:21`
- `src/Core/Lint/Finding.php:18`
- `src/Core/Lint/FindingLocation.php:22`
- `src/Core/Lint/LinterSummary.php:23`
- `src/Core/Lint/Rules/AbstractFieldRule.php:79`
- `src/Core/Lint/Rules/DeprecatedAttribute.php:94, 131`
- `src/Core/Lint/Rules/ExternaldocsInvalidUrl.php:50`
- `src/Core/Lint/Rules/HeaderInvalidName.php:56`
- `src/Core/Routing/ActionDescriptor.php:48`
- `src/Plugins/SpatieData/SchemaFromDataClass.php:309, 353`

- [ ] **Step 1: Fix the `implements` clauses**

`Finding.php:18`, `FindingLocation.php:22`, `LinterSummary.php:23`, and `OperationDescriptor.php:21` implement a generic interface (`Illuminate\Contracts\Support\Arrayable`) without arguments. `Arrayable` is `Arrayable<TKey, TValue>`. Each of these classes' `toArray()` returns a string-keyed array.

Worked example — `src/Core/Lint/Finding.php:18`:

```php
// before
final readonly class Finding implements Arrayable, JsonSerializable

// after
/**
 * @implements Arrayable<string, mixed>
 */
final readonly class Finding implements Arrayable, JsonSerializable
```

Apply the same `@implements Arrayable<string, mixed>` PHPDoc to `FindingLocation`, `LinterSummary`, and `OperationDescriptor` (check each `toArray()` to confirm `string` keys; use the actual value type if narrower than `mixed`).

- [ ] **Step 2: Fix the method-parameter and `@var` sites**

For each remaining site, add the generic argument the class declares:

- `JsonSchemaFromType.php:128, 140, 151` — `$type` is a `Symfony\Component\TypeInfo\Type\BuiltinType` / `ObjectType`; add the `@param BuiltinType<...> $type` argument symfony-typeinfo declares for that class (PHPStan's message states the missing parameter).
- `ExternaldocsInvalidUrl.php:50` and `HeaderInvalidName.php:56` — the `@var` tag for `$attributes` uses `ReflectionAttribute` bare; change to `ReflectionAttribute<object>` (or the specific attribute class if the surrounding code targets one).
- `ActionDescriptor.php:48`, `SchemaFromDataClass.php:309, 353`, `AbstractFieldRule.php:79`, `DeprecatedAttribute.php:94, 131`, `OpenApiGenerator.php:241`, `OperationBuilder.php:693` — add the generic argument to the `ReflectionClass`/`CollectionType`/`ObjectType`/`ReflectionAttribute` parameter or return type named in the message.

- [ ] **Step 3: Verify the category is clear**

Run: `composer analyse 2>&1 | grep -c 'missingType.generics'`
Expected: `0`

- [ ] **Step 4: Verify tests still pass**

Run: `composer test`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src
git commit -m "types: add missing generic type arguments (PHPStan level 8)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Fix `missingType.iterableValue` (5 sites)

**Recipe:** An `iterable` / `array` type is declared without its value type. Add the value type to the `@param` or `@return` PHPDoc tag.

**Files (all 5 sites):**
- `src/Core/Lint/Finding.php:108` — `jsonSerialize()` return
- `src/Core/Lint/FindingLocation.php:101` — `jsonSerialize()` return
- `src/Core/Lint/LinterSummary.php:88` — `jsonSerialize()` return
- `src/Core/Lint/Rules/SchemaConstraintsMissing.php:120` — `inspectSchema()` param
- `src/Core/Lint/Tree/SpecTreeBuilder.php:932` — `extractRef()` param

- [ ] **Step 1: Type the `jsonSerialize()` returns**

For `Finding`, `FindingLocation`, and `LinterSummary`, `jsonSerialize(): array` returns the same shape as `toArray()`. Add a `@return array<string, mixed>` PHPDoc tag above each method (use a narrower value type if `toArray()` has one).

Worked example — `src/Core/Lint/Finding.php:108`:

```php
// before
#[Override]
public function jsonSerialize(): array
{
    return $this->toArray();
}

// after
/**
 * @return array<string, mixed>
 */
#[Override]
public function jsonSerialize(): array
{
    return $this->toArray();
}
```

- [ ] **Step 2: Type the two parameter sites**

- `SchemaConstraintsMissing.php:120` — the `$enum` parameter is `?array`; type it from how `$enum` is consumed at lines 136–138 (`@param list<string|int>|null $enum` or the actual element type).
- `SpecTreeBuilder.php:932` — `extractRef()`'s `$schema` parameter array; add the value type from its usage.

- [ ] **Step 3: Verify the category is clear**

Run: `composer analyse 2>&1 | grep -c 'missingType.iterableValue'`
Expected: `0`

- [ ] **Step 4: Verify tests still pass**

Run: `composer test`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src
git commit -m "types: add missing iterable value types (PHPStan level 8)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Fix `assign.propertyType` (9 sites)

**Recipe:** A value is assigned to a property whose declared type is wider/narrower than the value. Either tighten the setter's parameter type so the value matches the property, or widen the property — whichever reflects the real contract.

**Files:**
- `src/Core/Registry/OpenApiRegistry.php:67, 74, 81, 88, 95, 102, 109` (7 sites)
- `src/Core/Extractors/ValidationRulesToSchema.php:718` (1 site)
- `src/Core/Lint/Tree/SpecTreeWalker.php:76` (1 site)

- [ ] **Step 1: Fix `OpenApiRegistry`**

Each property is a `list<class-string<X>>` (e.g. `$requestSchemaResolvers` is `list<class-string<RequestSchemaResolver>>`), but the matching `add*()` method declares its parameter as plain `string`, so `$this->prop[] = $class` assigns a `string` into a `class-string<X>` list. Tighten each `add*()` parameter to the matching `class-string`.

Worked example — `addRequestSchemaResolver()`:

```php
// before
public function addRequestSchemaResolver(string $class): void

// after
/**
 * @param class-string<RequestSchemaResolver> $class
 */
public function addRequestSchemaResolver(string $class): void
```

Apply the analogous `class-string<...>` `@param` to `addRefSchemaResolver`, `addQueryParameterResolver`, `addPrimaryResponseResolver`, `addPayloadClass` (`class-string`), `addErrorResponseFactory`, and `addRule` — matching each property's declared element type.

- [ ] **Step 2: Fix the two remaining sites**

- `ValidationRulesToSchema.php:718` — the value assigned to `FieldDescriptor::$enum` does not match `$enum`'s declared `list<int|string>` (per the message). Narrow the assigned value (cast/filter) or correct the property/value type so they agree.
- `SpecTreeWalker.php:76` — the value assigned to `$visitors` does not match its declared `array<class-string, ...>` shape. Align the assignment with the property type.

- [ ] **Step 3: Verify the category is clear**

Run: `composer analyse 2>&1 | grep -c 'assign.propertyType'`
Expected: `0`

- [ ] **Step 4: Verify tests still pass**

Run: `composer test`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src
git commit -m "types: align property assignment types (PHPStan level 8)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Remove stale `@phpstan-ignore` lines (5 sites)

**Recipe:** `ignore.unmatchedIdentifier` / `ignore.unmatchedLine` mean an `@phpstan-ignore` comment no longer matches any error (the flag flip and earlier fixes resolved the underlying issue). Delete the now-stale ignore comment.

**Files:**
- `src/Core/Extractors/FieldDescriptor.php:225` — stale `assign.propertyType` ignore
- `src/Core/Generator/NullableSchema.php:138` — stale `assign.propertyType` ignore
- `src/Core/Lint/Tree/SpecTreeBuilder.php:935` — stale ignore (no error on line)
- `src/Core/Lint/Tree/SpecTreeBuilder.php:939` — stale `nullCoalesce.property` ignore
- `src/Core/Lint/Tree/SpecTreeBuilder.php:942` — stale ignore (no error on line)

- [ ] **Step 1: Delete each stale ignore comment**

Run `composer analyse` immediately before editing and confirm each of the five lines is still reported as a stale ignore (`ignore.unmatched*`) — Tasks 3–5 may have shifted which ignores match. Then open each still-stale site and remove its `@phpstan-ignore` / `@phpstan-ignore-next-line` comment. If a later task (7–10) re-introduces an error on one of these lines, PHPStan will flag it normally and it gets fixed there.

- [ ] **Step 2: Verify both categories are clear**

Run: `composer analyse 2>&1 | grep -cE 'ignore.unmatched'`
Expected: `0`

- [ ] **Step 3: Verify tests still pass**

Run: `composer test`
Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add src
git commit -m "types: remove stale @phpstan-ignore annotations (PHPStan level 8)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Fix `argument.type` (24 sites)

**Recipe:** A value of the wrong type is passed to a method/constructor. Fix at the call site — narrow the value, correct its type, or fix the upstream declaration that produced the wrong type. The most common sub-pattern: a `ReflectionClass` constructor expects `class-string`/`object` but receives plain `string`; fix by typing the variable as `class-string` where it originates, or asserting it.

**Files (all 24 sites):**
- `src/Console/LintCommand.php:238`
- `src/Core/Extractors/SecurityExtractor.php:94, 127`
- `src/Core/Extractors/StandardResponsesExtractor.php:135, 139, 143, 270`
- `src/Core/Generator/ComponentSchemaRegistry.php:354, 382`
- `src/Core/Lint/Formatters/CliFormatter.php:77, 85, 218`
- `src/Core/Lint/Rules/DiscriminatorInvalidMapping.php:248`
- `src/Core/Lint/Rules/SchemaConstraintsMissing.php:87`
- `src/Core/Lint/Rules/SpecInvalid.php:248, 249`
- `src/Core/Lint/SuppressionCollector.php:206, 222`
- `src/Core/Lint/Tree/SpecTreeBuilder.php:361`
- `src/Core/Routing/RouteIntrospector.php:112`
- `src/OpenApiServiceProvider.php:236, 338`
- `src/Plugins/SpatieData/Lint/Rules/MultipartFileWithoutMultipart.php:94`
- `src/Plugins/SpatieData/SchemaFromDataClass.php:269`

- [ ] **Step 1: Fix the `ReflectionClass`/`class-string` sites**

`StandardResponsesExtractor.php:270`, `SuppressionCollector.php:206, 222`, and `RouteIntrospector.php:112` pass a plain `string` to a `ReflectionClass` constructor expecting `class-string`. Trace the variable to its origin and type it `class-string` there (a route's controller name, an attribute target, etc.). If the value cannot be statically proven a `class-string`, add a `class_exists()` guard and the value becomes `class-string` inside the guarded block.

- [ ] **Step 2: Fix the remaining sites**

For each other site, read the PHPStan message — it names the parameter and the expected vs actual type. Fix at the call site by correcting the type of the passed value. The `OpenApiServiceProvider.php:236, 338` sites pass an `$indirectionClasses` array whose element type does not match `SuppressionCollector` / `PayloadParameterScanner`'s expected `list<class-string>`; type the config-derived array accordingly.

If any site reveals a genuine type mismatch that is a latent bug (not just a missing annotation), note it for the commit message.

- [ ] **Step 3: Verify the category is clear**

Run: `composer analyse 2>&1 | grep -c 'argument.type'`
Expected: `0`

- [ ] **Step 4: Verify tests still pass**

Run: `composer test`
Expected: all tests pass. If a fix changed observable behavior, add a `CHANGELOG.md` entry under `[Unreleased]`.

- [ ] **Step 5: Commit**

```bash
git add src CHANGELOG.md
git commit -m "types: correct argument types at call sites (PHPStan level 8)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Fix `property.notFound` (8 sites)

**Recipe:** A property is accessed on a union type or bare `object` where not every member declares it — typically swagger-php annotation unions (`OA\Annotations\MediaType|OA\Attributes\...`) or a `config()` result typed `object`. Narrow the type with an `instanceof` check before the access, or correct the variable's declared type.

**Files (all 8 sites):**
- `src/Core/Generator/OpenApiGenerator.php:246` — `$environments` on `object`
- `src/Core/Generator/OperationBuilder.php:358, 455`
- `src/Core/Lint/Rules/ExternaldocsInvalidUrl.php:58` — `$url` on `object`
- `src/Core/Lint/Rules/HeaderInvalidName.php:65, 74` — `$name` on `object`
- `src/Core/Lint/Rules/StreamingNoContentType.php:109`
- `src/Core/Lint/Tree/SpecTreeBuilder.php:347`

- [ ] **Step 1: Fix the bare-`object` sites**

`OpenApiGenerator.php:246` (`$environments`), `ExternaldocsInvalidUrl.php:58` (`$url`), `HeaderInvalidName.php:65, 74` (`$name`) access a property on a value typed `object`. The value comes from a `ReflectionAttribute::newInstance()` or `config()`. Type the variable to the concrete attribute/annotation class it actually is (the surrounding rule code targets one specific attribute) so the property is known.

- [ ] **Step 2: Fix the annotation-union sites**

`OperationBuilder.php:358, 455`, `StreamingNoContentType.php:109`, `SpecTreeBuilder.php:347` access a property on an `OA\Annotations\*|OA\Attributes\*` union. Add an `instanceof` narrowing to the member that has the property before accessing it, or skip members that do not.

- [ ] **Step 3: Verify the category is clear**

Run: `composer analyse 2>&1 | grep -c 'property.notFound'`
Expected: `0`

- [ ] **Step 4: Verify tests still pass**

Run: `composer test`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src
git commit -m "types: narrow union types before property access (PHPStan level 8)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Fix `property.nonObject` and `method.nonObject` (9 sites)

**Recipe:** A property or method is accessed on a possibly-`null` value. These are the findings most likely to expose a latent bug. For each: determine whether the null path is genuinely reachable. If it is not (the value is always set by the time of access), narrow the type at the source. If it is reachable, add the null guard — and that is a behavior fix, so add a `CHANGELOG.md` entry.

**Files (9 sites — 2 lines carry both identifiers):**
- `src/Core/Lint/Rules/PublicEndpointContradictsMw.php:76` — `$route` on `ActionDescriptor`
- `src/Core/Lint/Rules/ThrowsTransitiveMissing.php:159` — `$throws`
- `src/Core/Lint/Rules/ThrowsTransitiveMissing.php:182, 183, 188, 189` — `$controller` / `$method`
- `src/Plugins/SpatieData/Lint/Rules/MultipartFileWithoutMultipart.php:94` — `$method`

- [ ] **Step 1: Inspect `ActionDescriptor` and `OperationDescriptor`**

Open `src/Core/Routing/ActionDescriptor.php` and `src/Core/Generator/OperationDescriptor.php`. Confirm which accessed members are nullable: `OperationDescriptor::$descriptor`, and `ActionDescriptor`'s `$route`, `$throws`, `$controller`, `$method`. The message at each flagged line names the member and the type it is accessed on.

- [ ] **Step 2: Fix `ThrowsTransitiveMissing.php`**

Lines 159/182/183/188/189 access `$operation->descriptor->throws`, `->controller`, `->method`. The code at 182–189 already uses `->controller?->getShortName() ?? '(unknown)'` (null-safe) but `->method->getName()` is not null-safe and `->method` is `ReflectionMethod|null`. Add the null guard: either guard the whole `foreach` body with an early `continue` when `$operation->descriptor->method` is null, or use `?->getName() ?? '(unknown)'` consistently. Determine from `OperationDescriptor` whether `method` can actually be null at this point; if it cannot, tighten the property type instead.

- [ ] **Step 3: Fix the remaining two sites**

`PublicEndpointContradictsMw.php:76` (`$route`) and `MultipartFileWithoutMultipart.php:94` (`$method`) — apply the same analysis: narrow at source if the null path is unreachable, else guard.

- [ ] **Step 4: Verify both categories are clear**

Run: `composer analyse 2>&1 | grep -cE 'property.nonObject|method.nonObject'`
Expected: `0`

- [ ] **Step 5: Verify tests still pass**

Run: `composer test`
Expected: all tests pass. If any guard changed observable lint output, add a `CHANGELOG.md` entry under `[Unreleased]` and update `docs/usage.md` if the change is observable.

- [ ] **Step 6: Commit**

```bash
git add src CHANGELOG.md docs
git commit -m "types: guard against null property/method access (PHPStan level 8)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Fix `return.type` and `foreach.nonIterable` (3 sites)

**Recipe:** `return.type` — a returned value does not match the declared return type; narrow the value or correct the declaration. `foreach.nonIterable` — a value that may not be iterable is iterated; narrow it before the loop.

**Files (all 3 sites):**
- `src/Core/Lint/Tree/SpecTreeBuilder.php:796` — `buildSecurity()` return
- `src/Core/Lint/Tree/SpecTreeBuilder.php:1028` — `extractSchemaEnum()` return
- `src/Core/Routing/RouteIntrospector.php:60` — `foreach` over `array<Route>|RouteCollection...`

- [ ] **Step 1: Fix the two `return.type` sites**

For `buildSecurity()` (796) and `extractSchemaEnum()` (1028), read the PHPStan message naming the declared vs returned type. Correct whichever is wrong — usually narrow the returned expression (filter nulls, cast) so it matches the declared return type.

- [ ] **Step 2: Fix `RouteIntrospector.php:60`**

The `foreach` iterates a value typed `array<Route>|RouteCollection...` whose union includes a non-iterable member. Narrow the value to an iterable type before the loop (call `->getRoutes()` / cast to array / `instanceof` check) so the loop operand is always iterable.

- [ ] **Step 3: Verify both categories are clear**

Run: `composer analyse 2>&1 | grep -cE 'return.type|foreach.nonIterable'`
Expected: `0`

- [ ] **Step 4: Verify the whole backlog is clear**

Run: `composer analyse 2>&1 | tail -3`
Expected: `[OK] No errors`

- [ ] **Step 5: Verify tests still pass**

Run: `composer test`
Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src
git commit -m "types: fix return types and non-iterable foreach (PHPStan level 8)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: Make PHPStan CI-blocking and update docs

**Files:**
- Modify: `.github/workflows/quality.yml`
- Modify: `CONTRIBUTING.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Remove `continue-on-error` from the PHPStan step**

Open `.github/workflows/quality.yml`. Find the PHPStan/`analyse` step and delete its `continue-on-error: true` line so a non-zero exit fails the job.

- [ ] **Step 2: Update `CONTRIBUTING.md`**

Find the text describing the PHPStan backlog as known and non-blocking. Replace it with a statement that PHPStan runs at level 8 and is a hard CI gate alongside Pint — a PR is mergeable only when `tests`, Pint, and PHPStan are all green.

- [ ] **Step 3: Update `CLAUDE.md`**

In the "Commands" section, the line currently reads (approximately):

> PHPStan is **non-blocking in CI** and has a known pre-existing backlog — don't add new findings, but clearing the backlog is out of scope for routine changes.

Replace it with:

> PHPStan runs at level 8 with `treatPhpDocTypesAsCertain: false` and is **CI-blocking** — `composer analyse` must report no errors.

Also update the `composer analyse` comment in the command list from `level 6` to `level 8`.

- [ ] **Step 4: Verify the full quality gate locally**

Run: `composer test && composer lint && composer analyse`
Expected: tests pass, Pint reports no violations, PHPStan reports `[OK] No errors`.

- [ ] **Step 5: Add a CHANGELOG entry**

In `CHANGELOG.md`, under `[Unreleased]`, add to a `Changed` subsection:

```markdown
- PHPStan now runs at level 8 with `treatPhpDocTypesAsCertain` disabled and is a blocking CI check.
```

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/quality.yml CONTRIBUTING.md CLAUDE.md CHANGELOG.md
git commit -m "ci: make PHPStan a blocking gate at level 8

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Done criteria

- `composer analyse` reports `[OK] No errors` at level 8.
- `composer test` is green; `composer lint` reports no violations.
- `.github/workflows/quality.yml` runs PHPStan without `continue-on-error`.
- `CONTRIBUTING.md` and `CLAUDE.md` describe PHPStan as a blocking level-8 gate.
