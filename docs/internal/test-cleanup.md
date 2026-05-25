# Test suite cleanup tracker

> **Append any findings—including pre-existing or unrelated ones—to §5
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
- `Finding` (id, level, message)—consumed by plugins and formatters.

Everything else (`SpecTreeBuilder`, `SpecTreeWalker`, `RuleRegistry`,
`LintContext`, `OperationBuilder`, `RouteIntrospector`, `ActionDescriptor`,
extractors, `ReflectionAttributeCache`, `ResolvedSchema`, …) is internal.

---

## 2. Open items

### 2.1. Bypassing public surface—rewrite to drive `generateSpec()`

Feature tests that instantiate internal pipeline classes directly. Rewrite to
register a route + run `generateSpec()`, then assert on `$spec['paths']` /
`$spec['components']`.

- [x] `tests/Feature/ComponentizedResponsesTest.php:126-167`—deleted the two
  OAPI-021 cases; folded a `Conflict` (409) component+ref assertion into the
  first OAPI-018 case so the exception-derived path is asserted via
  `generateSpec()`. The 418 inlining path was already covered by OAPI-018 #2.
- [x] `tests/Feature/DataDiscriminatorTest.php:30-46`—replaced
  `oapi027DataRegistry()` helper with a `ShapeFixtureController` typed on
  `BaseShapeData`; all six OAPI-027 cases now drive through
  `generateSpec()['components']['schemas']`.
- [x] `tests/Feature/ExampleFileLoaderTest.php:36-60`—deleted the three
  `ExampleFileLoader` unit cases. The OAPI-022 feature case at the bottom of
  the file covers the user-visible flow end-to-end.
- [x] `tests/Feature/ExtensionsTest.php:188-198`—deleted. No production code
  calls `ComponentSchemaRegistry::registerNamed()`; the SchemaContext branch
  for null `sourceClass` has no public path to exercise (recorded in §5).

### 2.2. Brittle index-based assertions

`$x[0]` / `$x[1]` where order isn't part of the contract. Convert to membership
or name-keyed lookups.

- [x] `tests/Feature/AuthoringAttributesTest.php:89-94`—`$headers[0]` /
  `$headers[1]` after filtering parameters by `in: header`. (ddd41d1)
- [x] `tests/Feature/QueryParamClassLevelTest.php:54-62`—`array_search` +
  position assertions. (ddd41d1)
- [x] `tests/Unit/Plugins/Fractal/FractalEnvelopeFactoryTest.php:28, 80, 89, 100`
 —`$schema->properties[0|1|2]` direct indexing. (ddd41d1)
- [x] `tests/Unit/Plugins/ApiResources/ResourceEnvelopeFactoryTest.php:35-36, 49`
 —same pattern. (ddd41d1)

### 2.3. Low-value tests—delete or rewrite

- [x] **PHP attribute target reflection.** Removed the `TARGET_*` / `IS_REPEATABLE`
  reflection cases from `ResponseFieldTest`, `RequestFieldTest`,
  `ResponseHeaderTest`, `ResourceFieldTest`, `TransformerFieldTest`,
  `TransformerIncludeResponseTest`, `AllowedFilterTest` (and the matching ones
  in the deleted attribute tests below). Unused `Attribute` / `ReflectionClass`
  imports cleaned up where they were the only consumers.
- [x] **Trivial property echo.** Deleted `tests/Unit/Attributes/PathParamTest.php`
  and `tests/Unit/Attributes/DeprecatedTest.php` outright (all three / three
  cases were FieldAttribute-passthrough echoes, target reflection, or
  language-feature checks—`PathParam` descriptor enrichment is covered via
  `UriParametersExtractorTest`; `Deprecated` is covered via `DeprecatedFieldTest`
  and the lint rules). Removed the first two cases from `QueryParamTest`
  (lines 17-30) and the `exposes the conditional flag` echo from
  `ResponseFieldTest`.

### 2.4. Hardcoded fixture paths

- [x] `tests/Feature/ExamplesTest.php:33, 50, 59`—extracted the
  `dirname(__DIR__, 2) . "/examples/{$flavor}/openapi.yaml"` repetition into a
  file-local `$exampleYaml` closure; each test `use ($exampleYaml)`s it.
- [-] `tests/Feature/ExampleFileLoaderTest.php`—tracker referenced the three
  pre-§2.1 unit cases (now deleted). The single remaining literal is inside an
  `#[Example(file: '…')]` attribute argument, which PHP requires to be a
  constant expression—a fixture-path helper can't be used there. Left as-is.

### 2.5. Misplaced fixture controllers

- [x] All four moved under `tests/Fixtures/` (namespace
  `Radiergummi\OpenApi\Tests\Fixtures`); `git mv` preserves history. Consumer
  test files (`AuthoringAttributesTest`, `QueryParamClassLevelTest`,
  `DataValidationRulesTest`, `ResponseHeaderClassLevelTest`) gained explicit
  `use` imports. Pint pruned the now-unused imports in the moved files.

### 2.6. DRY—repeated property-lookup loops

- [x] All three sites replaced with PHP 8.4's native `array_find()` (already
  used in `src/Core/Lint/Rules/SchemaConstraintsMissing.php`,
  `PayloadParameterScanner.php`). Skipped adding a `findByName($items, $name)`
  helper because (a) `array_find` covers the use case in one line, (b) the
  loops match on different properties (`$item->name`, `$item->schema`,
  `$item->property`) so a name-only helper wouldn't cover the schema-registry
  cases, and (c) adding a thin wrapper when the language already exposes the
  operation is the kind of indirection the §2.6 spirit pushes against.

