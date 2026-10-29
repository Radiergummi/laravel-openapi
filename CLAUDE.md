# CLAUDE.md

## Commands

- `composer check` runs Pint, PHPStan and Pest. CI also gates on `composer rector:check`.
- `composer examples` regenerates the `examples/*/openapi.yaml` snapshots. They are byte-exact
  and depend on the toolchain (symfony/yaml 7 and 8 dump empty maps differently), so regenerate
  them only with dependencies installed from `composer.lock`.

## Workflow

- Specs live in GitHub Issues (label `spec`, Roadmap project), not in `docs/`.
- Start work with a draft PR: branch from `main`, empty commit, `gh pr create --draft` with the
  implementation plan as the body, `Closes #<issue>`, and Moritz as reviewer. Record deviations
  from the plan as PR comments.
- Behaviour changes need tests, an update to the relevant `docs/` page, and a `CHANGELOG.md`
  entry under `[Unreleased]`.

## Invariants

- The whole stage order lives in `Support\Generator\BaselineRegistration::assemble()`, which
  seals the registry. Out-of-band `addX()` calls throw `RegistrySealedException` on purpose.
- The Core plugin only reads vanilla Laravel patterns. The package must work with Core disabled,
  so config-driven, plugin-agnostic code (for example `openapi.overrides` and its lint rules)
  goes in `src/Support/` or `src/Lint/`, never in `src/Plugins/Core/`.
- Pipeline services are scoped bindings, not singletons: they hold per-run state and Octane
  resets scoped bindings between requests.
- Inference ladder: prefer Tier 0 (reflection, signatures, PHPDoc, model metadata). Tier 1 is a
  small whitelist of call shapes in a method body, degrading gracefully with a log. Tier 2
  (dataflow across calls) is refused; such cases are the job of an attribute in
  `src/Attributes/`. An attribute that restates what Tier 0/1 could infer is a smell.

## Style

- No abbreviations in class, method or variable names.
- Comments explain why, not how. No plan steps, tier or issue numbers, and no em-dashes. Use
  `// region` / `// endregion` for sections. Never remove type-bearing docblock tags.
- Tag classes outside the documented extension surface `@internal`.
