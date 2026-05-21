# Test suite cleanup tracker

> **Append any findings — including pre-existing or unrelated ones — to §5
> Incidental findings as you spot them.** Don't fix mid-stream; record them so
> they can be triaged later.

Working document for the test-suite review started 2026-05-21. Tracks open
issues; completed work is summarised in §4.

Conventions:
- `[ ]` open, `[x]` done, `[~]` in progress, `[-]` dropped (with reason).
- Reference commit SHA in the parenthetical when an item lands.
- New issues found mid-cleanup go under §5 Incidental findings.

---

## 1. Public API the suite should target

- Artisan commands: `openapi:generate`, `openapi:lint`, `openapi:clear`.
- Generated OpenAPI document (YAML/JSON content).
- User-facing attributes in `src/Core/Attributes/`.
- Config surface (`config/openapi.php`).
- `Core\Registry\Plugin` interface.
- `Finding` (id, level, message) — consumed by plugins and formatters.

Everything else (`SpecTreeBuilder`, `SpecTreeWalker`, `RuleRegistry`,
`LintContext`, `OperationBuilder`, `RouteIntrospector`, `ActionDescriptor`,
extractors, `ReflectionAttributeCache`, `ResolvedSchema`, …) is internal.

---

## 2. Open items

### 2.1. Bypassing public surface — rewrite to drive `generateSpec()`

Feature tests that instantiate internal pipeline classes directly. Rewrite to
register a route + run `generateSpec()`, then assert on `$spec['paths']` /
`$spec['components']`.

- [x] `tests/Feature/ComponentizedResponsesTest.php:126-167` — deleted the two
  OAPI-021 cases; folded a `Conflict` (409) component+ref assertion into the
  first OAPI-018 case so the exception-derived path is asserted via
  `generateSpec()`. The 418 inlining path was already covered by OAPI-018 #2.
- [x] `tests/Feature/DataDiscriminatorTest.php:30-46` — replaced
  `oapi027DataRegistry()` helper with a `ShapeFixtureController` typed on
  `BaseShapeData`; all six OAPI-027 cases now drive through
  `generateSpec()['components']['schemas']`.
- [x] `tests/Feature/ExampleFileLoaderTest.php:36-60` — deleted the three
  `ExampleFileLoader` unit cases. The OAPI-022 feature case at the bottom of
  the file covers the user-visible flow end-to-end.
- [x] `tests/Feature/ExtensionsTest.php:188-198` — deleted. No production code
  calls `ComponentSchemaRegistry::registerNamed()`; the SchemaContext branch
  for null `sourceClass` has no public path to exercise (recorded in §5).

### 2.2. Brittle index-based assertions

`$x[0]` / `$x[1]` where order isn't part of the contract. Convert to membership
or name-keyed lookups.

- [x] `tests/Feature/AuthoringAttributesTest.php:89-94` — `$headers[0]` /
  `$headers[1]` after filtering parameters by `in: header`. (ddd41d1)
- [x] `tests/Feature/QueryParamClassLevelTest.php:54-62` — `array_search` +
  position assertions. (ddd41d1)
- [x] `tests/Unit/Plugins/Fractal/FractalEnvelopeFactoryTest.php:28, 80, 89, 100`
  — `$schema->properties[0|1|2]` direct indexing. (ddd41d1)
- [x] `tests/Unit/Plugins/ApiResources/ResourceEnvelopeFactoryTest.php:35-36, 49`
  — same pattern. (ddd41d1)

### 2.3. Low-value tests — delete or rewrite

- [ ] **PHP attribute target reflection.** Reflecting `#[Attribute]` flags to
  assert on `Attribute::TARGET_*` tests PHP itself, not the package:
  `tests/Unit/Attributes/ResponseFieldTest.php:42-45`,
  `tests/Unit/Plugins/ApiResources/Attributes/ResourceFieldTest.php:33-38`, and
  the matching Fractal/QueryBuilder attribute tests.
- [ ] **Trivial property echo.** Construct an object, assert its readonly
  properties round-trip — language behaviour, not package behaviour:
  `tests/Unit/QueryParamTest.php:17-30`,
  `tests/Unit/Attributes/PathParamTest.php`,
  `tests/Unit/Attributes/DeprecatedTest.php`, parts of `ResponseFieldTest`.

