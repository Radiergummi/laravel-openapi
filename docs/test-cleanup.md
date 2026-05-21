# Test suite cleanup tracker

Working document for the test-suite review started 2026-05-21. Captures every
issue found during the review and any incidental issues spotted while executing
fixes (including pre-existing or unrelated ones). Check items off as they land.

Conventions:
- `[ ]` open, `[x]` done, `[~]` in progress, `[-]` dropped (with reason).
- Reference commit SHA in the parenthetical when an item lands.
- New issues found mid-cleanup go under **Incidental findings**.

---

## 1. Public API the suite should target

For reference while triaging:
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

## 2. Delete — already covered end-to-end

Each of these tests internal pipeline machinery that is exercised by the
feature suite running through the artisan commands.

- [x] `tests/Unit/Lint/Tree/SpecTreeBuilderTest.php` (644 L) — hand-built `OA\OpenApi` graphs, brittle JSON Pointer asserts
- [x] `tests/Unit/Lint/Tree/SpecTreeWalkerTest.php` (785 L) — inline `Rule` impls + manual `ApiNode` trees
- [x] `tests/Unit/Lint/RuleRegistryTest.php` (265 L) — severity overrides belong in a config-driven feature test
- [x] `tests/Unit/Lint/SuppressionCollectorTest.php` — `#[IgnoreLint]` parsing; assert via linter
- [x] `tests/Unit/Lint/LintContextTest.php` — trivial data container
- [x] `tests/Unit/Lint/ArrayFindingsCollectorTest.php` — container only
- [x] `tests/Unit/Lint/LoggingFindingsCollectorTest.php` — over-mocked logger calls
- [x] `tests/Unit/Lint/Formatters/CliFormatterTest.php` — covered by `tests/Feature/Lint/Formatters/CliFormatterTest.php`
- [-] `tests/Unit/Lint/Formatters/JsonFormatterTest.php` — **kept**: schema/summary structure has no feature coverage; pure logic worth a unit test
- [-] `tests/Unit/Lint/Formatters/GithubFormatterTest.php` — **kept**: percent-encoding edge cases (commas, colons, newlines, `%`) have no feature coverage
- [x] `tests/Unit/Core/Lint/ReflectionAttributeCacheTest.php` — pure caching internals
- [x] `tests/Unit/Core/Routing/ActionDescriptorTest.php` — private cache + reflection probing
- [-] `tests/Unit/Core/Routing/RouteIntrospectorTest.php` — **keep, trim later**: defensive non-existent-controller test has unique value; see §7
- [-] `tests/Unit/Core/Routing/UriParameterResolverTest.php` — **keep, trim later**: defensive cases (required ctor arg, throwing key resolver) have unique value; see §7
- [x] `tests/Unit/Core/Generator/OperationBuilderTest.php` — smoke path redundant with `P1BatchTwoTest` / `AuthoringAttributesTest`
- [x] `tests/Unit/Core/Generator/CoreQueryParameterResolverTest.php` — exercised by query-param feature tests
- [x] `tests/Unit/Core/Registry/OpenApiRegistryTest.php` — container wiring
- [x] `tests/Unit/Core/Registry/ResolvedSchemaTest.php` — data carrier
- [x] `tests/Unit/Core/Registry/CoreRegistrationTest.php` — provider internals
- [-] `tests/Unit/Extractors/SecurityExtractorTest.php` (220 L) — **keep, trim later**: middleware-group expansion uses Mockery and should go, but config-driven `security_schemes` / `security_default_scheme` tests (lines 112–220) are the only coverage for that public API; see §7
- [-] `tests/Unit/Lint/Rules/*` — **REVISED: keep, refactor**: the feature suite (`tests/Feature/Lint/LintCommandTest.php`) asserts on linter command behaviour (exit codes, suppression, config) but does **not** assert per-rule semantics. These unit tests are the only behaviour coverage for individual rules. Move shared cleanups to §7.

Sizing actually removed in this pass: ~3.5 kLOC across 12 files plus orphaned fixtures (`tests/Unit/Core/Lint/Fixtures/Rac*.php`, `tests/Unit/Core/Routing/Fixtures/{AttributedController,Alpha,Beta}.php`).

---

