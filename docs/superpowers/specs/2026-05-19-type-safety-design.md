# Type Safety & Code Correctness Design

**Date:** 2026-05-19
**Status:** Approved
**Workstream:** 2 of 5 in the pre-1.0 publication program (plugins → type safety → tests → docs → example apps)

## Goal

Raise PHPStan from level 6 to level 8, clear every finding so `composer
analyse` exits cleanly, then make PHPStan CI-blocking so the codebase stays
clean.

This spec covers only the type-safety / static-analysis cleanup. The test
suite, documentation, plugins, and example apps are separate workstreams with
their own specs.

## Current state

`composer analyse` reports **216 errors** at PHPStan level 6 (Larastan
extension, `paths: src`). They fall into two groups:

- **~184 PHPDoc-certainty errors** — one family caused by
  `treatPhpDocTypesAsCertain` defaulting to `true`. PHPStan trusts PHPDoc
  types as guaranteed, then flags defensive runtime checks as provably
  redundant. Identifiers: `identical.alwaysFalse` (59),
  `function.alreadyNarrowedType` (56), `notIdentical.alwaysTrue` (27),
  `booleanOr.alwaysFalse` (19), `instanceof.alwaysTrue` (10),
  `booleanAnd.alwaysTrue` (7), `booleanOr.alwaysTrue` (4),
  `booleanNot.alwaysFalse` (1), `function.impossibleType` (1).
- **~32 genuine errors** — real findings independent of the certainty flag:
  `missingType.generics` (17), `missingType.iterableValue` (5),
  `deadCode.unreachable` (3), `ignore.unmatchedIdentifier` (3),
  `ignore.unmatchedLine` (2), `return.missing` (1), `arrayValues.list` (1).

This 216 count is the **level-6** picture. Raising to level 8 will surface
additional errors — primarily null-safety (`possibly null` accesses, missing
null checks) and tighter type-narrowing findings. Their exact count and
identifiers are not known until the level is raised; enumerating and fixing
them is part of this workstream (Section 2).

CI currently runs PHPStan with `continue-on-error: true` in
`.github/workflows/quality.yml`; `CONTRIBUTING.md` and `CLAUDE.md` document
the backlog as known and non-blocking.

## Scope

In scope:

- Raise the PHPStan level from 6 to 8 in `phpstan.neon`.
- Fix the ~32 genuine level-6 errors individually.
- Fix every additional error level 8 surfaces.
- Resolve the ~184 PHPDoc-certainty errors by setting
  `treatPhpDocTypesAsCertain: false`, after a spot-check confirms they are
  genuine defensive guards.
- Make PHPStan CI-blocking and update `CONTRIBUTING.md` / `CLAUDE.md`.

Out of scope:

- Raising the PHPStan level above 8 (level 9 / `max`).
- Adding `phpstan-strict-rules` or bleeding-edge rules.
- Test-suite expansion (workstream 3).
- The plugin code from workstream 1 — that code must land already clean at
  level 8; once this workstream makes CI blocking, the gate enforces it.

## Design principles

- **Level 8 is the bar.** This workstream raises the level to 8 and brings the
  codebase to a clean level-8 state. It does not go further (level 9 / strict
  rules are out of scope).
- **Behavior-preserving.** The fixes are type annotations, null guards, and
  dead-code removal. `composer test` must stay green throughout; any fix that
  changes observable behavior is flagged and gets a `CHANGELOG.md` entry.
- **Keep the signal where it matters.** The flag flip is the standard setting
  for a codebase doing defensive runtime checks against reflection and
  swagger-php data, whose PHPDoc types are not guaranteed at runtime — but it
  is applied only after sampling confirms the flagged checks are real guards,
  not dead code.

## Section 1 — PHPDoc-certainty errors (~184)

Set `treatPhpDocTypesAsCertain: false` in `phpstan.neon`.

Rationale: with the flag `true` (PHPStan default), PHPStan assumes every
PHPDoc type is guaranteed at runtime and flags any check that is redundant
*under that assumption*. This package introspects reflection data and
swagger-php annotations, where PHPDoc types describe intent but are not
runtime guarantees; the defensive checks are legitimate. `false` is the
documented PHPStan setting for exactly this situation.

Before flipping, **spot-check a sample** of ~10–15 flagged sites spread across
the identifiers (`identical.alwaysFalse`, `function.alreadyNarrowedType`,
`instanceof.alwaysTrue`, `booleanOr.alwaysFalse`):

- If the sample is uniformly genuine defensive guards → flip the flag.
- If the sample turns up real dead code (the PHPDoc is accurate and the check
  truly is unreachable) → widen triage to find and remove those sites first,
  then flip the flag for the remainder.

## Section 2 — Genuine errors

### Level-6 backlog (~32)

Fixed individually; unaffected by the certainty flag.

- **`missingType.generics` (17) + `missingType.iterableValue` (5)** — add the
  missing generic / iterable type parameters. Known sites include
  `src/Core/Routing/ActionDescriptor.php` and
  `src/Plugins/SpatieData/SchemaFromDataClass.php`
  (`resolveCollectionSchema()`, `resolveObjectSchema()`).
- **`deadCode.unreachable` (3)** — remove the unreachable code.
- **`return.missing` (1)** — add the missing return path.
- **`arrayValues.list` (1)** — correct the array-shape annotation.
- **`ignore.unmatchedIdentifier` (3) + `ignore.unmatchedLine` (2)** — remove the
  now-stale `@phpstan-ignore` lines.

### Level-8 additions

Raising to level 8 enables stricter checks — chiefly null-safety: property and
method accesses on possibly-null values, missing null checks, and tighter type
narrowing. The full set is enumerated by running `composer analyse` after the
level is raised. Each finding is fixed at its source: add a null guard, narrow
the type, or correct the annotation — never silenced with a blanket ignore.
If a finding reveals a latent bug (a genuinely reachable null dereference),
that fix is behavior-changing and gets a `CHANGELOG.md` entry.

## Section 3 — CI enforcement

- Remove `continue-on-error: true` from the PHPStan step in
  `.github/workflows/quality.yml` so findings fail the build.
- Update `CONTRIBUTING.md` — remove the text describing the backlog as known
  and non-blocking; PHPStan is now a hard gate alongside Pint.
- Update `CLAUDE.md` — the line stating "PHPStan is non-blocking in CI … known
  pre-existing backlog" becomes "PHPStan is CI-blocking at level 8; keep it
  clean."

## Verification

- `composer analyse` exits 0 (no errors) at level 8.
- `composer test` stays green — the fixes are behavior-preserving.
- `composer lint` reports no violations.

## Build sequence

Each step leaves the suite green.

1. Fix the ~32 genuine level-6 errors while PHPStan still shows the full
   level-6 picture.
2. Spot-check the certainty-error sample; flip `treatPhpDocTypesAsCertain`
   (widening triage first if the sample reveals dead code). Confirm level 6 is
   clean.
3. Raise the level to 8 in `phpstan.neon`; enumerate and fix every error it
   surfaces.
4. Confirm `composer analyse` exits 0 at level 8.
5. Flip CI to blocking; update `CONTRIBUTING.md` and `CLAUDE.md`.