### 2.4. Hardcoded fixture paths

- [ ] `tests/Feature/ExampleFileLoaderTest.php:37, 49, 56` — string-literal
  `'tests/Fixtures/OpenApi/example_payloads/create_project.json'`.
- [ ] `tests/Feature/ExamplesTest.php:33, 50, 59` —
  `dirname(__DIR__, 2) . "/examples/{$flavor}/openapi.yaml"`.

Resolve via `__DIR__` + a fixture-path helper; survives directory reorgs.

### 2.5. Misplaced fixture controllers

Four fixture controllers live under `tests/Feature/` instead of
`tests/Fixtures/`:

- [ ] `tests/Feature/AuthoringFixtureController.php`
- [ ] `tests/Feature/QueryParamClassFixtureController.php`
- [ ] `tests/Feature/ValidationRulesFixtureController.php`
- [ ] `tests/Feature/ResponseHeaderClassFixtureController.php`

Move under `tests/Fixtures/` and update imports.

### 2.6. DRY — repeated property-lookup loops

Same `foreach ($items as $i) if ($i->name === $needle) { … }` pattern repeated
across plugin tests:

- [ ] `tests/Unit/Plugins/ApiResources/SchemaFromResourceTest.php:36-52`
- [ ] `tests/Unit/Plugins/Fractal/SchemaFromTransformerTest.php:46-61`
- [ ] `tests/Unit/Plugins/QueryBuilder/QueryBuilderParameterResolverTest.php:50-77`

Add a `findByName($items, string $name)` helper to `tests/Support/`.

### 2.7. Toolchain gaps (Testbench / Pest / PHPUnit / Spatie-style)

High-value, low-effort first; nice-to-have toward the end.

- [ ] **Add `spatie/pest-plugin-snapshots`** and swap
  `tests/Feature/ExamplesTest.php`'s hand-rolled byte-comparison onto
  `->toMatchSnapshot()`. Each `examples/{flavor}/openapi.yaml` becomes a
  snapshot; regressions show up as diffs in PR reviews. The byte-compare is
  the strongest argument for snapshots — that's exactly what they're for.
- [ ] **Add `composer test-coverage` script** (`pest --coverage --coverage-clover build/logs/clover.xml --coverage-html build/coverage`).
  Spatie convention; pairs with the existing `composer test` (which already
  uses `--no-coverage`). Trivial.
- [ ] **Add `<coverage>` block to `phpunit.xml`** with `<report><clover/>` and
  `<html/>`. Without it, `test-coverage` has nowhere to write to.
- [ ] **Add `--log-junit build/junit.xml` to the CI test step** in
  `.github/workflows/tests.yml`. Lets GitHub render per-test status on PRs.
- [ ] **Add testsuites that match the in-test groups.** Tests tag themselves
  with `->group('openapi', 'lint')`, but `phpunit.xml` only has Unit/Feature
  testsuites — the groups can be filtered with `--group=lint` but aren't
  surfaced as named suites. Optional; only useful if CI wants a "lint-suite
  only" job.
- [ ] **Replace the `@mkdir` / `@copy` shadowing in `tests/TestCase.php::setUp`.**
  The leading `@` suppresses real errors; if the source directory disappears,
  the next failure will be opaque. Either use a proper publishable-asset
  mechanism or fail loudly when the source is missing.
- [ ] **Extend PHPStan to `tests/`** at a lower level (e.g. level 5). Catches
  fixture/factory bugs that would otherwise hide real issues. Configure a
  separate path-scoped block in `phpstan.neon`.