## 3. Keep — legitimate unit-level value

No action needed; listed so they aren't accidentally swept.

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
- `tests/Unit/Lint/Rules/MetaRulesTest.php`
- `tests/Unit/Lint/Rules/MetaSuppressionStaleTest.php`
- `tests/Unit/Lint/Rules/MetaTooManySuppressionsTest.php`

---

## 4. Misplaced — move

- [x] `tests/Feature/Plugins/SpatieData/DataSyntheticPayloadBuilderTest.php` → `tests/Unit/Plugins/SpatieData/` (no route, no generation, pure logic)

---

## 5. Rewrite as feature tests against the generated doc

These live in `tests/Feature/*` but assert on intermediate resolver/extractor
objects rather than on the generated YAML. Drive them through
`openapi:generate` and assert on the doc.

- [x] `tests/Feature/RequestBodyExtractorTest.php` (208 L) — **deleted**: every case covered elsewhere. Data class direct type-hint → new `DataClassRequestSchemaResolverTest` feature test; FormRequest application/json + multipart → `FormRequestSchemaTest`; SimpleFormRequest plain JSON → `FormRequestSchemaTest` (RemoteMediaRequest); `request.empty` × 3 → `tests/Feature/Lint/RequestEmptyTest.php` (full feature flow via scoped `FindingsCollector` swap).
- [x] `tests/Feature/StandardResponsesExtractorTest.php` — **deleted**: all three cases were covered elsewhere (`#[Throws]` 418 by `ComponentizedResponsesTest` OAPI-021; `exceptionMap` 404 by `AuthoringAttributesTest`; no-throws by every test with non-throwing actions). Also dropped the orphan `tests/Fixtures/FixtureErrorResponseFactory.php`. Kept `StandardResponsesFixtureController.php` — still used by 3 other tests.
- [x] `tests/Feature/Plugins/SpatieData/DataResponseResolverTest.php` (209 L → 100 L) — rewritten as feature test: routes + `app(OpenApiGenerator::class)->generate()` + YAML assertions for single Data $ref, `DataCollection<X>` array items, `PaginatedDataCollection` length-aware envelope, `CursorPaginatedDataCollection` cursor envelope. Dropped internal "returns null" cases (resolver dispatch is implicit in the envelope tests).
- [x] `tests/Feature/Plugins/SpatieData/DataClassRequestSchemaResolverTest.php` (166 L → 50 L) — rewritten as feature test: direct `Data` type-hint produces `application/json` request body with `$ref`; component schema registered. Dropped Action indirection case (covered by `ActionRequestBodyTest`) and internal null cases.
- [x] `tests/Feature/Plugins/SpatieData/SchemaFromDataClassTest.php` (212 L → 205 L) — rewritten as feature test: each `#[RequestField]`, `#[MapInputName]`, self-ref, and same-basename-disambiguation case now drives `openapi:generate` and asserts on `components.schemas.*` in YAML.
- [ ] `tests/Feature/Oapi031034Test.php` — split into the three OAPIs (031, 034, 043) or fold into `SchemaFromDataClassTest`; drop the batch-dump name

---

## 6. Batch-named tests

Focused enough to keep, but renamed to describe behaviour rather than Linear
tickets. OAPI-NNN ticket references retained inside `it(…)` titles as
regression-trail breadcrumbs.

- [x] `tests/Feature/P1BatchTwoTest.php` → `ActionRequestBodyTest.php` (OAPI-010/011)
- [x] `tests/Feature/P2BatchTest.php` — split into:
  - `ComponentizedResponsesTest.php` (OAPI-018 + OAPI-021)
  - `OperationTagsTest.php` (OAPI-020)
  - `ExampleFileLoaderTest.php` (OAPI-022)
- [x] `tests/Feature/Oapi024Test.php` → `StreamingResponseTest.php`
- [x] `tests/Feature/Oapi027Test.php` → `DataDiscriminatorTest.php`
- [x] `tests/Feature/Oapi035Test.php` → `ScopedDiBindingsTest.php`
- [x] `tests/Feature/Oapi031034Test.php` — split into:
  - `DeprecatedFieldTest.php` (OAPI-031 + OAPI-043)
  - `EnumCaseDescriptionTest.php` (OAPI-034)

