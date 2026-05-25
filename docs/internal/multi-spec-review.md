# Multi-spec implementation review

## Resolved

**1. Middleware passed to `SpecMatcher` without string-cast.** Fixed by routing through
`gatherMiddleware()` with an explicit `(string)` cast in both
`InclusionEvaluator::decide()` and `WhyCommand::printHeader()`. Subsumes #5 and #11.

**3. `InclusionEvaluator` summary/reason mismatch (was #10).** `InclusionDecision::summary`
now echoes the precise `TraceEntry::reason` for empty-match cases, so `--explain` output
and the trace agree.

**4. `SpecMatcher::$isDefault` parameter removed.** The default-spec catch-all semantics
moved up into `InclusionEvaluator` (where the spec name is known), keeping the matcher a
pure predicate over `prefix`/`middleware`/`namespace`.

**5. `SpecDefinition::servesOverHttp()` restored.** Added `servesOverHttp()`,
`isDefault()`, plus `specRouteName()` / `playgroundRouteName()` (instance) and
`specRouteNameFor()` / `playgroundRouteNameFor()` (static) helpers, replacing three
inline copies of the `name === 'default' ? 'openapi.spec' : 'openapi.spec.' . $name`
ternary across the service provider and `DocsController`.

**6. `DocsController` env check uses `app()->environment('local')`.** Consistent with the
rest of the codebase; previously the controller alone read raw `config('app.env')`.

**7. `LintRunner` constructor-injects `SpecRegistry` and
`OpenApiGenerationOrchestrator`** instead of mid-method `$container->make()` calls.

**8. `OpenApiGenerationOrchestrator::forgetScopedInstances()` no longer wipes an explicit
`FindingsCollector` override.** It preserves and restores the current binding across the
forget so extractor-emitted findings during `LintRunner` per-spec generation land in the
spec-local collector instead of the original `LoggingFindingsCollector`.

**9. Per-spec lint loop binds the spec-local collector before `generateOne()`.** Extractor
findings now drain into the same bucket as tree-walk findings. `walkSpec()` is now a pure
emit-into-supplied-bucket helper; draining is the caller's responsibility.

**10. `installArrayCollector()` no longer calls `forgetScopedInstances()`.** The
orchestrator handles cache invalidation; `instance()` alone is sufficient to pin the
collector. Avoids redundant resolution of scoped bindings.

**11. `SpecConfigOrphanedTest` mislabeled test renamed.** The "no findings" name
contradicted the `count=1` assertion; the test now reads "flags the default spec as
orphaned when the descriptor list is empty".

**12. Hide/Expose extraction in `InclusionEvaluator` (was #8).** Added
`ActionDescriptor::attributeInstances<T>(string): list<T>` which merges
action-then-controller `ReflectionAttribute`s and instantiates them. `InclusionEvaluator`
now uses it for both `Hide` and `Expose`, replacing two hand-rolled spread+`array_map`
expressions.

## Open

**2. `openapi:why` cannot explain globally-filtered routes.** The design says the trace
should show the global-filter stage for filtered-out routes, but `WhyCommand` iterates
`RouteIntrospector::discover()` which has already dropped them. Restoring this requires
exposing an unfiltered iterator on `RouteIntrospector`. Deferred—not blocking.

**3. `info` is shallow-merged.** `SpecRegistry::buildSpec` uses `array_merge` which
clobbers nested `contact` / `license` when a spec overrides one field. Design specifies
deep-merge. Deferred; needs a recursive merge with replacement semantics for non-assoc
arrays.

**6. URI default duplication between `SpecRegistry::buildSpec` and
`OpenApiServiceProvider::registerRoutes`.** Both still encode the `openapi-{name}.yaml`,
`docs/{name}`, and `false`→opt-out logic. The route mount path deliberately avoids
resolving `SpecRegistry` at boot (later providers can still override `info`/`tags`).
Extracting a tiny `SpecRouteConfig` helper would unify both paths without forcing eager
materialisation; not done in this pass.

**4. Global filters re-run per (route × spec).** `InclusionEvaluator` walks
`globalFilters` for every descriptor for every spec, but `RouteIntrospector::discover()`
has already dropped any route those filters reject. The filter stage in the evaluator is
trace material only and would become load-bearing only once an unfiltered descriptor
iterator exists (see #2). Accepting the redundancy explicitly; per-call cost is N
cheap `shouldSkip` calls.

**12. End-to-end multi-spec generation test.** Plan listed
`tests/Feature/MultiSpecGenerationTest.php`. `OpenApiGenerationOrchestratorTest`
exercises cross-contamination; HTTP route mounting is covered by `MultiSpecRoutesTest`
and `MultiSpecNamedRouteTest`. A full `openapi:generate` → two parseable YAML files test
would close the gap.
