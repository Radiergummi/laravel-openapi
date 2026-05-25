# Type Safety & Code Correctness Design

**Date:** 2026-05-19
**Status:** Approved (revised 2026-05-19 with measured error data)
**Workstream:** 2 of 5 in the pre-1.0 publication program (plugins → type safety → tests → docs → example apps)

## Goal

Raise PHPStan from level 6 to level 8, clear every finding so `composer
analyse` exits cleanly, then make PHPStan CI-blocking so the codebase stays
clean.

This spec covers only the type-safety / static-analysis cleanup. The test
suite, documentation, plugins, and example apps are separate workstreams with
their own specs.

## Current state

`composer analyse` reports **216 errors** at PHPStan level 6, with
`treatPhpDocTypesAsCertain` at its default `true`.

The error counts were then measured empirically by running PHPStan against
modified configs. This revised the original estimates:

- **Level 6, `treatPhpDocTypesAsCertain: false` → 27 errors.** Flipping the
  flag eliminates 189 errors. All 27 survivors are type-annotation gaps:
  `missingType.generics` (17), `missingType.iterableValue` (5),
  `ignore.unmatchedIdentifier` (3), `ignore.unmatchedLine` (2).
- The earlier spec draft listed `deadCode.unreachable` (3), `return.missing`
  (1), `arrayValues.list` (1), and `function.impossibleType` (1) as "genuine"
  errors. **They are not.** They are certainty artifacts—PHPStan treating a
  swagger-php `Generator::UNDEFINED` comparison as always-true and concluding
  the following `yield` is unreachable. They vanish when the flag is flipped.
  Removing that "unreachable" code would have broken the
  `schema.constraints-missing` lint rule. No dead code is removed in this
  workstream.
- **Level 8, `treatPhpDocTypesAsCertain: false` → 80 errors.** Raising the
  level on top of the flag flip surfaces 53 additional errors across these
  identifiers: `argument.type` (24), `missingType.generics` (17),
  `assign.propertyType` (9), `property.notFound` (8), `property.nonObject`
  (7), `missingType.iterableValue` (5), `ignore.unmatchedIdentifier` (3),
  `method.nonObject` (2), `return.type` (2), `ignore.unmatchedLine` (2),
  `foreach.nonIterable` (1).

CI currently runs PHPStan with `continue-on-error: true` in
`.github/workflows/quality.yml`; `CONTRIBUTING.md` and `CLAUDE.md` document
the backlog as known and non-blocking.

## Scope

In scope:

- Set `treatPhpDocTypesAsCertain: false` in `phpstan.neon`, after a spot-check
  confirms the suppressed errors are genuine defensive guards.
- Raise the PHPStan level from 6 to 8.
- Fix all 80 errors that remain at level 8 with the flag off.
- Make PHPStan CI-blocking and update `CONTRIBUTING.md` / `CLAUDE.md`.

Out of scope:

- Raising the PHPStan level above 8 (level 9 / `max`).
- Adding `phpstan-strict-rules` or bleeding-edge rules.
- Test-suite expansion (workstream 3).
- The plugin code from workstream 1—that code must land already clean at
  level 8; once this workstream makes CI blocking, the gate enforces it.

## Design principles

- **Level 8 is the bar.** This workstream raises the level to 8 and brings the
  codebase to a clean level-8 state. It does not go further (level 9 / strict
  rules are out of scope).
- **Behavior-preserving by default.** Most fixes are type annotations and null
  guards. `composer test` must stay green throughout. The `property.nonObject`
  / `method.nonObject` / `foreach.nonIterable` findings may expose latent
  bugs; any fix that changes observable behavior is flagged and gets a
  `CHANGELOG.md` entry.
- **Flag flip before everything.** The flag flip is applied first, after
  sampling confirms the flagged checks are real guards. Working at level 8
  with the flag still `true` would mean fixing 130+ certainty artifacts that
  the flip deletes for free—wasted, risky work.

## Section 1—The `treatPhpDocTypesAsCertain` flip

Set `treatPhpDocTypesAsCertain: false` in `phpstan.neon`.