---

## 7. Anti-patterns to sweep

- [-] Drop the `it('reports its id and level', …)` test — **kept**: rule `id()` and `level()` are the public contract (config keys, default severity). `RuleCatalogCoverageTest` only verifies shape (non-empty, unique, ≥0), not specific values. These per-rule pins prevent silent renames/level changes.
- [~] Consolidate duplicate rule-test cases — batches 1–8 done: batch 1 (10 description-style rule tests) folded null/empty/whitespace `it(…)` triples into single `with(…)` datasets; batch 2 (6 operation-level rule tests: `OperationIdMissing`, `OperationIdInvalidChars`, `OperationIdDuplicate`, `OperationIdNamingInconsistent`, `OperationSummaryEqualsDescription`, `OperationTagMissing`) folded valid/invalid charset pairs and one-side-null pairs into datasets and dropped two redundant cases (`OperationTagMissing`'s "no tags" / "empty array" dupe, `OperationIdNamingInconsistent`'s second "default still passes dot" case); batch 3 (6 naming-inconsistent rule tests: `ComponentNameNamingInconsistent`, `FieldNameNamingInconsistent`, `HeaderNameNamingInconsistent`, `ParameterNameNamingInconsistent`, `PathSegmentNamingInconsistent`, `TagNameNamingInconsistent`) folded valid/invalid case-style pairs and the six reserved-query-name cases (`ParameterNameNamingInconsistent`) into datasets; batch 4 (7 parameter/query-parameter rule tests: `ParameterDuplicateName`, `ParameterExampleConflict`, `ParameterExampleMissing`, `ParameterPathMustBeRequired`, `ParameterQueryArrayNoExplode`, `ParameterQueryNoSchema`, `QueryParamDuplicate`) folded only-singular/only-plural/neither and string/integer/enum-only no-schema cases into datasets; batch 5 (9 response/request-body rule tests: `ResponseDuplicateStatus`, `ResponseExampleMissing`, `ResponseNoError`, `ResponseNoSuccess`, `ResponseRedirectWithoutLocation`, `ResponseStatusUnconventional`, `RequestBodyExampleMissing`, `RequestBodyNoContent`, `RequestBodyOnGetOrDelete`) folded 2xx-shape, redirect-header-case, conventional-status, and body-less-verb cases into datasets; batch 6 (6 link rule tests: `LinkBothOperationIdAndRef`, `LinkDuplicateName`, `LinkInvalidOperation`, `LinkInvalidParameter`, `LinkNeitherOperationIdNorRef`, `LinkParameterRequiredMissing`) folded `LinkBoth`'s only-id/only-ref/neither no-finding triple, `LinkNeither`'s only-id/only-ref pair, `LinkInvalidParameter`'s "no operationId" / "unknown target operationId" pair, and `LinkParameterRequiredMissing`'s id/slug path-param duplicate into datasets; batch 7 (12 schema/field rule tests: `SchemaAllofTypeConflict`, `SchemaConstraintsMissing`, `SchemaEnumEmpty`, `SchemaEnumTypeMismatch`, `SchemaExampleMissing`, `SchemaNullableViaDeprecatedKeyword`, `SchemaRequiredWithoutProperty`, `FieldConflictingType`, `FieldEnumMismatch`, `FieldInvalidFormat`, `FieldNoEffect`, `EnumValuesUndocumented`) folded enum / constraint / example / type-mismatch case families into datasets and dropped each test's local `LintContext` builder in favour of `emptyContext()`; batch 8 (10 tag/webhook/deprecated/header/path rule tests: `TagDuplicate`, `TagsNoDescription`, `TagUndeclaredAtRoot`, `WebhookNameDuplicate`, `DeprecatedNoReplacement`, `DeprecatedNoSunsetDate`, `HeaderInvalidName`, `PathParameterUndeclared`, `PathParameterUndefined`, `PathTrailingSlashInconsistent`) folded `TagsNoDescription`'s missing/empty/whitespace triple, `DeprecatedNoReplacement`'s five keyword variants, `DeprecatedNoSunsetDate`'s four no-date and two concrete-date cases, `TagUndeclaredAtRoot`'s no-operations / no-tags pair, `PathParameterUndeclared`'s three "all declared" shapes, and `PathTrailingSlashInconsistent`'s four "consistent paths" cases into datasets. ~15 rule tests still to audit.
- [~] Extract shared rule-test boilerplate — `OperationNodeFactory` extended with `makeOperation()`, `makeResponse()`, `makeRequestBody()`, `makeComponentSchema()`, `makeParameter()`, `makeQueryParameter()`, `makeHeader()`, `makeExample()`, `makeLink()`, `makeField()`, `makeWebhook()` (auto-links children when wrapped in `makeOperation()` / `makeResponse()`), and `emptyContext(declaredTags:, operationsByOperationId:, operations:, webhooks:, tagDescriptions:)` for api-level / cross-ref rules. Batches 1–8 of rule-test migrations done: batch 1 (10 description-style: SummaryMissing, OperationDescription, RequestBody/Response/Field/Schema/Parameter/Header/Webhook/InfoDescriptionMissing); batch 2 (6 operation-level: OperationIdMissing, OperationIdInvalidChars, OperationIdDuplicate, OperationIdNamingInconsistent, OperationSummaryEqualsDescription, OperationTagMissing); batch 3 (6 naming-inconsistent: ComponentName, FieldName, HeaderName, ParameterName, PathSegment, TagName); batch 4 (7 parameter/query-parameter: ParameterDuplicateName, ParameterExampleConflict, ParameterExampleMissing, ParameterPathMustBeRequired, ParameterQueryArrayNoExplode, ParameterQueryNoSchema, QueryParamDuplicate); batch 5 (9 response/request-body: ResponseDuplicateStatus, ResponseExampleMissing, ResponseNoError, ResponseNoSuccess, ResponseRedirectWithoutLocation, ResponseStatusUnconventional, RequestBodyExampleMissing, RequestBodyNoContent, RequestBodyOnGetOrDelete); batch 6 (6 link: LinkBothOperationIdAndRef, LinkDuplicateName, LinkInvalidOperation, LinkInvalidParameter, LinkNeitherOperationIdNorRef, LinkParameterRequiredMissing); batch 7 (12 schema/field: SchemaAllofTypeConflict, SchemaConstraintsMissing, SchemaEnumEmpty, SchemaEnumTypeMismatch, SchemaExampleMissing, SchemaNullableViaDeprecatedKeyword, SchemaRequiredWithoutProperty, FieldConflictingType, FieldEnumMismatch, FieldInvalidFormat, FieldNoEffect, EnumValuesUndocumented) dropped all local `make*Context()` / `make*Node()` helpers in favour of `emptyContext(payloadClasses:)` + `makeField(...)` / `makeComponentSchema(...)` / `makeOperation(descriptor: null)`; batch 8 (10 tag/webhook/deprecated/header/path: TagDuplicate, TagsNoDescription, TagUndeclaredAtRoot, WebhookNameDuplicate, DeprecatedNoReplacement, DeprecatedNoSunsetDate, HeaderInvalidName, PathParameterUndeclared, PathParameterUndefined, PathTrailingSlashInconsistent) extended `emptyContext()` with `operations:`, `webhooks:`, `tagDescriptions:` kwargs to drive api-level rules (`checkApi`), added `makeWebhook()` for `WebhookNameDuplicate`, and threaded `raw:` overrides for deprecated x-extension Bug 8 cases. ~15 rule tests still to migrate.
- [-] Replace hand-constructed `OA\…` object graphs (e.g. `SpecTreeBuilderTest:24–98`, `SpecTreeWalkerTest:34–117`) — **done** as part of §2 deletions
- [x] Trim `tests/Unit/Extractors/SecurityExtractorTest.php`: dropped 5 Mockery-based middleware-group tests; kept config-driven `security_schemes` / `security_default_scheme` coverage (220 L → 130 L)
- [x] Trim `tests/Unit/Core/Routing/RouteIntrospectorTest.php`: dropped 3 redundant cases; kept defensive "non-existent controller class" (108 L → 47 L). Removed orphaned `Fixtures/SimpleController.php`.
- [x] Trim `tests/Unit/Core/Routing/UriParameterResolverTest.php`: dropped 2 happy-path cases; kept defensive cases (119 L → 95 L)
- [x] Audit orphaned fixtures — none orphaned; see §9
- [ ] **Extract a shared `generateSpec()` helper.** Every feature test inlines `Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml())` (30+ sites). Move to `tests/Pest.php` or `tests/Support/`. Removes the YAML-import dance from every file and centralises the only point that knows about the serialise-then-parse roundtrip.
- [ ] **Ban `return null;` + `@phpstan-ignore-next-line` in signature-only controller fixtures.** Replace with `throw new \LogicException('Signature-only fixture; never invoked.')` — honest about types, doesn't lie to static analysis, doesn't risk a `TypeError` if the route ever gets hit. Already done in `tests/Feature/Plugins/SpatieData/DataResponseResolverTest.php`; audit the rest of `tests/Feature/` and `tests/Fixtures/` for the same anti-pattern.
- [ ] **Assert on `oneOf` schemas by membership, not index.** Tests that probe nullable-wrapped refs (`oneOf[0]['$ref']`) couple to `NullableSchema`'s emission order. Prefer `array_column($oneOf, '$ref')` + `toContain('#/components/schemas/X')` — same length, robust to future re-ordering. Pattern applied in `SchemaFromDataClassTest`; audit other tests touching `oneOf`/`anyOf`.