- [ ] **Consider `pestphp/pest-plugin-arch`** to enforce the Core/Plugins
  one-way dependency stated in `CLAUDE.md` ("`src/Core/` must not depend on
  any plugin"). One arch test would prevent a whole class of regressions.
- [ ] **Rename `composer lint` → `composer format` (or alias)?** Spatie
  convention is `format` for Pint. `lint` reads as "report violations" but the
  current `composer lint` actually rewrites files (it invokes `pint` directly,
  not `pint --test`). Either alias or rename to match the verb to the action.

Explicitly **not** pursuing:
- Workbench directory — useful for packages with UIs / Blade views; this
  package only generates files, so the value is marginal.
- Moving `beforeEach()` route registration into a Testbench `defineRoutes()`
  hook — the agent flagged this, but inline route registration per test is
  idiomatic Pest and keeps each test self-contained. Splitting setup across
  two locations would be worse.
- `@covers` annotations — irrelevant for integration-shaped tests.
- Rector — Pint + PHPStan already cover what we need.

---

## 3. Reference — keep, don't sweep

Unit tests with legitimate value that should not be deleted:

- `tests/Unit/Core/Routing/DocCommentParserTest.php` — pure parser, many edge cases
- `tests/Unit/Core/Routing/ReturnTypeExtractorTest.php` — docblock regex + generics
- `tests/Unit/Core/Routing/ThrowsExtractorTest.php` — `@throws` parsing
- `tests/Unit/Attributes/*` — public authoring attributes
- `tests/Unit/Console/ClearCommandTest.php` — drives the artisan command
- `tests/Unit/Core/Extractors/PayloadParameterScannerTest.php` — non-trivial reflection
- `tests/Unit/Core/Extractors/FieldDescriptorTest.php` — regression-anchored
- `tests/Unit/Core/Generator/PaginatorSchemaFactoryTest.php` — pure shape building
- `tests/Unit/Lint/FindingTest.php` — public API for plugin/formatter authors
- `tests/Unit/Lint/IdentifierCaseTest.php` — regex patterns
- `tests/Unit/Lint/RuleFixHintGuardTest.php` — meta-guard
- `tests/Unit/Lint/Rules/Meta*Test.php` — three meta-rule unit tests
- `tests/Unit/Lint/Formatters/JsonFormatterTest.php` — pure logic, no feature coverage
- `tests/Unit/Lint/Formatters/GithubFormatterTest.php` — percent-encoding edge cases
- `tests/Unit/Lint/Rules/*` — per-rule semantics; `LintCommandTest` only asserts on
  command behaviour, not rule output. These tests are the contract guard.

---

## 4. Past work (2026-05-21 first pass)

| Tranche | Outcome |
|---|---|
| Delete pipeline-internal unit tests | 19 files removed (`SpecTreeBuilderTest`, `SpecTreeWalkerTest`, `RuleRegistryTest`, `SuppressionCollectorTest`, lint formatters/context/collector unit tests, `Core/Routing/ActionDescriptorTest`, `Core/Lint/ReflectionAttributeCacheTest`, `Core/Generator/{OperationBuilder,CoreQueryParameterResolver}Test`, `Core/Registry/{OpenApiRegistry,ResolvedSchema,CoreRegistration}Test`, `Core/Routing/UriParameterDescriptorTest`); ~3.5 kLOC. |
| Trim, don't delete | `SecurityExtractorTest` 220 → 130 L (kept config-driven coverage); `RouteIntrospectorTest` 108 → 47 L; `UriParameterResolverTest` 119 → 95 L. |
| Move | `DataSyntheticPayloadBuilderTest` → `Unit/Plugins/SpatieData/`. |
| Rewrite as feature tests | `DataResponseResolverTest`, `DataClassRequestSchemaResolverTest`, `SchemaFromDataClassTest`. 795 → 355 L. |
| Delete fully-covered | `RequestBodyExtractorTest`, `StandardResponsesExtractorTest`. |
| Rename batch-named files | `P1BatchTwoTest` → `ActionRequestBodyTest`; `P2BatchTest` split (`ComponentizedResponsesTest`, `OperationTagsTest`, `ExampleFileLoaderTest`); `Oapi024Test` → `StreamingResponseTest`; `Oapi027Test` → `DataDiscriminatorTest`; `Oapi035Test` → `ScopedDiBindingsTest`; `Oapi031034Test` split (`DeprecatedFieldTest`, `EnumCaseDescriptionTest`). |
| Rule-test factory migration | Extended `OperationNodeFactory` with `makeOperation/Response/RequestBody/ComponentSchema/Parameter/QueryParameter/Header/Example/Link/Field/Webhook` + `emptyContext()`. Migrated all 82 rule tests in 10 batches; folded duplicate cases into `with()` datasets. |
| Shared `generateSpec()` helper | Added to `tests/Pest.php`; swapped 66 sites across 20 feature files. |
| `oneOf` assertions | Switched index-based assertions to membership across `NullableSchemaTest`, `FieldDescriptorTest`, etc. |
| `return null;` + `@phpstan-ignore-next-line` fixture sweep | 5 files cleaned; remaining suppressions are legitimate non-fixture cases. |

End-of-pass state: 1230 → 1103 tests, 3172 → 2965 assertions. Reduction is from
deletions, not coverage loss — backstopped by feature tests (command behaviour
via `LintCommandTest`, rule semantics via per-rule unit tests).
`composer test && composer lint && composer analyse` green.

---

## 5. Incidental findings

Append issues spotted mid-cleanup here. Include file + reason; promote to its
own section if a pattern emerges.

- **`tests/Unit/Lint/RuleFixHintGuardTest` greps source for `'fixHint:'`** by
  iterating `CoreRegistration::RULES`, reading each rule's file via
  `ReflectionClass::getFileName()`. Tests a code-style convention, not
  behaviour. Better: put `fixHint()` on the rule interface and assert each rule
  returns non-empty. *(Surfaced in 2026-05-21 second-pass review; intentionally
  not in §2 because §3 currently lists this file as keep — re-evaluate.)*
- **`tests/Feature/Lint/RuleCatalogCoverageTest`** is a shape test only — it
  pins `id` non-empty + unique and `level` ≥ 0, but does not pin specific
  id/level values. The per-rule `it('reports its id and level', …)` cases are
  the only contract guard for rule-id stability and default severity. Keep
  them.
- **`tests/Unit/Core/Generator/OpenApiGeneratorTest`** is the right shape and
  should be the template for what feature tests look like: define route →
  `app(OpenApiGenerator::class)->generate()` → assert on parsed YAML.
- **`request.empty` is observable from feature flow via scoped binding swap.**
  `tests/Feature/Lint/RequestEmptyTest` swaps `FindingsCollector` on the
  container before calling `OpenApiGenerator::generate()` and inspects the
  collector after. Supported way to assert on extractor-emitted findings
  end-to-end.
- **Spatie paginator envelope coverage was resolver-only before the rewrite.**
  `PaginatorResponseTest` covers Laravel's `LengthAwarePaginatorContract` /
  `CursorPaginatorContract`, not Spatie's `PaginatedDataCollection<X>` /
  `CursorPaginatedDataCollection<X>`. The rewritten `DataResponseResolverTest`
  is now the only feature-level guard for those envelopes.
- **`OperationNodeFactory` is the right home for shared lint-test fixtures.**
  Extending it (rather than adding a separate `LintNodeFactory`) keeps the
  test-support surface flat. `makeOperation()` auto-links children so callers
  don't manually `linkParent()`; pass `responses: []` when a rule must not see
  any (webhooks, certain failure modes).
- **`emptyContext()` deliberately ships an empty `OA\OpenApi`.** Don't bloat it
  with rawSpec knobs — when a rule walks `rawSpec` (e.g.
  `InfoDescriptionMissing`, `ServerInvalidUrl`, `ComponentOrphaned`,
  `RefBroken`), use a per-file `*Findings(...)` helper or local
  `make*Context()` builder instead.
- **`*Findings(...)` per-file helpers** are the pattern for rawSpec-heavy
  api-level rules — they take domain inputs (schema names, refs, url, …) and
  return `iterator_to_array($rule->checkApi(...))`. Keeps per-test bodies down
  to a single line without leaking rawSpec construction into the factory.
- **`ComponentSchemaRegistry::registerNamed()` has no production caller.** No
  code in `src/` (Core or plugins) currently registers a named schema; the
  method exists as an extension-API contract for plugin authors building shared
  envelopes (e.g. JSON:API error wrappers). Implication: the SchemaContext
  branch with null `sourceClass` is untestable through the public surface
  today. Reintroduce a coverage test only once a plugin starts registering
  named schemas. *(Found while removing the ExtensionsTest named-schema case
  in §2.1.)*
- **Pre-existing PhpStorm weak warnings across test files** — unhandled
  `\PHPUnit\Framework\ExpectationFailedException`, unhandled
  `\Symfony\Component\Yaml\Exception\ParseException`, "closure can be declared
  static", "multiple expectations can be chained". Hundreds of instances;
  project-wide and not real issues for test code. Could be silenced via
  `phpstorm.meta.php` or IDE config if noisy.
