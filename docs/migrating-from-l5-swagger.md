# Migrating from L5-Swagger / swagger-php

If you arrived here with the *"annotations are the source of truth"* mental model —
hand-write `#[OA\*]` / `@OA` blocks, the package scans them — this page explains how
this library inverts that model, and how to migrate an annotated codebase onto it
without losing the spec you already have.

## How this differs from L5-Swagger / swagger-php

[`darkaonline/l5-swagger`](https://github.com/DarkaOnLine/L5-Swagger) (and raw
[`zircote/swagger-php`](https://github.com/zircote/swagger-php) underneath it) build the
document **from the annotations you write**. You hand-author `#[OA\Get]`, `#[OA\Response]`,
`#[OA\Schema]` blocks; the scanner turns them into the spec. The annotation *is* the
documentation — nothing about your code is read.

This library inverts that:

| | L5-Swagger / swagger-php | laravel-openapi |
|---|---|---|
| Source of truth | Hand-written `#[OA\*]` / `@OA` annotations | Your typed code, PHPDoc, and conventions |
| What you write | Every operation, parameter, response, schema | Nothing for the common case; an attribute only where code can't express it |
| Drift risk | Annotations and code drift independently | The spec tracks the code, because it *is* the code |
| Coverage check | None | `openapi:lint` reports documentation gaps |

The practical consequence: **type your returns or annotate them to get response schemas.**
Where your app *types* what an action returns (a model, a Spatie `Data` object, an API
Resource, a paginator), the generated response schema is strong and stays in sync for free.
Where a shape exists only at runtime — an untyped `response()->json([...])` assembled in a
service — the generator has nothing to read, and that is exactly where an
[authoring attribute](attributes.md) earns its place.

### The inference ladder

Every part of the document is inferred at the lowest tier that captures the idiom:

- **Tier 0 — reflection & signatures.** Class/method signatures, PHPDoc tags, attributes,
  model metadata (`$casts`, `$hidden`, `$fillable`, …), backed enums, route-model-binding
  types, middleware names. Deterministic and cheap — the library's whole basis.
- **Tier 1 — bounded body reads.** A small whitelist of well-known call shapes (e.g., inline
  `validate()`), adopted selectively and degrading gracefully when the shape isn't matched.
- **Tier 2 — full dataflow.** Tracking values across calls and conditionals. **Refused** —
  fragile and never complete. Where only Tier 2 would close a case, that case is the
  authoring attribute's job.

[Auto-derivation](auto-derivation.md) maps which part of the spec comes from which part of
your code; [Attributes](attributes.md) is the escape-hatch catalog.

### What you gain on top

- **A linter.** `openapi:lint` reports where documentation is missing or thin, gates CI on a
  [coverage threshold](linting.md#documentation-coverage-gate), and ships
  [CI / git-hook recipes](ci.md).
- **Edit-time validation.** Attribute arguments are checked by PHPStan at level 8 as you type,
  not at scan time.
- **Plugins.** Bundled support for [Spatie Data, API Resources, Query Builder, and
  Fractal](plugins.md) reads those libraries' own conventions — plus the
  [SwaggerPhp](plugins.md#swaggerphp) harvester used in the migration below.

### Be honest about the floor

This library targets **modern, well-typed Laravel 12/13 on PHP 8.4** by design. The more your
code leans on types, the less you write by hand. A legacy codebase that returns untyped arrays
everywhere will infer little until those returns are typed — and for that codebase, the
migration below lets you keep your existing annotations working while you get there.

## Migration path (end to end)

The goal is to end up running on inference, with annotations only where they still carry
something the code can't express. You get there without a flag day: the harvester keeps your
current spec correct the entire time, and you delete annotations a tier at a time.

### 1. Enable the harvester so the existing spec stays correct

The bundled [**SwaggerPhp plugin**](plugins.md#swaggerphp) harvests the `#[OA\*]` / `@OA`
annotations your app already wrote and merges them into the generated document. The library
owns the operation skeleton it infers from routes (path, method, parameters, security); the
harvester contributes the authored schemas and response bodies on top. There is no inference
risk — those schemas are authored by you.

```php
// config/openapi.php — uncomment the plugin
'plugins' => [
    // …
    \Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin::class,
],
```

`#[OA\*]` **attributes** are harvested out of the box (swagger-php is already a dependency).
To harvest `@OA` **PHPDoc** annotations as well, also `composer require doctrine/annotations`.
See [Plugins → SwaggerPhp](plugins.md#swaggerphp) for the full behaviour and limits.

At this point `openapi:generate` produces the same document you had under L5-Swagger, plus
whatever inference adds for un-annotated routes. Nothing is lost.

### 2. See what inference already covers

With the harvester on, the **`migration.*`** lint rule family reports annotations the generator
now reproduces on its own — the ones safe to delete. They sit at the cleanup tier (level 4),
so they never run on an ordinary lint; select the family explicitly:

```bash
php artisan openapi:lint --only 'migration.*'
```

The decision is **provenance-based, not name-based**: each authored annotation is compared
against what inference produces for the *same* class or route, and a rule fires only when
inference **subsumes** the annotation — reproduces everything it said and possibly more. A
human `description`, an `additionalProperties: false`, or a runtime-only response shape that
inference can't derive keeps the annotation essential, so it is *not* flagged. See
[Linting → Migration rules](linting.md#migration-rules-migration).

### 3. Remove the redundant annotations

Add `--fix` to delete the flagged annotations from your source:

```bash
php artisan openapi:lint --only 'migration.*' --fix
```

`--fix` removes the whole `#[OA\Schema]` + `#[OA\Property]` set (or the `@OA\Schema` docblock
block) for a redundant model, and the whole `@OA` operation docblock (or `#[OA\Get]` /
`#[OA\Response]` attribute set) for a redundant operation. It never touches an annotation that
is still essential, and never one another surviving annotation still `$ref`s — so a fix
can't dangle a reference. Re-run `openapi:generate` and diff: the document is unchanged.

### 4. Optionally drop the harvester

Once the `migration.*` rules report nothing — every annotation left is one inference genuinely
can't reproduce — you can comment the `SwaggerPhpPlugin` back out and run purely on inference,
shaving the per-generation app scan. Any annotations that remain were the ones worth keeping;
re-express them as [authoring attributes](attributes.md) if you'd rather not keep swagger-php
installed at all, or leave the plugin enabled and keep them as `@OA` blocks — both work.

## See also

- [Plugins → SwaggerPhp](plugins.md#swaggerphp) — harvester behaviour, enabling, limits.
- [Linting → Migration rules](linting.md#migration-rules-migration) — the `migration.*` family.
- [`examples/swagger-php/`](../examples/swagger-php/) — a worked annotated endpoint.
- [CI integration](ci.md) — wire `openapi:lint` into CI and git hooks for the migration.