---

## 8. Suggested execution order

1. [x] Sweep orphaned fixtures — confirmed none orphaned at session start; cleaned up incidentally with §2/§7 deletions.
2. [-] Rule-level dump tests — reversed; see §2 / §9.
3. [x] Delete `SpecTreeBuilderTest`, `SpecTreeWalkerTest`, `RuleRegistryTest`, `SuppressionCollectorTest`, lint formatters/context/collector unit tests (§2).
4. [x] Delete clear-cut `Core/Routing` + `Core/Generator` + `Core/Registry` + `Extractors/SecurityExtractor` unit tests covered by feature flow (§2). Two `Core/Routing` tests trimmed not deleted; `SecurityExtractorTest` trimmed not deleted — see §7.
5. [x] Move `DataSyntheticPayloadBuilderTest` into `Unit/` (§4).
6. [x] Rewrite the four remaining resolver/extractor feature tests in §5 to assert on the generated document — `RequestBodyExtractorTest` (deleted as fully covered), `DataResponseResolverTest`, `DataClassRequestSchemaResolverTest`, `SchemaFromDataClassTest` (all three rewritten). 795 → 355 LOC; −17 tests / −40 assertions.
7. [x] Split or rename `Oapi031034Test`; rename the other batch-named files (§6).
8. [~] Refactor rule-test boilerplate (§7): extend `OperationNodeFactory` and consolidate duplicate `it(…)` cases within rule files. **Batches 1–8 done.** Batch 1: 10 description-style rule tests, ~600 → ~470 LOC, 49 tests / 138 assertions; baseline tests 1113 → 1109, assertions 2873 → 2914. Batch 2: 6 operation-level rule tests (OperationIdMissing, OperationIdInvalidChars, OperationIdDuplicate, OperationIdNamingInconsistent, OperationSummaryEqualsDescription, OperationTagMissing), ~870 → ~395 LOC; tests 1109 → 1107, assertions 2914 → 2917. Batch 3: 6 naming-inconsistent rule tests (ComponentName, FieldName, HeaderName, ParameterName, PathSegment, TagName), 887 → 588 LOC; tests 1107 → 1107, assertions 2917 → 2934. Batch 4: 7 parameter / query-parameter rule tests (ParameterDuplicateName, ParameterExampleConflict, ParameterExampleMissing, ParameterPathMustBeRequired, ParameterQueryArrayNoExplode, ParameterQueryNoSchema, QueryParamDuplicate), 1165 → 646 LOC; tests 1107 → 1105, assertions 2934 → 2931. Batch 5: 9 response / request-body rule tests (ResponseDuplicateStatus, ResponseExampleMissing, ResponseNoError, ResponseNoSuccess, ResponseRedirectWithoutLocation, ResponseStatusUnconventional, RequestBodyExampleMissing, RequestBodyNoContent, RequestBodyOnGetOrDelete), 1490 → 720 LOC; tests 1105 → 1105, assertions 2931 → 2933. Batch 6: 6 link rule tests (LinkBothOperationIdAndRef, LinkDuplicateName, LinkInvalidOperation, LinkInvalidParameter, LinkNeitherOperationIdNorRef, LinkParameterRequiredMissing), 1072 → 604 LOC; tests 1105 → 1104, assertions 2933 → 2934. Batch 7: 12 schema / field rule tests (SchemaAllofTypeConflict, SchemaConstraintsMissing, SchemaEnumEmpty, SchemaEnumTypeMismatch, SchemaExampleMissing, SchemaNullableViaDeprecatedKeyword, SchemaRequiredWithoutProperty, FieldConflictingType, FieldEnumMismatch, FieldInvalidFormat, FieldNoEffect, EnumValuesUndocumented), 1962 → 1244 LOC; tests 1104 → 1104, assertions 2934 → 2949. Batch 8: 10 tag / webhook / deprecated / header / path rule tests (TagDuplicate, TagsNoDescription, TagUndeclaredAtRoot, WebhookNameDuplicate, DeprecatedNoReplacement, DeprecatedNoSunsetDate, HeaderInvalidName, PathParameterUndeclared, PathParameterUndefined, PathTrailingSlashInconsistent), 1633 → 855 LOC; tests 1104 → 1103, assertions 2949 → 2965. ~15 rule tests still to migrate.

