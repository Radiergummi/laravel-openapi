# Visibility Attributes—Design

**Date:** 2026-05-21
**Status:** Approved (brainstorming)
**Purpose:** Make endpoint visibility in the generated OpenAPI document explicit and bidirectional. Today every route is exposed unless `#[Hide]` opts out; this design adds a configurable default mode (`public` vs `hidden`) and introduces a symmetric `#[Expose]` attribute, both with bidirectional environment scoping (`only` / `except`).

---

## Goals

- Support the "everything hidden, explicitly expose" workflow used by internal/admin APIs.
- Keep current behavior unchanged for projects that don't opt in (`visibility.default = 'public'`).
- Make environment scoping read naturally in both directions—no more "list of envs in which to hide".
- Catch misconfiguration with lint rules instead of silent surprises.

## Non-goals

- Per-operation visibility based on auth, roles, or scopes. Visibility is a build-time decision driven by attributes and environment.
- Multiple visibility tiers (e.g., `internal` vs `partner` vs `public`). Two states—visible or not—is enough.
- Backwards compatibility shims for `#[Hide(environments: ...)]`. Pre-1.0, the rename is clean.

## User-facing surface

### Config

```php
// config/openapi.php
'visibility' => [
    // 'public' → endpoints exposed by default; #[Hide] opts out (current behavior)
    // 'hidden' → endpoints hidden by default; #[Expose] opts in
    'default' => 'public',
],
```

The package default is `'public'` so existing projects need no action.

### Attributes

Both live in `src/Core/Attributes/`. Both target class, method, and function.

```php


#[Hide]                            // hide unconditionally
#[Hide(only: ['production'])]      // hide only when APP_ENV ∈ {production}
#[Hide(except: ['local'])]         // hide in every env except {local}

#[Expose]                          // expose unconditionally
#[Expose(only: ['staging'])]       // expose only when APP_ENV ∈ {staging}
#[Expose(except: ['production'])]  // expose in every env except {production}
```

**Mutual exclusion.** Passing both `only` and `except` to the same attribute throws `LogicException` at construction time. The message names the attribute and the offending arguments so the failure is easy to diagnose.

**`Hide::$environments` is renamed.** The existing constructor parameter is replaced by `only`. The old name is removed, not aliased—pre-1.0, callers update once. The `CHANGELOG.md` entry under `[Unreleased]` calls this out as a breaking change with a one-line migration recipe.

## Resolution semantics

Visibility is decided per route, per environment, by `VisibilityResolver` (new—extracted from the current inline `OpenApiGenerator::matchesHide()`).

The algorithm:

1. **Resolve effective attributes.** For each of `Hide` and `Expose`, look at the method first, then the class. Method-level attributes shadow class-level attributes of the same type. (Method `Hide` does **not** shadow class `Expose`, and vice versa—they're orthogonal axes.)
2. **Evaluate Hide.** If a `Hide` attribute resolves to "applies in this env," the route is **hidden**. Hide always wins.
3. **Evaluate Expose.** Else, if an `Expose` attribute resolves to "applies in this env," the route is **exposed**.
4. **Fall back to default.** Else, return `visibility.default` (`'public'` → exposed, `'hidden'` → hidden).

An attribute "applies in this env" when:
- `only` is set and the current env is in the list, or
- `except` is set and the current env is **not** in the list, or
- neither is set (unconditional).

### Conflict precedence

Hide wins by design. The rationale: accidental exposure of a private endpoint is the failure mode we care about; accidental hiding of a public endpoint is recoverable and visible. The conflict is reported by a lint rule (see below).

### Class vs method

Method beats class for the *same* attribute. The class-level `#[Hide]` is a default that any method can override with its own `#[Hide]` (different env scope) or, if `visibility.default = 'hidden'`, can be lifted by adding `#[Expose]` to a single method. The walker still consults both class and method when deciding which Expose/Hide applies—it does not stop at the first attribute it finds.

## Implementation

### New code

- `src/Core/Attributes/Expose.php`—mirror of `Hide` with the same `only` / `except` shape and the same mutual-exclusion check.
- `src/Core/Visibility/VisibilityResolver.php`—pure decision class. Inputs: `ActionDescriptor`, current env string, default mode. Output: `bool $visible`. No reflection inside the resolver—the caller passes resolved attribute instances or arrays of attribute instances. This keeps the resolver trivially unit-testable.
- `src/Core/Visibility/VisibilityMode.php`—enum `Public` / `Hidden` to avoid string literals at call sites.

### Changes

- `src/Core/Attributes/Hide.php`—replace `$environments` with `$only`; add `$except`; add `LogicException` guard. Update docblock.
- `src/Core/Generator/OpenApiGenerator.php`—replace the inline `matchesHide()` path with a `VisibilityResolver` call. The generator now passes both the `Hide` and `Expose` attribute arrays from the descriptor plus the configured default.
- `src/Core/OpenApiServiceProvider.php`—bind `VisibilityResolver` (scoped) with the configured default mode pulled from `config('openapi.visibility.default')`. Invalid config values fall back to `'public'` with a deprecation-style log line.
- `config/openapi.php`—add the `visibility` block with explanatory comment.

### Reuse vs extract

Extracting `VisibilityResolver` (rather than keeping the logic inline as a second method on `OpenApiGenerator`) is justified because: (a) the logic now spans two attributes, two scoping arguments, two scope levels, and a default mode—five degrees of freedom—and inline cyclomatic complexity would grow accordingly; (b) lint rules need the same resolution to detect no-ops and conflicts, and duplicating the logic between generator and lint would invite drift.

## Lint rules

Two new rules in `src/Core/Lint/Rules/Visibility/`, both walking the `ActionDescriptor` level (not the OpenAPI tree, since hidden routes don't appear in the tree). They run from a dedicated `VisibilityRuleCollector` invoked alongside `SpecTreeWalker`, sharing the same `FindingsCollector`.

| Rule ID | Level | Triggers when |
|---|---|---|
| `visibility.hide-expose-conflict` | 1 | A route has at least one `Hide` and one `Expose` attribute (class or method) whose env scopes overlap for the current env. Reports the route, both attribute locations, and notes that Hide wins. |
| `visibility.attribute-no-op` | 2 | A route carries an attribute that has no effect: `Expose` (unconditional or env-scoped to the current env) when `visibility.default = 'public'` and no `Hide` is present; `Hide` (unconditional only) when `visibility.default = 'hidden'` and no `Expose` is present. Env-scoped `Hide`/`Expose` are not flagged—they can change effect across envs. |

Both rules integrate with the existing `severity_overrides`, `disabled_rules`, and `#[IgnoreLint]` machinery. The implementation plan must work out how `#[IgnoreLint]` suppression is wired for findings that originate from `ActionDescriptor` walks rather than from the spec tree.

## Tests

**Unit**
- `ExposeTest` and updated `HideTest`: `LogicException` when both `only` and `except` are passed; getters reflect inputs.
- `VisibilityResolverTest`: matrix of (default mode) × (Hide present/absent + scope) × (Expose present/absent + scope) × (current env). Method-overrides-class for each attribute. Hide-wins conflict cases.

**Feature**
- Default-public mode: existing behavior preserved (regression coverage).
- Default-hidden mode: only routes with applicable `Expose` appear in the document.
- `Hide` + `Expose` on the same action: route hidden, finding emitted.
- Env scoping across `local` / `staging` / `production` against `app()->environment()`.

**Lint**
- `visibility.hide-expose-conflict` fires on the conflict case and not when only one attribute is present.
- `visibility.attribute-no-op` fires for unconditional `Expose` in public mode and unconditional `Hide` in hidden mode; does **not** fire for env-scoped variants.

## Docs

- `docs/usage.md`—new "Endpoint visibility" section: the two modes, both attributes with `only`/`except`, the precedence rule, the lint rules. Replace any existing `Hide` example using `environments:` with the new `only:` form.
- `CHANGELOG.md`—entry under `[Unreleased]`:
  - **Added:** `#[Expose]` attribute; `visibility.default` config flag; `visibility.hide-expose-conflict` and `visibility.attribute-no-op` lint rules.
  - **Changed (breaking):** `#[Hide]` constructor argument renamed from `environments` to `only`; gains `except` as an exclusive alternative.

## Open questions

None. The design is ready for an implementation plan.