Rationale: with the flag `true` (PHPStan default), PHPStan assumes every
PHPDoc type is guaranteed at runtime and flags any check redundant *under that
assumption*. This package introspects reflection data and swagger-php
annotations, where PHPDoc types describe intent but are not runtime
guarantees; the defensive checks are legitimate. `false` is the documented
PHPStan setting for exactly this situation.

Before flipping, **spot-check a sample** of ~10–15 flagged sites spread across
the suppressed identifiers (`identical.alwaysFalse`,
`function.alreadyNarrowedType`, `instanceof.alwaysTrue`,
`booleanOr.alwaysFalse`, `deadCode.unreachable`):

- If the sample is uniformly genuine defensive guards → flip the flag.
- If the sample turns up real dead code (the PHPDoc is accurate and the check
  truly is unreachable) → remove those specific sites first, then flip.

One sample point is already confirmed: the `deadCode.unreachable` errors in
`SchemaConstraintsMissing::inspectSchema()` guard against swagger-php
sentinels and are genuine; their `yield` statements are reachable at runtime.

## Section 2—Fixing the 80 level-8 errors

After the flag flip and the level raise, 80 errors remain. They are fixed by
identifier category. None require dead-code removal.

**Annotation-only categories (mechanical, behavior-preserving):**

- `missingType.generics` (17)—add the missing generic type parameter to
  methods, `@var` tags, and `implements` clauses.
- `missingType.iterableValue` (5)—add the value type to `iterable` / `array`
  return types and parameters.
- `assign.propertyType` (9)—tighten property type declarations (or the
  setter parameter types) so the assigned value matches; concentrated in
  `OpenApiRegistry` (7 sites).
- `ignore.unmatchedIdentifier` (3) + `ignore.unmatchedLine` (2)—remove the
  now-stale `@phpstan-ignore` lines.

**Investigation categories (may expose latent bugs):**

- `argument.type` (24)—a value of the wrong type passed to a method;
  frequently `ReflectionClass` constructors expecting `class-string`. Fix at
  the call site: narrow or correct the type.
- `property.notFound` (8)—property access on a union type or bare `object`
  where the property does not exist on every member. Narrow the type before
  access.
- `property.nonObject` (7) + `method.nonObject` (2)—property/method access
  on a possibly-`null` value, mostly `ActionDescriptor` fields and
  `ReflectionMethod|null`. Add the null guard; if the null path is genuinely
  reachable, that is a latent bug—fix it and add a `CHANGELOG.md` entry.
- `return.type` (2)—returned value does not match the declared return type.
- `foreach.nonIterable` (1)—`RouteIntrospector` iterating a value that may
  not be iterable. Narrow before the loop.

## Section 3—CI enforcement

- Raise `level: 6` → `level: 8` in `phpstan.neon`.
- Remove `continue-on-error: true` from the PHPStan step in
  `.github/workflows/quality.yml` so findings fail the build.
- Update `CONTRIBUTING.md`—remove the text describing the backlog as known
  and non-blocking; PHPStan is now a hard gate alongside Pint.
- Update `CLAUDE.md`—the line stating "PHPStan is non-blocking in CI … known
  pre-existing backlog" becomes "PHPStan is CI-blocking at level 8; keep it
  clean."

## Verification

- `composer analyse` exits 0 (no errors) at level 8.
- `composer test` stays green—fixes are behavior-preserving unless flagged.
- `composer lint` reports no violations.

## Build sequence

Each step leaves `composer test` green.

1. Spot-check the certainty-error sample; set
   `treatPhpDocTypesAsCertain: false`. Confirm level 6 drops to 27 errors.
2. Raise `level: 6` → `level: 8`. Confirm 80 errors surface; CI is still
   non-blocking, so the build is unaffected.
3. Fix the annotation-only categories (Section 2, first group).
4. Fix the investigation categories (Section 2, second group).
5. Confirm `composer analyse` exits 0 at level 8.
6. Flip CI to blocking; update `CONTRIBUTING.md` and `CLAUDE.md`.