Run `composer test && composer lint && composer analyse` after each tranche.

### Session results so far

| Metric | Baseline | After cleanup | Δ |
|---|---|---|---|
| Tests passing | 1230 | 1103 | −127 |
| Assertions | 3172 | 2965 | −207 |
| Files deleted | — | 18 | |
| Files moved/split | — | 5 source files → 7 split products | |
| Files rewritten as feature tests | — | 3 | |
| Files trimmed | — | 3 | |
| Rule tests refactored onto factory | — | 66 | |

`composer test` / `composer lint` / `composer analyse` all green.

---

## 9. Incidental findings

Append issues spotted mid-cleanup here. Include the file + reason; promote to
its own section if a pattern emerges.

- **Linter rule unit tests are the only per-rule behaviour coverage.** `tests/Feature/Lint/LintCommandTest.php` only asserts on command behaviour (exit codes, suppression, config-driven flag handling); it does not assert that any specific rule emits any specific finding. This invalidated the original plan to mass-delete `tests/Unit/Lint/Rules/*`. Outcome: those tests stay, refactor them via §7 instead.
- **`tests/Unit/Core/Generator/CoreQueryParameterResolverTest.php` covered class-level → method-level `#[QueryParam]` override behaviour that is not exercised by any feature test.** Deleted with the file; coverage gap. Add a class-level `#[QueryParam]` fixture controller alongside `ResponseHeaderClassLevelTest`.
- **`tests/Unit/Core/Routing/UriParameterDescriptorTest.php` is a 60-line constructor-verbatim test on a public-named-args data class.** Low value but small; consider deleting along with §7 trimming pass.
- **`tests/Unit/Core/Generator/OpenApiGeneratorTest.php` is the right shape and should be the template for what feature tests look like** (define route → `app(OpenApiGenerator::class)->generate()` → assert on parsed YAML). Use as reference when rewriting tests under §5.
- **`tests/Feature/Lint/RuleCatalogCoverageTest.php` is a shape test only.** It verifies every registered rule has a unique non-empty `id`, a non-empty `description`, and a non-negative `level`, but does not pin specific id/level values. The per-rule `it('reports its id and level', …)` tests are the only contract guard for default severity and rule-id stability — keep them.
- **No truly orphaned fixtures.** `RemoteMediaFixtureController` (used by `FormRequestSchemaTest`), `ExampleFixtureController` (used by `RequestBodyExtractorTest`, `DataClassRequestSchemaResolverTest`), `StandardResponsesFixtureController` (used by 4 tests) are all still referenced. The explore agent's "orphaned" call was incorrect.
- **Dropped a self-admitting-useless test during the `Oapi031034Test.php` split.** The OAPI-034 case `description is not set when no case has PHPDoc` literally asserted on the positive path (description IS set) and admitted in its own comment that it couldn't actually test the negative path. Removed.
- **Pre-existing weak warnings (PhpStorm inspections) across test files:** unhandled `\PHPUnit\Framework\ExpectationFailedException`, unhandled `\Symfony\Component\Yaml\Exception\ParseException`, "closure can be declared static", "multiple expectations can be chained". Hundreds of instances; ignored because they exist project-wide and aren't real issues for test code. Could be silenced via `phpstorm.meta.php` or an `.editorconfig`-like IDE config if they become noisy.
- **`request.empty` is observable from feature flow via scoped binding swap.** `tests/Feature/Lint/RequestEmptyTest.php` swaps `FindingsCollector` on the container before calling `OpenApiGenerator::generate()` and inspects the collector after. This is the supported way to assert on extractor-emitted findings end-to-end; the unit-level extractor harness in `RequestBodyExtractorTest` was redundant with it.
- **Spatie paginator envelope coverage was resolver-only before this pass.** `PaginatorResponseTest` covers Laravel's `LengthAwarePaginatorContract` / `CursorPaginatorContract`, not Spatie's `PaginatedDataCollection<X>` / `CursorPaginatedDataCollection<X>`. The rewritten `DataResponseResolverTest` is now the only feature-level guard for those envelopes — keep it.
- **`OperationNodeFactory` is the right home for shared lint-test fixtures** — extending it (instead of adding a separate `LintNodeFactory`) keeps the test-support surface flat. `makeOperation()` auto-links `responses`, `requestBody`, `parameters`, `queryParameters` so callers don't manually `linkParent()` everywhere; pass `responses: []` when a rule should not see any (e.g. webhooks, certain failure modes).
- **Some rules need a non-empty `info`/`servers` on the raw spec.** `InfoDescriptionMissingTest` keeps a local `makeInfoContext()` helper because `emptyContext()` deliberately ships an empty `OA\OpenApi`. Other rules that touch `rawSpec` directly (`ServerInvalidUrl`, `ServerVariableUndeclared`, `SpecInvalid`, `ComponentOrphaned`, `RefBroken`) will follow the same per-file pattern — don't bloat `emptyContext()` with kitchen-sink knobs.
- **Field-scanning rules (`field.*`) take a `PayloadParameterScanner` constructor dep.** Batch-7 rules `FieldNoEffect`, `FieldConflictingType`, `FieldEnumMismatch`, `FieldInvalidFormat` all run through `forDescriptor()` to attach a real reflection-bearing `ActionDescriptor`. Their "no descriptor" negative case now uses `makeOperation(descriptor: null)` rather than an inlined `new OperationNode(...)` — keeps the assertion focused on the `descriptor === null` early return.
- **`makeField()` was added to `OperationNodeFactory` for batch 7.** Covers the same constructor surface as `FieldNode` with safe defaults; pair with `makeComponentSchema(fields: [...])` when a rule walks both schema- and field-level. No auto-linking — fields are pure data on their parent and don't need a `linkParent()` ceremony.
- **`emptyContext()` grew `operations:`, `webhooks:`, `tagDescriptions:` for batch 8.** Api-level rules (`checkApi`) read straight off the `ApiNode`, so the empty default isn't enough — these kwargs let `PathTrailingSlashInconsistent` see a populated paths list, `TagUndeclaredAtRoot` see operation tags, and `TagsNoDescription` see the tag-name → description map. The single context constructor stays the only api-level builder; no per-rule helpers.
- **`makeWebhook()` builds a `WebhookNode` whose operation defaults to `makeOperation(webhook: true, responses: [])`.** `WebhookNameDuplicate` aggregates per `checkWebhook` and emits on `finalize`, so it only needs the name; the wrapped operation is enough scaffolding for any future rule that walks `WebhookNode->operation`. No `linkParent()` ceremony — the api-level finalize path doesn't traverse upwards.
- **Deprecated x-extension cases (Bug 8) pass `raw:` to `makeOperation()`.** `DeprecatedNoReplacement` / `DeprecatedNoSunsetDate` read `$operation->raw->x` for the `x-replacement` / `x-sunset` extensions. The factory's auto-built `OA\Get` has `$x = null`, so the two extension cases construct a one-off `OA\Get` with `->x = [...]` and pass it via `raw:`. Two lines per test, no helper warranted.