### 2.7. Toolchain gaps (Testbench / Pest / PHPUnit / Spatie-style)

- [x] **`composer test-coverage` script**—added (clover + html under `build/`).
- [x] **`<coverage>` block in `phpunit.xml`**—clover + html report targets
  paired with `test-coverage`.
- [x] **`--log-junit build/junit.xml` in CI**—added to the `pest` invocation
  in `.github/workflows/tests.yml`. `build/` is already in `.gitignore`.
- [x] **`composer format` alias**—added as an alias of `lint`. Kept `lint`
  in place to avoid breaking existing muscle memory; `format` exists for
  Spatie-convention callers.
- [x] **`@mkdir` / `@copy` shadowing in `tests/TestCase.php::setUp`**—the
  leading `@`s are gone; missing source dir, failed mkdir, failed copy each
  throw `RuntimeException` with the offending path.
- [x] **`pestphp/pest-plugin-arch`**—added as a dev dep (constraint
  `^3.0 || ^4.0` to match the existing `pest:^3.8 || ^4.7` matrix).
  `tests/Arch/CoreBoundaryTest.php` enforces the CLAUDE.md
  "src/Core/ must not depend on any plugin" rule. New `Arch` testsuite
  registered in `phpunit.xml`.
- [-] **Testsuites matching in-test groups**—tracker marks this optional
  ("only useful if CI wants a lint-suite-only job"). Skipped; the Unit /
  Feature / Arch suites are enough today.
- [-] **Extend PHPStan to `tests/`**—PHPStan can't natively run different
  levels per path, so this requires a separate `phpstan-tests.neon` (or a
  baseline file). Both options are real work, not a flag flip; deferred.
  Recording here for follow-up.
- [-] **`spatie/pest-plugin-snapshots`**—deferred. The `examples/{flavor}/openapi.yaml`
  files are dual-purpose (CI snapshot AND public reference docs users link
  to). Moving them under `tests/.pest/snapshots/` would lose the second
  purpose; keeping byte-compare avoids that trade-off until we decide what
  the public surface should look like.

Explicitly **not** pursuing:
- Workbench directory—useful for packages with UIs / Blade views; this
  package only generates files, so the value is marginal.
- Moving `beforeEach()` route registration into a Testbench `defineRoutes()`
  hook—the agent flagged this, but inline route registration per test is
  idiomatic Pest and keeps each test self-contained. Splitting setup across
  two locations would be worse.
- `@covers` annotations—irrelevant for integration-shaped tests.
- Rector—Pint + PHPStan already cover what we need.

---

## 3. Reference—keep, don't sweep

Unit tests with legitimate value that should not be deleted:

- `tests/Unit/Core/Routing/DocCommentParserTest.php`—pure parser, many edge cases
- `tests/Unit/Core/Routing/ReturnTypeExtractorTest.php`—docblock regex + generics
- `tests/Unit/Core/Routing/ThrowsExtractorTest.php`—`@throws` parsing
- `tests/Unit/Attributes/*`—public authoring attributes
- `tests/Unit/Console/ClearCommandTest.php`—drives the artisan command
- `tests/Unit/Core/Extractors/PayloadParameterScannerTest.php`—non-trivial reflection
- `tests/Unit/Core/Extractors/FieldDescriptorTest.php`—regression-anchored
- `tests/Unit/Core/Generator/PaginatorSchemaFactoryTest.php`—pure shape building
- `tests/Unit/Lint/FindingTest.php`—public API for plugin/formatter authors
- `tests/Unit/Lint/IdentifierCaseTest.php`—regex patterns
- `tests/Unit/Lint/RuleFixHintGuardTest.php`—meta-guard
- `tests/Unit/Lint/Rules/Meta*Test.php`—three meta-rule unit tests
- `tests/Unit/Lint/Formatters/JsonFormatterTest.php`—pure logic, no feature coverage
- `tests/Unit/Lint/Formatters/GithubFormatterTest.php`—percent-encoding edge cases
- `tests/Unit/Lint/Rules/*`—per-rule semantics; `LintCommandTest` only asserts on
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
deletions, not coverage loss—backstopped by feature tests (command behaviour
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
  not in §2 because §3 currently lists this file as keep—re-evaluate.)*
- **`tests/Feature/Lint/RuleCatalogCoverageTest`** is a shape test only—it
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
  with rawSpec knobs—when a rule walks `rawSpec` (e.g.
  `InfoDescriptionMissing`, `ServerInvalidUrl`, `ComponentOrphaned`,
  `RefBroken`), use a per-file `*Findings(...)` helper or local
  `make*Context()` builder instead.
- **`*Findings(...)` per-file helpers** are the pattern for rawSpec-heavy
  api-level rules—they take domain inputs (schema names, refs, url, …) and
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
- **Pre-existing PhpStorm weak warnings across test files**—unhandled
  `\PHPUnit\Framework\ExpectationFailedException`, unhandled
  `\Symfony\Component\Yaml\Exception\ParseException`, "closure can be declared
  static", "multiple expectations can be chained". Hundreds of instances;
  project-wide and not real issues for test code. Could be silenced via
  `phpstorm.meta.php` or IDE config if noisy.
